import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { CompanyChoiceField } from '@/features/hrm/CompanyChoiceField';
import { useI18n } from '@/i18n/useI18n';
import { EARLIEST_DATE, MAX_NOTES_LENGTH, MAX_REASON_LENGTH, amountProblem, amountReady, dateProblem, deductionErrorMessage, isDuplicateRecord, newIdempotencyKey, periodProblem, reasonProblem, reasonReady, registerPayload, sanitizeAmountInput, type DeductionKind } from '@/utils/deductions';
import { facilityToday } from '@/utils/facility';
import { localMonth } from '@/utils/hrHelpers';
import { companyMissing, companyPayload, type CompanyChoice } from '@/utils/inputCompany';

/**
 * Registers ONE penalty or one-month salary advance. The dialog is opened fresh each time, so it makes one idempotency key per opening and sends the same key
 * on every retry of this submission: a double click or a lost answer never records a second row. If the server finds an identical record it answers 409
 * DUPLICATE_RECORD and the person is asked "Record it again anyway?"; only a yes sends the request again with `confirm_duplicate`. The amount is in USD
 * with at most two decimals; whether the employee may be charged for that month is decided by the server.
 */
export function RecordDeductionDialog({ kind, employeeId, choices, title, onClose, onRecorded }: { kind: DeductionKind; employeeId: number; choices: CompanyChoice[]; title: string; onClose: () => void; onRecorded: () => void }) {
  const { t } = useI18n();
  const [key] = useState(newIdempotencyKey);
  // Today is the Mogadishu calendar day (the server's), not the browser's own clock.
  const [date, setDate] = useState(() => facilityToday());
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  // Owner decision UG-7: a NEW penalty or advance opens on the CURRENT month (a form default only; the payroll screens keep the previous month).
  const [payrollPeriod, setPayrollPeriod] = useState(localMonth());
  const [notes, setNotes] = useState('');
  const [company, setCompany] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const [confirmingDuplicate, setConfirmingDuplicate] = useState(false);
  const prefix = kind === 'penalty' ? 'pen' : 'adv';
  const now = new Date();

  const mutation = useMutation({
    mutationFn: (confirmDuplicate: boolean) => {
      const form = { date, reason, amount, payrollPeriod, notes, company: companyPayload(choices, company) };

      return kind === 'penalty'
        ? hrApi.employees.addPenalty(employeeId, registerPayload('penalty', form, key, confirmDuplicate))
        : hrApi.employees.addAdvance(employeeId, registerPayload('advance', form, key, confirmDuplicate));
    },
    onSuccess: () => {
      setConfirmingDuplicate(false);
      onRecorded();
    },
    onError: (err) => {
      if (isDuplicateRecord(err)) {
        setConfirmingDuplicate(true);
        return;
      }
      setConfirmingDuplicate(false);
      setServerError(deductionErrorMessage(err, t('notify.deduction.recordFailed')));
    },
  });

  const amountMessage = amountProblem(amount);
  const periodMessage = periodProblem(payrollPeriod, now);
  const dateMessage = dateProblem(date, now);
  const reasonMessage = reasonProblem(reason);
  const ready = amountReady(amount) && reasonReady(reason) && periodMessage === null && dateMessage === null && !companyMissing(choices, company);
  const busy = mutation.isPending;
  const submit = (confirmDuplicate: boolean) => {
    setServerError(null);
    mutation.mutate(confirmDuplicate);
  };

  const dateField = (
    <FormField label={t(kind === 'penalty' ? 'option.employeeDetail.fieldPenaltyDate' : 'option.employeeDetail.fieldAdvanceDate')} htmlFor={`${prefix}-date`} required error={dateMessage ? t(dateMessage) : undefined}>
      <input id={`${prefix}-date`} type="date" min={EARLIEST_DATE} max={facilityToday()} className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
    </FormField>
  );
  const reasonField = (
    <FormField label={t('option.employeeDetail.fieldReason')} htmlFor={`${prefix}-reason`} required hint={t('option.deduction.reasonHint')} error={reasonMessage ? t(reasonMessage) : undefined}>
      <input id={`${prefix}-reason`} maxLength={MAX_REASON_LENGTH} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
    </FormField>
  );

  return (
    <>
      <Modal
        isOpen
        onClose={busy ? () => undefined : onClose}
        title={title}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={onClose} disabled={busy}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={busy && !confirmingDuplicate} disabled={!ready || busy} onClick={() => submit(false)} data-testid="record-deduction-confirm">
              {t('action.record')}
            </Button>
          </>
        }
      >
        {serverError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger" data-testid="record-deduction-error">{serverError}</div>}
        <CompanyChoiceField id={`${prefix}-company`} choices={choices} value={company} onChange={setCompany} />
        {dateField}
        {kind === 'penalty' && reasonField}
        <div className="grid grid-cols-2 gap-3">
          <FormField
            label={t(kind === 'penalty' ? 'option.employeeDetail.fieldDeductionAmount' : 'option.employeeDetail.fieldAmount')}
            htmlFor={`${prefix}-amount`}
            required
            error={amountMessage ? t(amountMessage) : undefined}
          >
            <input
              id={`${prefix}-amount`}
              type="text"
              inputMode="decimal"
              autoComplete="off"
              placeholder="0.00"
              className={inputClasses}
              value={amount}
              onChange={(e) => setAmount(sanitizeAmountInput(e.target.value))}
            />
          </FormField>
          <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor={`${prefix}-currency`} required>
            <input id={`${prefix}-currency`} className={inputClasses} value="USD" readOnly disabled />
          </FormField>
        </div>
        <p className="-mt-2 mb-4 text-xs text-ink-faint">{t('option.deduction.currencyHint')}</p>
        <FormField label={t('option.employeeDetail.fieldPayrollPeriod')} htmlFor={`${prefix}-period`} required error={periodMessage ? t(periodMessage) : undefined}>
          <input id={`${prefix}-period`} type="month" min="2020-01" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
        </FormField>
        {kind === 'advance' && reasonField}
        <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor={`${prefix}-notes`}>
          <textarea id={`${prefix}-notes`} rows={2} maxLength={MAX_NOTES_LENGTH} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </Modal>

      {confirmingDuplicate && (
        <Modal
          isOpen
          onClose={busy ? () => undefined : () => setConfirmingDuplicate(false)}
          title={t('confirm.deduction.duplicateTitle')}
          size="sm"
          footer={
            <>
              <Button variant="outline" size="sm" onClick={() => setConfirmingDuplicate(false)} disabled={busy}>{t('action.cancel')}</Button>
              <Button size="sm" isLoading={busy} onClick={() => submit(true)} data-testid="record-deduction-again">
                {t('action.deduction.recordAgain')}
              </Button>
            </>
          }
        >
          <p className="text-sm text-ink" data-testid="record-deduction-duplicate">{t('confirm.deduction.duplicateBody')}</p>
        </Modal>
      )}
    </>
  );
}
