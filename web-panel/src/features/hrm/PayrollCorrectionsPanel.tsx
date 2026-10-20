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
import { formatCents } from '@/utils/companyEmployees';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { CORRECTION_STATUS_LABEL, canManageDebt, canPayUnderpayment, canVoidPayment, canWriteOff, newIdempotencyKey, validateSettlementAmount, validateWriteOffReason } from '@/utils/payrollCorrections';
import type { PayrollCorrection, PayrollCorrectionTransaction } from '@/types/payroll';

const KIND_LABEL: Record<PayrollCorrection['kind'], string> = { overpayment: 'Overpaid', underpayment: 'Underpaid' };
const TONE: Record<PayrollCorrection['status'], 'warning' | 'success' | 'info'> = { open: 'warning', recovered: 'success', written_off: 'info', settled: 'success' };

/**
 * Ledger of the corrections a Super Admin made to approved/finalized payroll months. The closed month is never changed:
 *  - an OVERPAYMENT is deducted from later salaries; what is left (for example after the employee leaves) stays a debt that a Super Admin
 *    or an HR Manager manages by recording repayments;
 *  - an UNDERPAYMENT is owed to the employee and is paid only by a Super Admin, as its own transaction.
 * The server enforces every rule again; the buttons only mirror it.
 */
