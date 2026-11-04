import { Fragment, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { TABLE_SCROLL_CLASS } from '@/components/ui/TableScroll';
import { useToast } from '@/components/ui/useToast';
import { monthYearLabel } from '@/i18n/dates';
import { getActiveLanguage } from '@/i18n/active';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { WORKPLACE_STATUS, validateVoidReason, waitsForCompanyFinalization, workplaceName } from '@/utils/workplacePayments';
import type { PayrollRun, WorkplacePaymentRecord, WorkplacePayrollSummary } from '@/types/payroll';

function money(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

type Voiding = { workplace: WorkplacePayrollSummary; payment: WorkplacePaymentRecord };

/**
 * Payments by workplace for the month (read-only, plus the void of a mistaken payment): each workplace's total payroll, total paid and outstanding balance
 * with its own status (UNPAID / PARTIAL / PAID) and its payment history. Payments are RECORDED from the company list above ("Approve Paid" / "Approve
 * Partial": owner decision 2026-11-04), which also approves a company when it is paid in full. The server calculates every figure; this panel only shows
 * them. Paying one workplace never changes another, and the payroll lines are not touched.
 */
export function WorkplacePaymentsPanel({ run, canVoid }: { run: PayrollRun; canVoid: boolean }) {
  const { t } = useI18n();
  const [voiding, setVoiding] = useState<Voiding | null>(null);
  const [open, setOpen] = useState<Set<string>>(new Set());

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: ['workplace-payments', run.id],
    queryFn: () => hrApi.payrollRuns.workplacePayments(run.id),
  });

  const month = monthYearLabel(getActiveLanguage(), Number(run.period.slice(0, 4)), Number(run.period.slice(5, 7)) - 1);
  const toggle = (key: string) =>
    setOpen((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);

      return next;
    });

  return (
    <section className="mb-5 rounded-sm border border-border" data-testid="workplace-payments">
      <div className="border-b border-border px-4 py-3">
        <h4 className="font-display text-sm font-bold text-ink">{t('option.payroll.wpTitle')}</h4>
        <p className="mt-0.5 text-xs text-ink-muted">{t('option.payroll.wpHelp')}</p>
        {data && !data.can_record && <p className="mt-2 rounded-sm bg-warning-soft px-3 py-2 text-xs text-ink">{t('warning.payroll.wpNotFinalized')}</p>}
      </div>

      {isLoading && <LoadingState label={t('option.payroll.loadingLines')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="px-4 py-3">
          <div className="mb-3 grid grid-cols-3 gap-3">
            {([['option.payroll.wpTotal', data.totals.total], ['option.payroll.wpPaid', data.totals.paid], ['option.payroll.wpOutstanding', data.totals.outstanding]] as const).map(([label, value]) => (
              <div key={label} className="rounded-sm border border-border px-3 py-2">
                <p className="text-xs text-ink-faint">{t(label)}</p>
                <p className="font-display text-base font-bold text-ink" data-testid={`wp-total-${label.split('.').pop()}`}>{money(value)}</p>
              </div>
            ))}
          </div>

          {data.workplaces.length === 0 ? (
            <p className="py-4 text-center text-sm text-ink-muted">{t('option.payroll.wpNone')}</p>
          ) : (
            <div className={TABLE_SCROLL_CLASS}>
              <table className="w-full min-w-[56rem] text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs text-ink-muted">
                    <th className="py-2 pr-3 font-medium">{t('option.payroll.wpWorkplace')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('option.label.employees')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpTotal')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpPaid')}</th>
                    <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpOutstanding')}</th>
                    <th className="px-3 py-2 font-medium">{t('option.label.status')}</th>
                    <th className="py-2 pl-3 text-right font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {data.workplaces.map((workplace) => {
                    const status = WORKPLACE_STATUS[workplace.status];
                    const expanded = open.has(workplace.key);
                    const waiting = waitsForCompanyFinalization(workplace);

                    return (
                      <Fragment key={workplace.key}>
                        <tr className="border-b border-border align-top" data-testid={`wp-row-${workplace.key}`}>
                          <td className="py-2 pr-3">
                            <p className="font-medium text-ink">{workplace.payable ? workplaceName(workplace) : t('option.payroll.wpUnassigned')}</p>
                          </td>
                          <td className="px-3 py-2 text-right text-ink">{workplace.employee_count}</td>
                          <td className="px-3 py-2 text-right text-ink">{money(workplace.total)}</td>
                          <td className="px-3 py-2 text-right text-ink" data-testid="wp-paid">{money(workplace.paid)}</td>
                          <td className="px-3 py-2 text-right font-semibold text-ink" data-testid="wp-outstanding">{money(workplace.outstanding)}</td>
                          <td className="px-3 py-2">
                            {workplace.payable && <StatusBadge status={workplace.status} label={t(status.labelKey)} tone={status.tone} />}
                          </td>
                          <td className="py-2 pl-3">
                            <div className="flex flex-wrap justify-end gap-2">
                              {waiting && <span className="self-center text-xs text-ink-muted" data-testid="wp-company-not-finalized">{t('warning.payroll.wpCompanyNotFinalized')}</span>}
                              {workplace.payable && (
                                <Button size="sm" variant="ghost" aria-expanded={expanded} onClick={() => toggle(workplace.key)}>
                                  {t('option.payroll.wpHistory')} ({workplace.payments.length})
                                </Button>
                              )}
                            </div>
                          </td>
                        </tr>
                        {expanded && (
                          <tr className="border-b border-border bg-surface-muted" data-testid={`wp-history-${workplace.key}`}>
                            <td colSpan={7} className="px-3 py-3">
                              {workplace.payments.length === 0 ? (
                                <p className="text-xs text-ink-muted">{t('option.payroll.wpNoPayments')}</p>
                              ) : (
                                <table className="w-full text-xs">
                                  <thead>
                                    <tr className="text-left text-ink-muted">
                                      <th className="py-1 pr-3 font-medium">{t('option.payroll.date')}</th>
                                      <th className="px-3 py-1 text-right font-medium">{t('option.payroll.amountUsd')}</th>
                                      <th className="px-3 py-1 font-medium">{t('option.payroll.wpRecordedBy')}</th>
                                      <th className="px-3 py-1 font-medium">{t('option.payroll.wpNote')}</th>
                                      <th className="py-1 pl-3" />
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {workplace.payments.map((payment) => (
                                      <tr key={payment.id} className={payment.voided ? 'text-ink-faint line-through' : 'text-ink'}>
                                        <td className="py-1 pr-3">{formatDate(payment.payment_date)}</td>
                                        <td className="px-3 py-1 text-right">{money(payment.amount)}</td>
                                        <td className="px-3 py-1">{payment.recorded_by ?? '—'}</td>
                                        <td className="px-3 py-1">{payment.note ?? '—'}</td>
                                        <td className="py-1 pl-3 text-right no-underline">
                                          {payment.voided ? (
                                            <span className="text-ink-muted no-underline">{t('option.payroll.wpVoidedMark', { reason: payment.void_reason ?? '' })}</span>
                                          ) : (
                                            canVoid && workplace.can_record && (
                                              <Button size="sm" variant="ghost" onClick={() => setVoiding({ workplace, payment })}>{t('action.payroll.wpVoid')}</Button>
                                            )
                                          )}
                                        </td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              )}
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {voiding && <VoidDialog month={month} voiding={voiding} onClose={() => setVoiding(null)} />}
    </section>
  );
}

function useRefreshWorkplaces() {
  const queryClient = useQueryClient();

  return () => queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
}

/** Voids a workplace payment recorded by mistake: a reason is required, the payment stays in the history and stops counting. */
function VoidDialog({ month, voiding, onClose }: { month: string; voiding: Voiding; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const refresh = useRefreshWorkplaces();
  const { workplace, payment } = voiding;
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.voidWorkplacePayment(payment.id, reason.trim()),
    onSuccess: () => {
      void refresh();
      show(t('notify.payroll.wpVoided'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.payroll.wpFailed'))),
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.payroll.wpVoidTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" variant="danger" isLoading={mutation.isPending} disabled={validateVoidReason(reason) !== null} onClick={() => { setServerError(null); mutation.mutate(); }}>
            {t('action.payroll.wpVoid')}
          </Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}
      <p className="mb-3 text-sm text-ink-muted">
        {t('confirm.payroll.wpVoidBody', { amount: money(payment.amount), date: formatDate(payment.payment_date), name: `${workplaceName(workplace)} (${month})` })}
      </p>
      <FormField label={t('option.payroll.wpVoidReason')} htmlFor="wp-void-reason" required error={reason !== '' ? validateVoidReason(reason) ?? undefined : undefined}>
        <input id="wp-void-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
