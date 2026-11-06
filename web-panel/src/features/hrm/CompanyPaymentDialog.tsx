import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency } from '@/utils/formatters';
import { facilityToday } from '@/utils/facility';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { newIdempotencyKey } from '@/utils/payrollCorrections';
import { invalidatePayrollDependents } from '@/utils/payrollQueries';
import { MAX_PAYMENT_REFERENCE, companyPaymentPayload, sectionName } from '@/utils/payrollSections';
import { validatePartialAmount } from '@/utils/workplacePayments';
import type { PayrollRunSection } from '@/types/payroll';

function money(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

/**
 * "Approve Paid" (the whole outstanding balance, no amount typed) or "Approve Partial" (the amount paid now) for ONE finalized company. The dialog shows the
 * company's total payroll, what has been paid and what is outstanding; the server calculates and re-checks every figure. A partial payment keeps the company
 * Partially paid (NOT approved); a payment that clears the balance approves it by itself. Payroll amounts are never touched.
 */
export function CompanyPaymentDialog({ section, mode, monthLabel, onClose }: { section: PayrollRunSection; mode: 'full' | 'partial'; monthLabel: string; onClose: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [amount, setAmount] = useState('');
  // The payment date is a Mogadishu calendar day (the server's check), not the day of the browser's own clock.
  const [date, setDate] = useState(() => facilityToday());
  const [note, setNote] = useState('');
  const [reference, setReference] = useState('');
  // One key per dialog opening: a retry or a double click of the same submission is recorded once.
  const [key] = useState(newIdempotencyKey);
  const [serverError, setServerError] = useState<string | null>(null);
  const name = sectionName(section, t('option.payroll.sectionNoCompany'));
  const problem = mode === 'partial' && amount !== '' ? validatePartialAmount(amount, section.outstanding_cents) : null;
  const ready = date !== '' && date <= facilityToday() && (mode === 'full' || (amount !== '' && validatePartialAmount(amount, section.outstanding_cents) === null));

  const mutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.payCompany(section.id, companyPaymentPayload(mode, amount, date, note, key, reference)),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
      void queryClient.invalidateQueries({ queryKey: ['payroll-run'] });
      void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
      invalidatePayrollDependents(queryClient);
      show(t(result.company?.approved ? 'notify.payroll.apApproved' : 'notify.payroll.apPartial'));
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, t('notify.payroll.wpFailed'))),
  });

  return (
    <Modal
      isOpen
      onClose={mutation.isPending ? () => undefined : onClose}
      title={mode === 'full' ? t('confirm.payroll.apPaidTitle', { name }) : t('confirm.payroll.apPartialTitle', { name })}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={mutation.isPending} disabled={!ready} onClick={() => { setServerError(null); mutation.mutate(); }} data-testid="company-payment-confirm">
            {mode === 'full' ? t('action.payroll.apRecordPaid') : t('action.payroll.apRecordPartial')}
          </Button>
        </>
      }
    >
      {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{serverError}</div>}

      <div className="mb-3 grid grid-cols-3 gap-2" data-testid="company-payment-figures">
        {([['option.payroll.wpTotal', section.payable], ['option.payroll.wpPaid', section.paid], ['option.payroll.wpOutstanding', section.outstanding]] as const).map(([label, value]) => (
          <div key={label} className="rounded-sm border border-border px-2.5 py-1.5">
            <p className="text-xs text-ink-faint">{t(label)}</p>
            <p className="font-display text-sm font-bold text-ink">{money(value)}</p>
          </div>
        ))}
      </div>

      <p className="mb-3 text-sm text-ink-muted">
        {mode === 'full' ? t('confirm.payroll.apPaidBody', { amount: money(section.outstanding), name, month: monthLabel }) : t('confirm.payroll.apPartialBody')}
      </p>
      {mode === 'partial' && (
        <FormField label={t('option.payroll.apPayNow')} htmlFor="ap-amount" required error={problem ?? undefined}>
          <input id="ap-amount" type="text" inputMode="decimal" autoComplete="off" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
      )}
      <FormField label={t('option.payroll.paymentDate')} htmlFor="ap-date" required hint={t('option.payroll.datesMogadishu')}>
        <input id="ap-date" type="date" max={facilityToday()} className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.payroll.paymentReference')} htmlFor="ap-reference">
        <input
          id="ap-reference"
          maxLength={MAX_PAYMENT_REFERENCE}
          autoComplete="off"
          placeholder={t('option.payroll.paymentReferencePlaceholder')}
          className={inputClasses}
          value={reference}
          onChange={(e) => setReference(e.target.value)}
        />
      </FormField>
      <FormField label={t('option.payroll.noteOptional')} htmlFor="ap-note">
        <input id="ap-note" maxLength={500} className={inputClasses} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}
