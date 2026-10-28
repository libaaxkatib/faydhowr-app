import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { TABLE_SCROLL_CLASS } from '@/components/ui/TableScroll';
import type { MessageKey } from '@/i18n/messages';
import { formatCents } from '@/utils/companyEmployees';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { canManageDebt, correctionStatusLabel, canPayUnderpayment, canVoidPayment, canWriteOff, newIdempotencyKey, settlementToday, validateSettlementAmount, validateSettlementDate, validateWriteOffReason } from '@/utils/payrollCorrections';
import type { PayrollCorrection, PayrollCorrectionTransaction } from '@/types/payroll';

const KIND_LABEL: Record<PayrollCorrection['kind'], MessageKey> = { overpayment: 'option.payroll.kindOverpayment', underpayment: 'option.payroll.kindUnderpayment' };
const TONE: Record<PayrollCorrection['status'], 'warning' | 'success' | 'info'> = { open: 'warning', recovered: 'success', written_off: 'info', settled: 'success' };

/**
 * Ledger of the corrections a Super Admin made to approved/finalized payroll months. The closed month is never changed:
 *  - an OVERPAYMENT is deducted from later salaries; what is left (for example after the employee leaves) stays a debt that a Super Admin
 *    or an HR Manager manages by recording repayments;
 *  - an UNDERPAYMENT is owed to the employee and is paid only by a Super Admin, as its own transaction.
 * The server enforces every rule again; the buttons only mirror it.
 */