export function PayrollCorrectionsPanel() {
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
        <h3 className="font-display text-sm font-bold text-ink">Corrections of closed payroll, overpayment recovery and underpayments</h3>
      </CardHeader>
      <CardBody>
        <dl className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-8">
          <div><dt className="text-xs text-ink-muted">Overpaid in closed months</dt><dd className="font-medium">{money(data.totals.overpaid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Recovered so far</dt><dd className="font-medium">{money(data.totals.recovered_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Written off</dt><dd className="font-medium">{money(data.totals.written_off_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Debt still owed</dt><dd className="font-medium">{money(data.totals.outstanding_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Underpaid in closed months</dt><dd className="font-medium">{money(data.totals.underpaid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Underpayments paid</dt><dd className="font-medium">{money(data.totals.underpayments_paid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Still to pay to employees</dt><dd className="font-medium">{money(data.totals.underpayments_outstanding_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Payroll payments voided</dt><dd className="font-medium">{money(data.totals.payroll_payments_voided_cents ?? 0)}</dd></div>
        </dl>
        <p className="mb-3 text-xs text-ink-muted">
          The closed month is never changed. An overpayment is taken from the employee's later salaries (never more than that month's pay, and from the final salary when they leave); what is left stays a debt that a Super Admin or an HR Manager manages here. A Super Admin can write off what cannot be recovered (with a reason; the debt and its history stay). An underpayment is owed to the employee and is paid only by a Super Admin, as a separate recorded payment, which only a Super Admin can void (a linked reversal is added; nothing is deleted).
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-ink-muted">
              <tr>
                <th className="py-1.5 pr-3 font-medium">Employee</th>
                <th className="py-1.5 pr-3 font-medium">Month</th>
                <th className="py-1.5 pr-3 font-medium">Recorded</th>
                <th className="py-1.5 pr-3 font-medium">Should have been</th>
                <th className="py-1.5 pr-3 font-medium">Difference</th>
                <th className="py-1.5 pr-3 font-medium">Settled so far</th>
                <th className="py-1.5 pr-3 font-medium">Still open</th>
                <th className="py-1.5 pr-3 font-medium">Status</th>
                <th className="py-1.5 pr-3 font-medium">Reason and who</th>
                <th className="py-1.5 font-medium">Action</th>
              </tr>
            </thead>
            <tbody>
              {data.corrections.map((c) => (
                <tr key={c.id} className="border-t border-border align-top">
                  <td className="py-2 pr-3">{c.employee_name} <span className="text-xs text-ink-muted">({c.employee_number})</span>{c.needs_follow_up && <span className="mt-1 block text-xs text-warning">No longer paid by payroll: follow up the debt here</span>}</td>
                  <td className="py-2 pr-3">{c.period}</td>
                  <td className="py-2 pr-3">{money(c.recorded_net_cents)}</td>
                  <td className="py-2 pr-3">{money(c.corrected_net_cents)}</td>
                  <td className="py-2 pr-3">{KIND_LABEL[c.kind]} {money(Math.abs(c.difference_cents))}</td>
                  <td className="py-2 pr-3">
                    {c.kind === 'overpayment' ? `${money(c.recovered_cents)} payroll + ${money(c.repaid_cents)} repaid + ${money(c.written_off_cents)} written off` : money(c.paid_cents)}
                    {c.recoveries.length > 0 && (
                      <span className="block text-xs text-ink-muted">{c.recoveries.map((r) => `${r.period}: ${money(r.amount_cents)}${r.status === 'scheduled' ? ' (draft)' : ''}`).join('; ')}</span>
                    )}
                    {c.transactions.map((t) => (
                      <span key={t.id} className="block text-xs text-ink-muted">
                        {t.transaction_date ? formatDate(t.transaction_date) : ''}: {TX_LABEL[t.type]} {money(t.amount_cents)}
                        {t.recorded_by ? ` by ${t.recorded_by}` : ''}{t.note ? ` — ${t.note}` : ''}{t.reason ? ` — reason: ${t.reason}` : ''}
                        {t.balance_before_cents !== null && t.balance_after_cents !== null ? ` (balance ${money(t.balance_before_cents)} → ${money(t.balance_after_cents)})` : ''}
                        {t.voided ? ` (voided${t.void_reason ? `: ${t.void_reason}` : ''})` : (
                          mayManage && (
                            (t.type === 'debt_repayment' && canManageDebt(admin?.role)) || (t.type === 'underpayment_payment' && canVoidPayment(admin?.role))
                          ) && (
                            <button type="button" className="ml-2 underline" onClick={() => setVoiding({ correction: c, transaction: t })}>void</button>
                          )
                        )}
                      </span>
                    ))}
                  </td>
                  <td className="py-2 pr-3">{money(c.outstanding_cents)}</td>
                  <td className="py-2 pr-3"><StatusBadge status={c.status} label={CORRECTION_STATUS_LABEL[c.status] ?? c.status} tone={TONE[c.status]} /></td>
                  <td className="py-2 pr-3 text-xs text-ink-muted">{c.reason}<br />{c.corrected_by ?? '—'}{c.corrected_at ? `, ${formatDate(c.corrected_at)}` : ''}</td>
                  <td className="py-2">
                    <div className="flex flex-col gap-1">
                      {mayDo(c) && (
                        <Button size="sm" variant="outline" onClick={() => setDialog({ correction: c, mode: 'settle' })}>
                          {c.kind === 'underpayment' ? 'Record payment' : 'Record repayment'}
                        </Button>
                      )}
                      {mayManage && c.kind === 'overpayment' && c.available_to_settle_cents > 0 && canWriteOff(admin?.role) && (
                        <Button size="sm" variant="outline" onClick={() => setDialog({ correction: c, mode: 'write_off' })}>Write off</Button>
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
            <h4 className="mb-2 text-sm font-semibold text-ink">Payroll payments voided</h4>
            <p className="mb-2 text-xs text-ink-muted">A payment marked paid was cancelled by a Super Admin or an HR Manager. The original payment stays in the payment ledger; a linked reversal of the same amount was added, and the payroll line can be paid again.</p>
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-ink-muted">
                <tr>
                  <th className="py-1.5 pr-3 font-medium">Employee</th>
                  <th className="py-1.5 pr-3 font-medium">Month</th>
                  <th className="py-1.5 pr-3 font-medium">Amount voided</th>
                  <th className="py-1.5 pr-3 font-medium">Payments (original → reversal)</th>
                  <th className="py-1.5 font-medium">Reason and who</th>
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

const TX_LABEL: Record<PayrollCorrectionTransaction['type'], string> = {
  underpayment_payment: 'paid',
  underpayment_payment_reversal: 'payment reversed',
  debt_repayment: 'repaid',
  debt_write_off: 'written off',
};

function SettlementDialog({ correction, mode, onClose }: { correction: PayrollCorrection; mode: 'settle' | 'write_off'; onClose: () => void }) {
  const refresh = useRefresh();
  const { show } = useToast();
  const isWriteOff = mode === 'write_off';
  const isPayment = correction.kind === 'underpayment';
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [date, setDate] = useState(localToday());
  const [note, setNote] = useState('');
  const [key] = useState(newIdempotencyKey);
  const [serverError, setServerError] = useState<string | null>(null);
  const money = (cents: number) => formatCents(cents, correction.currency);
  const problem = amount === '' ? null : validateSettlementAmount(amount, correction.available_to_settle_cents);

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.recordCorrectionTransaction(correction.id, { type: isWriteOff ? 'debt_write_off' : isPayment ? 'underpayment_payment' : 'debt_repayment', amount: Number(amount), transaction_date: date, note: note.trim() || null, ...(isWriteOff ? { reason: reason.trim() } : {}), idempotency_key: key }),
    onSuccess: () => { refresh(); show(isWriteOff ? 'Debt written off.' : isPayment ? 'Payment recorded.' : 'Repayment recorded.'); onClose(); },
    onError: (e) => setServerError(friendlyErrorMessage(e, 'Nothing was recorded.')),
  });

  const ready = amount !== '' && validateSettlementAmount(amount, correction.available_to_settle_cents) === null && date !== '' && (!isWriteOff || validateWriteOffReason(reason) === null);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={isWriteOff ? 'Write off part of the debt' : isPayment ? 'Record payment of an underpayment' : 'Record a repayment of the debt'}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={!ready} onClick={() => { setServerError(null); mutation.mutate(); }}>
            {isWriteOff ? 'Write off' : isPayment ? 'Record payment' : 'Record repayment'}
          </Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="mb-3 text-sm text-ink-muted">
        {correction.employee_name} ({correction.employee_number}), {correction.period}: {isPayment ? 'owed to the employee' : 'still owed by the employee'} {money(correction.outstanding_cents)}
        {correction.kind === 'overpayment' && correction.scheduled_cents > 0 ? `, of which ${money(correction.scheduled_cents)} is already scheduled in a draft payroll` : ''}.
        {isWriteOff ? ' A write-off forgives this part of the debt. The debt record and its history are kept, the write-off is audited with your reason, and it cannot be undone silently.' : isPayment ? ' This records a payment you have made; it is kept as its own transaction and in the payment ledger. The closed payroll month is not changed.' : ' This records money the employee gave back outside payroll; it is kept as its own transaction.'}
      </p>
      <FormField label="Amount (USD)" htmlFor="settle-amount" required error={problem ?? undefined} hint={`At most ${money(correction.available_to_settle_cents)}.`}>
        <input id="settle-amount" type="number" step="0.01" min="0" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
      </FormField>
      <FormField label="Date" htmlFor="settle-date" required>
        <input id="settle-date" type="date" max={localToday()} className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      {isWriteOff && (
        <FormField label="Reason for the write-off" htmlFor="settle-reason" required error={reason !== '' ? validateWriteOffReason(reason) ?? undefined : undefined} hint="Required, at least 5 characters. Kept in the audit history.">
          <input id="settle-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      )}
      <FormField label="Note (optional)" htmlFor="settle-note">
        <input id="settle-note" maxLength={500} className={inputClasses} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function VoidDialog({ correction, transaction, onClose }: { correction: PayrollCorrection; transaction: PayrollCorrectionTransaction; onClose: () => void }) {
  const refresh = useRefresh();
  const { show } = useToast();
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.voidCorrectionTransaction(correction.id, transaction.id, reason.trim()),
    onSuccess: () => { refresh(); show(transaction.type === 'underpayment_payment' ? 'Payment voided: a linked reversal was recorded.' : 'Repayment voided. The debt is restored.'); onClose(); },
    onError: (e) => setServerError(friendlyErrorMessage(e, 'Nothing was changed.')),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={transaction.type === 'underpayment_payment' ? 'Void a payment to the employee' : 'Void a repayment'}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={reason.trim().length < 5} onClick={() => { setServerError(null); mutation.mutate(); }}>{transaction.type === 'underpayment_payment' ? 'Void payment' : 'Void repayment'}</Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="mb-3 text-sm text-ink-muted">
        {transaction.type === 'underpayment_payment'
          ? `Voiding keeps the payment and adds a linked reversal of ${formatCents(transaction.amount_cents, correction.currency)} to the payment ledger; the amount is owed to ${correction.employee_name} again. Nothing is deleted.`
          : `Voiding keeps the repayment in the history and puts ${formatCents(transaction.amount_cents, correction.currency)} back on the debt of ${correction.employee_name}.`}
      </p>
      <FormField label="Reason" htmlFor="void-reason" required hint="At least 5 characters. Kept in the audit history.">
        <input id="void-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
