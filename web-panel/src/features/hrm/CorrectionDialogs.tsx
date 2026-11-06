import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { companyEmployeesApi } from '@/api/companyEmployees';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { facilityToday } from '@/utils/facility';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { invalidatePayrollDependents } from '@/utils/payrollQueries';
import { newIdempotencyKey } from '@/utils/payrollCorrections';
import { MIN_REOPEN_FINALIZED_REASON, sectionName } from '@/utils/payrollSections';
import { validatePartialAmount } from '@/utils/workplacePayments';
import type { CompanyEmployeeRow } from '@/types/companyEmployees';
import type { PayrollRunSection, ReconciliationMethod } from '@/types/payroll';

function money(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

function ErrorLine({ message }: { message: string | null }) {
  return message ? <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{message}</div> : null;
}

/**
 * Reopen ONE company's FINALIZED payroll for correction (owner decision 2026-11-04). The reason is mandatory (the server checks it again); every payment
 * already recorded stays, and the company is not approved again until it is paid in full. Who, when and why is recorded.
 */
export function ReopenFinalizedDialog({ section, monthLabel, onClose }: { section: PayrollRunSection; monthLabel: string; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const name = sectionName(section, t('option.payroll.sectionNoCompany'));

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.reopenSection(section.id, reason.trim()),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-run'] });
      void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-corrections'] });
      invalidatePayrollDependents(queryClient);
      show(t('notify.correction.reopened'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.payroll.reopenFailed'))),
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.correction.reopenFinalizedTitle', { name })}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={reason.trim().length < MIN_REOPEN_FINALIZED_REASON} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="reopen-finalized-confirm">
            {t('action.correction.reopenFinalized')}
          </Button>
        </>
      }
    >
      <ErrorLine message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.correction.reopenFinalizedBody', { name, month: monthLabel })}</p>
      <FormField label={t('option.correction.reason')} htmlFor="reopen-finalized-reason" required hint={t('validation.correction.reasonHint10')}>
        <textarea id="reopen-finalized-reason" rows={3} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/**
 * Settle the excess of an OVERPAID company: how it was settled and why. No payment is changed and no money moves; the company is approved again once the
 * whole excess is settled. One key per opening makes a retry or a double click a single entry.
 */
export function ReconcileDialog({ section, onClose }: { section: PayrollRunSection; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'full' | 'partial'>('full');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<ReconciliationMethod>('returned');
  const [reason, setReason] = useState('');
  // The settlement date is a Mogadishu calendar day (the server's check), not the day of the browser's own clock.
  const [date, setDate] = useState(() => facilityToday());
  const [key] = useState(newIdempotencyKey);
  const [serverError, setServerError] = useState<string | null>(null);
  const name = sectionName(section, t('option.payroll.sectionNoCompany'));
  const excessCents = section.unreconciled_overpaid_cents ?? 0;
  const problem = mode === 'partial' && amount !== '' ? validatePartialAmount(amount, excessCents) : null;
  const ready = reason.trim().length >= 5 && date !== '' && date <= facilityToday() && (mode === 'full' || (amount !== '' && problem === null));

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.reconcileCompany(section.id, { mode, ...(mode === 'partial' ? { amount } : {}), method, reason: reason.trim(), settled_on: date, idempotency_key: key }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-run'] });
      void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
      invalidatePayrollDependents(queryClient);
      show(t('notify.correction.reconciled'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.payroll.wpFailed'))),
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.correction.reconcileTitle', { name })}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={!ready} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="reconcile-confirm">
            {t('action.correction.reconcile')}
          </Button>
        </>
      }
    >
      <ErrorLine message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.correction.reconcileBody', { name, amount: money(section.unreconciled_overpaid ?? '0') })}</p>
      <FormField label={t('option.correction.settleAmount')} htmlFor="rc-mode">
        <select id="rc-mode" className={inputClasses} value={mode} onChange={(e) => setMode(e.target.value as 'full' | 'partial')}>
          <option value="full">{t('option.correction.settleAll')}</option>
          <option value="partial">{t('option.correction.settleAmount')}</option>
        </select>
      </FormField>
      {mode === 'partial' && (
        <FormField label={t('option.correction.settleAmount')} htmlFor="rc-amount" required error={problem ?? undefined}>
          <input id="rc-amount" type="text" inputMode="decimal" autoComplete="off" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
      )}
      <FormField label={t('option.correction.settleMethod')} htmlFor="rc-method">
        <select id="rc-method" className={inputClasses} value={method} onChange={(e) => setMethod(e.target.value as ReconciliationMethod)}>
          <option value="returned">{t('option.correction.methodReturned')}</option>
          <option value="carried_forward">{t('option.correction.methodCarried')}</option>
          <option value="other">{t('option.correction.methodOther')}</option>
        </select>
      </FormField>
      <FormField label={t('option.correction.settledOn')} htmlFor="rc-date" required hint={t('option.payroll.datesMogadishu')}>
        <input id="rc-date" type="date" max={facilityToday()} className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.correction.reason')} htmlFor="rc-reason" required hint={t('validation.correction.reasonHint5')}>
        <textarea id="rc-reason" rows={2} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/** Undo of Stop / End for ONE assignment: the same assignment is active again. A reason is required; the server refuses it if the position was refilled. */
export function ReopenAssignmentDialog({ row, onClose, companyId, companyName }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number; companyName: string }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.reopen(companyId, row!.assignment_id, { reason: reason.trim(), ...(correctionReason.trim() !== '' ? { correction_reason: correctionReason.trim() } : {}) }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['company-employees'] });
      void queryClient.invalidateQueries({ queryKey: ['employee'] });
      void queryClient.invalidateQueries({ queryKey: ['employees'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      show(t('notify.correction.assignmentReopened'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.company.somethingWentWrongNothingChanged'))),
  });

  // The dialog stays mounted on the company page: every opening (a different row) starts with empty fields and no old error.
  useEffect(() => {
    setReason('');
    setCorrectionReason('');
    setServerError(null);
  }, [row?.assignment_id]);

  if (row === null) return null;

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={t('confirm.correction.reopenAssignmentTitle', { name: row.full_name ?? '' })}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={reason.trim().length < 5} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="reopen-assignment-confirm">
            {t('action.correction.reopenAssignment')}
          </Button>
        </>
      }
    >
      <ErrorLine message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.correction.reopenAssignmentBody', { name: row.full_name ?? '', company: companyName, date: row.end_date ? formatDate(row.end_date) : '—' })}</p>
      <FormField label={t('option.correction.reason')} htmlFor="reopen-assignment-reason" required hint={t('validation.correction.reasonHint5')}>
        <input id="reopen-assignment-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.correction.payrollCorrectionReason')} htmlFor="reopen-assignment-correction">
        <input id="reopen-assignment-correction" maxLength={500} className={inputClasses} value={correctionReason} onChange={(e) => setCorrectionReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