export function PayrollCorrectionsPanel() {
  const { t } = useI18n();
  const { admin } = useAuth();
  const { hasPermission } = useEffectivePermissions();
  const { data, isLoading, error } = useQuery({ queryKey: ['payroll-corrections'], queryFn: hrApi.payrollRuns.corrections });
  const [dialog, setDialog] = useState<{ correction: PayrollCorrection; mode: 'settle' | 'write_off' } | null>(null);
  const [voiding, setVoiding] = useState<{ correction: PayrollCorrection; transaction: PayrollCorrectionTransaction } | null>(null);

  if (isLoading || error || !data || (data.corrections.length === 0 && (data.payroll_payment_voids?.length ?? 0) === 0)) return null;

  const money = (cents: number) => formatCents(cents, data.totals.currency);
  const mayManage = hasPermission('hr.payroll.manage');
  const mayDo = (c: PayrollCorrection) => mayManage && c.outstanding_cents > 0 && (c.kind === 'underpayment' ? canPayUnderpayment(admin?.role) : canManageDebt(admin?.role));

  return (
    <Card className="mt-4" data-testid="payroll-corrections">
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">{t('option.payroll.correctionsTitle')}</h3>
      </CardHeader>
      <CardBody>
        <dl className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-8">
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalOverpaid')}</dt><dd className="font-medium">{money(data.totals.overpaid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalRecovered')}</dt><dd className="font-medium">{money(data.totals.recovered_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('status.payroll.correction.written_off')}</dt><dd className="font-medium">{money(data.totals.written_off_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalDebtOwed')}</dt><dd className="font-medium">{money(data.totals.outstanding_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalUnderpaid')}</dt><dd className="font-medium">{money(data.totals.underpaid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalUnderpaymentsPaid')}</dt><dd className="font-medium">{money(data.totals.underpayments_paid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.totalStillToPay')}</dt><dd className="font-medium">{money(data.totals.underpayments_outstanding_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">{t('option.payroll.paymentsVoided')}</dt><dd className="font-medium">{money(data.totals.payroll_payments_voided_cents ?? 0)}</dd></div>
        </dl>
        <p className="mb-3 text-xs text-ink-muted">
          {t('warning.payroll.correctionsExplain')}
        </p>
        <div role="region" tabIndex={0} aria-label={t('option.common.dataTable')} className={TABLE_SCROLL_CLASS}>
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-ink-muted">
              <tr>
                <th className="py-1.5 pr-3 font-medium">{t('option.label.employee')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.label.month')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colRecorded')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colShouldHaveBeen')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colDifference')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colSettled')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('status.payroll.correction.open')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.label.status')}</th>
                <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colReasonWho')}</th>
                <th className="py-1.5 font-medium">{t('option.payroll.colAction')}</th>
              </tr>
            </thead>
            <tbody>
              {data.corrections.map((c) => (
                <tr key={c.id} className="border-t border-border align-top">
                  <td className="py-2 pr-3">{c.employee_name} <span className="text-xs text-ink-muted">({c.employee_number})</span>{c.needs_follow_up && <span className="mt-1 block text-xs text-warning">{t('warning.payroll.followUpDebt')}</span>}</td>
                  <td className="py-2 pr-3">{c.period}</td>
                  <td className="py-2 pr-3">{money(c.recorded_net_cents)}</td>
                  <td className="py-2 pr-3">{money(c.corrected_net_cents)}</td>
                  <td className="py-2 pr-3">{t(KIND_LABEL[c.kind])} {money(Math.abs(c.difference_cents))}</td>
                  <td className="py-2 pr-3">
                    {c.kind === 'overpayment' ? t('option.payroll.settledBreakdown', { payroll: money(c.recovered_cents), repaid: money(c.repaid_cents), writtenOff: money(c.written_off_cents) }) : money(c.paid_cents)}
                    {c.recoveries.length > 0 && (
                      <span className="block text-xs text-ink-muted">{c.recoveries.map((r) => (r.status === 'scheduled' ? t('option.payroll.recoveryDraft', { period: r.period, amount: money(r.amount_cents) }) : `${r.period}: ${money(r.amount_cents)}`)).join('; ')}</span>
                    )}
                    {c.transactions.map((tx) => (
                      <span key={tx.id} className="block text-xs text-ink-muted">
                        {tx.transaction_date ? formatDate(tx.transaction_date) : ''}: {t(TX_LABEL[tx.type])} {money(tx.amount_cents)}
                        {tx.recorded_by ? ` ${t('option.payroll.txBy', { name: tx.recorded_by })}` : ''}{tx.note ? ` — ${tx.note}` : ''}{tx.reason ? ` — ${t('option.payroll.txReason', { reason: tx.reason })}` : ''}
                        {tx.balance_before_cents !== null && tx.balance_after_cents !== null ? ` ${t('option.payroll.txBalance', { from: money(tx.balance_before_cents), to: money(tx.balance_after_cents) })}` : ''}
                        {tx.voided ? ` ${tx.void_reason ? t('option.payroll.txVoidedReason', { reason: tx.void_reason }) : t('option.payroll.txVoided')}` : (
                          mayManage && (
                            (tx.type === 'debt_repayment' && canManageDebt(admin?.role)) || (tx.type === 'underpayment_payment' && canVoidPayment(admin?.role))
                          ) && (
                            <button type="button" className="ml-2 underline" onClick={() => setVoiding({ correction: c, transaction: tx })}>{t('action.payroll.voidLink')}</button>
                          )
                        )}
                      </span>
                    ))}
                  </td>
                  <td className="py-2 pr-3">{money(c.outstanding_cents)}</td>
                  <td className="py-2 pr-3"><StatusBadge status={c.status} label={correctionStatusLabel(c.status)} tone={TONE[c.status]} /></td>
                  <td className="py-2 pr-3 text-xs text-ink-muted">{c.reason}<br />{c.corrected_by ?? '—'}{c.corrected_at ? `, ${formatDate(c.corrected_at)}` : ''}</td>
                  <td className="py-2">
                    <div className="flex flex-col gap-1">
                      {mayDo(c) && (
                        <Button size="sm" variant="outline" onClick={() => setDialog({ correction: c, mode: 'settle' })}>
                          {c.kind === 'underpayment' ? t('action.payroll.recordPayment') : t('action.payroll.recordRepayment')}
                        </Button>
                      )}
                      {mayManage && c.kind === 'overpayment' && c.available_to_settle_cents > 0 && canWriteOff(admin?.role) && (
                        <Button size="sm" variant="outline" onClick={() => setDialog({ correction: c, mode: 'write_off' })}>{t('action.payroll.writeOff')}</Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {(data.payroll_payment_voids?.length ?? 0) > 0 && (
          <div className="mt-5" data-testid="payroll-payment-voids">
            <h4 className="mb-2 text-sm font-semibold text-ink">{t('option.payroll.paymentsVoided')}</h4>
            <p className="mb-2 text-xs text-ink-muted">{t('warning.payroll.voidsExplain')}</p>
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-ink-muted">
                <tr>
                  <th className="py-1.5 pr-3 font-medium">{t('option.label.employee')}</th>
                  <th className="py-1.5 pr-3 font-medium">{t('option.label.month')}</th>
                  <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colAmountVoided')}</th>
                  <th className="py-1.5 pr-3 font-medium">{t('option.payroll.colPaymentsOriginalReversal')}</th>
                  <th className="py-1.5 font-medium">{t('option.payroll.colReasonWho')}</th>
                </tr>
              </thead>
              <tbody>
                {data.payroll_payment_voids.map((v) => (
                  <tr key={v.id} className="border-t border-border align-top">
                    <td className="py-2 pr-3">{v.employee_name} <span className="text-xs text-ink-muted">({v.employee_number})</span></td>
                    <td className="py-2 pr-3">{v.period}</td>
                    <td className="py-2 pr-3">{money(v.amount_cents)}</td>
                    <td className="py-2 pr-3 text-xs text-ink-muted">#{v.original_payment_id} → #{v.reversal_payment_id}</td>
                    <td className="py-2 text-xs text-ink-muted">{v.reason}<br />{v.voided_by ?? '—'}{v.voided_at ? `, ${formatDate(v.voided_at)}` : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardBody>
      {dialog && <SettlementDialog correction={dialog.correction} mode={dialog.mode} onClose={() => setDialog(null)} />}
      {voiding && <VoidDialog correction={voiding.correction} transaction={voiding.transaction} onClose={() => setVoiding(null)} />}
    </Card>
  );
}

function useRefresh() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: ['payroll-corrections'] });
}

const TX_LABEL: Record<PayrollCorrectionTransaction['type'], MessageKey> = {
  underpayment_payment: 'option.payroll.txPaid',
  underpayment_payment_reversal: 'option.payroll.txReversed',
  debt_repayment: 'option.payroll.txRepaid',
  debt_write_off: 'option.payroll.txWrittenOff',
};

function SettlementDialog({ correction, mode, onClose }: { correction: PayrollCorrection; mode: 'settle' | 'write_off'; onClose: () => void }) {
  const { t } = useI18n();
  const refresh = useRefresh();
  const { show } = useToast();
  const isWriteOff = mode === 'write_off';
  const isPayment = correction.kind === 'underpayment';
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [date, setDate] = useState(settlementToday());
  const [note, setNote] = useState('');
  const [key] = useState(newIdempotencyKey);
  const [serverError, setServerError] = useState<string | null>(null);
  const money = (cents: number) => formatCents(cents, correction.currency);
  const problem = amount === '' ? null : validateSettlementAmount(amount, correction.available_to_settle_cents);

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.recordCorrectionTransaction(correction.id, { type: isWriteOff ? 'debt_write_off' : isPayment ? 'underpayment_payment' : 'debt_repayment', amount: Number(amount), transaction_date: date, note: note.trim() || null, ...(isWriteOff ? { reason: reason.trim() } : {}), idempotency_key: key }),
    onSuccess: () => { refresh(); show(isWriteOff ? t('notify.payroll.debtWrittenOff') : isPayment ? t('notify.payroll.paymentRecorded') : t('notify.payroll.repaymentRecorded')); onClose(); },
    onError: (e) => setServerError(friendlyErrorMessage(e, t('notify.payroll.nothingRecorded'))),
  });

  const ready = amount !== '' && validateSettlementAmount(amount, correction.available_to_settle_cents) === null && validateSettlementDate(date) === null && (!isWriteOff || validateWriteOffReason(reason) === null);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={isWriteOff ? t('confirm.payroll.writeOffTitle') : isPayment ? t('confirm.payroll.settlePaymentTitle') : t('confirm.payroll.settleRepaymentTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={!ready} onClick={() => { setServerError(null); mutation.mutate(); }}>
            {isWriteOff ? t('action.payroll.writeOff') : isPayment ? t('action.payroll.recordPayment') : t('action.payroll.recordRepayment')}
          </Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="mb-3 text-sm text-ink-muted">
        {t(isPayment ? 'confirm.payroll.settleOwedToEmployee' : 'confirm.payroll.settleOwedByEmployee', {
          name: correction.employee_name ?? '',
          number: correction.employee_number ?? '',
          period: correction.period,
          amount: money(correction.outstanding_cents),
        })}
        {correction.kind === 'overpayment' && correction.scheduled_cents > 0 ? t('confirm.payroll.settleScheduled', { amount: money(correction.scheduled_cents) }) : ''}.
        {` ${t(isWriteOff ? 'confirm.payroll.writeOffTail' : isPayment ? 'confirm.payroll.paymentTail' : 'confirm.payroll.repaymentTail')}`}
      </p>
      <FormField label={t('option.payroll.amountUsd')} htmlFor="settle-amount" required error={problem ?? undefined} hint={t('validation.payroll.amountAtMost', { amount: money(correction.available_to_settle_cents) })}>
        <input id="settle-amount" type="number" step="0.01" min="0" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
      </FormField>
      <FormField label={t('option.payroll.date')} htmlFor="settle-date" required error={validateSettlementDate(date) ?? undefined} hint={t('option.payroll.datesMogadishu')}>
        <input id="settle-date" type="date" max={settlementToday()} className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      {isWriteOff && (
        <FormField label={t('option.payroll.writeOffReason')} htmlFor="settle-reason" required error={reason !== '' ? validateWriteOffReason(reason) ?? undefined : undefined} hint={t('validation.payroll.writeOffReasonHint')}>
          <input id="settle-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      )}
      <FormField label={t('option.payroll.noteOptional')} htmlFor="settle-note">
        <input id="settle-note" maxLength={500} className={inputClasses} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function VoidDialog({ correction, transaction, onClose }: { correction: PayrollCorrection; transaction: PayrollCorrectionTransaction; onClose: () => void }) {
  const { t } = useI18n();
  const refresh = useRefresh();
  const { show } = useToast();
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.voidCorrectionTransaction(correction.id, transaction.id, reason.trim()),
    onSuccess: () => { refresh(); show(transaction.type === 'underpayment_payment' ? t('notify.payroll.correctionPaymentVoided') : t('notify.payroll.repaymentVoided')); onClose(); },
    onError: (e) => setServerError(friendlyErrorMessage(e, t('notify.payroll.nothingChanged'))),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={transaction.type === 'underpayment_payment' ? t('confirm.payroll.voidPaymentToEmployeeTitle') : t('confirm.payroll.voidRepaymentTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={reason.trim().length < 5} onClick={() => { setServerError(null); mutation.mutate(); }}>{transaction.type === 'underpayment_payment' ? t('action.voidPayment') : t('action.payroll.voidRepayment')}</Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="mb-3 text-sm text-ink-muted">
        {t(transaction.type === 'underpayment_payment' ? 'confirm.payroll.voidPaymentToEmployeeBody' : 'confirm.payroll.voidRepaymentBody', {
          amount: formatCents(transaction.amount_cents, correction.currency),
          name: correction.employee_name ?? '',
        })}
      </p>
      <FormField label={t('option.payroll.reason')} htmlFor="void-reason" required hint={t('validation.payroll.reasonHint5')}>
        <input id="void-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
