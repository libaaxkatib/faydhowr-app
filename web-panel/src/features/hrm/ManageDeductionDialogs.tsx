import { useState, type ReactNode } from 'react';
import { useMutation } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { CompanyChoiceField } from '@/features/hrm/CompanyChoiceField';
import { useI18n } from '@/i18n/useI18n';
import {
  DEDUCTION_PATH,
  amountProblem,
  amountReady,
  centsText,
  changedFields,
  dateProblem,
  deductionErrorMessage,
  periodProblem,
  reasonReady,
  sanitizeAmountInput,
  toCents,
  waiveAmountProblem,
  type DeductionKind,
  type DeductionRecord,
} from '@/utils/deductions';
import type { CompanyChoice } from '@/utils/inputCompany';
import type { CorrectDeductionPayload } from '@/types/payroll';

interface DialogProps {
  kind: DeductionKind;
  employeeId: number;
  record: DeductionRecord;
  onClose: () => void;
  /** The change was saved. */
  onDone: () => void;
  /** Something went wrong: the screen reloads the record so the next try starts from what the server really holds. */
  onRefresh: () => void;
}

function amountOf(record: DeductionRecord): string {
  return 'deduction_amount' in record ? record.deduction_amount : record.amount;
}

function twoDecimals(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? number.toFixed(2) : value.trim();
}

function ErrorBox({ message }: { message: string | null }) {
  return message ? <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger" data-testid="deduction-dialog-error">{message}</div> : null;
}

function ReasonField({ id, label, value, onChange }: { id: string; label: string; value: string; onChange: (value: string) => void }) {
  const { t } = useI18n();

  return (
    <FormField label={label} htmlFor={id} required hint={t('option.deduction.reasonHint')} error={value.trim() !== '' && !reasonReady(value) ? t('validation.deduction.reasonShort') : undefined}>
      <textarea id={id} rows={2} maxLength={500} className={`${inputClasses} h-auto py-2`} value={value} onChange={(e) => onChange(e.target.value)} />
    </FormField>
  );
}

function Shell({ title, confirmLabel, ready, busy, tone, onClose, onConfirm, children }: { title: string; confirmLabel: string; ready: boolean; busy: boolean; tone?: 'danger'; onClose: () => void; onConfirm: () => void; children: ReactNode }) {
  const { t } = useI18n();

  return (
    <Modal
      isOpen
      onClose={busy ? () => undefined : onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={busy}>{t('action.cancel')}</Button>
          <Button size="sm" variant={tone === 'danger' ? 'danger' : 'primary'} isLoading={busy} disabled={!ready || busy} onClick={onConfirm} data-testid="deduction-dialog-confirm">
            {confirmLabel}
          </Button>
        </>
      }
    >
      {children}
    </Modal>
  );
}

/** Void a penalty / advance: mandatory reason, and the version the screen showed (a change by someone else in the meantime is refused). */
export function VoidDeductionDialog({ kind, employeeId, record, onClose, onDone, onRefresh }: DialogProps) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.voidDeduction(employeeId, DEDUCTION_PATH[kind], record.id, { reason: reason.trim(), expected_version: record.version ?? 0 }),
    onSuccess: onDone,
    onError: (err) => {
      onRefresh();
      setServerError(deductionErrorMessage(err, t('notify.deduction.voidFailed')));
    },
  });

  return (
    <Shell
      title={t(kind === 'penalty' ? 'confirm.deduction.voidPenaltyTitle' : 'confirm.deduction.voidAdvanceTitle')}
      confirmLabel={t('action.void')}
      tone="danger"
      ready={reasonReady(reason)}
      busy={mutation.isPending}
      onClose={onClose}
      onConfirm={() => { setServerError(null); mutation.mutate(); }}
    >
      <ErrorBox message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.deduction.voidBody')}</p>
      <p className="mb-3 text-sm font-medium text-ink">{amountOf(record)} {record.currency} · {record.reason}</p>
      <p className="mb-3 text-xs text-ink-muted">{t('warning.deduction.regenerateNote')}</p>
      <ReasonField id="void-deduction-reason" label={t('option.deduction.voidReason')} value={reason} onChange={setReason} />
    </Shell>
  );
}

/** Correct a penalty / advance: the old row is voided and a replacement is created. Only the fields that differ are sent. */
export function CorrectDeductionDialog({ kind, employeeId, record, choices, onClose, onDone, onRefresh }: DialogProps & { choices: CompanyChoice[] }) {
  const { t } = useI18n();
  const initialDate = 'penalty_date' in record ? record.penalty_date : record.advance_date;
  const initialCompany = record.client_company_id === undefined || record.client_company_id === null ? '' : String(record.client_company_id);
  const [amount, setAmount] = useState(twoDecimals(amountOf(record)));
  const [date, setDate] = useState(initialDate);
  const [payrollPeriod, setPayrollPeriod] = useState(record.payroll_period);
  const [reason, setReason] = useState(record.reason);
  const [notes, setNotes] = useState(record.notes ?? '');
  const [company, setCompany] = useState(initialCompany);
  const [correctionReason, setCorrectionReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const now = new Date();
  const prefix = kind === 'penalty' ? 'cpen' : 'cadv';

  const before = { amount: twoDecimals(amountOf(record)), date: initialDate, period: record.payroll_period, reason: record.reason, notes: record.notes ?? '', company: initialCompany };
  const after = { amount: amountReady(amount) ? twoDecimals(amount) : amount, date, period: payrollPeriod, reason: reason.trim(), notes: notes.trim(), company };
  const changed = changedFields(before, after);
  const hasChange = Object.keys(changed).length > 0;
  const amountMessage = amountProblem(amount);
  const periodMessage = periodProblem(payrollPeriod, now);
  const dateMessage = dateProblem(date, now);
  const ready = hasChange && amountReady(amount) && reason.trim() !== '' && reasonReady(correctionReason) && periodMessage === null && dateMessage === null;

  const payload = (): CorrectDeductionPayload => {
    const body: CorrectDeductionPayload = { correction_reason: correctionReason.trim(), expected_version: record.version ?? 0 };
    if (changed.amount !== undefined) {
      if (kind === 'penalty') body.deduction_amount = Number(amount);
      else body.amount = Number(amount);
    }
    if (changed.date !== undefined) {
      if (kind === 'penalty') body.penalty_date = date;
      else body.advance_date = date;
    }
    if (changed.period !== undefined) body.payroll_period = payrollPeriod;
    if (changed.reason !== undefined) body.reason = reason.trim();
    if (changed.notes !== undefined) body.notes = notes.trim() === '' ? null : notes.trim();
    if (changed.company !== undefined && company !== '') body.client_company_id = company === 'none' ? null : Number(company);

    return body;
  };

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.correctDeduction(employeeId, DEDUCTION_PATH[kind], record.id, payload()),
    onSuccess: onDone,
    onError: (err) => {
      onRefresh();
      setServerError(deductionErrorMessage(err, t('notify.deduction.correctFailed')));
    },
  });

  return (
    <Shell
      title={t(kind === 'penalty' ? 'confirm.deduction.correctPenaltyTitle' : 'confirm.deduction.correctAdvanceTitle')}
      confirmLabel={t('action.deduction.correct')}
      ready={ready}
      busy={mutation.isPending}
      onClose={onClose}
      onConfirm={() => { setServerError(null); mutation.mutate(); }}
    >
      <ErrorBox message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.deduction.correctBody')}</p>
      <CompanyChoiceField id={`${prefix}-company`} choices={choices} value={company} onChange={setCompany} />
      <div className="grid grid-cols-2 gap-3">
        <FormField
          label={t(kind === 'penalty' ? 'option.employeeDetail.fieldDeductionAmount' : 'option.employeeDetail.fieldAmount')}
          htmlFor={`${prefix}-amount`}
          required
          error={amountMessage ? t(amountMessage) : undefined}
        >
          <input id={`${prefix}-amount`} type="text" inputMode="decimal" autoComplete="off" className={inputClasses} value={amount} onChange={(e) => setAmount(sanitizeAmountInput(e.target.value))} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor={`${prefix}-currency`} required>
          <input id={`${prefix}-currency`} className={inputClasses} value="USD" readOnly disabled />
        </FormField>
      </div>
      <FormField label={t(kind === 'penalty' ? 'option.employeeDetail.fieldPenaltyDate' : 'option.employeeDetail.fieldAdvanceDate')} htmlFor={`${prefix}-date`} required error={dateMessage ? t(dateMessage) : undefined}>
        <input id={`${prefix}-date`} type="date" className={inputClasses} value={date} onChange={(e) => setDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldPayrollPeriod')} htmlFor={`${prefix}-period`} required error={periodMessage ? t(periodMessage) : undefined}>
        <input id={`${prefix}-period`} type="month" min="2020-01" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldReason')} htmlFor={`${prefix}-reason`} required>
        <input id={`${prefix}-reason`} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor={`${prefix}-notes`}>
        <textarea id={`${prefix}-notes`} rows={2} className={`${inputClasses} h-auto py-2`} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      {!hasChange && <p className="mb-3 text-xs text-ink-muted" data-testid="correct-nothing-changed">{t('warning.deduction.nothingChanged')}</p>}
      <p className="mb-3 text-xs text-ink-muted">{t('warning.deduction.regenerateNote')}</p>
      <ReasonField id={`${prefix}-correction-reason`} label={t('option.deduction.correctionReason')} value={correctionReason} onChange={setCorrectionReason} />
    </Shell>
  );
}

/** Waive (part of) what is still owed - Super Admin only. An empty amount waives everything that is still owed. */
export function WaiveDeductionDialog({ kind, employeeId, record, onClose, onDone, onRefresh }: DialogProps) {
  const { t } = useI18n();
  const owedCents = record.owed_cents ?? 0;
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const problem = waiveAmountProblem(amount, owedCents);

  const mutation = useMutation({
    mutationFn: () =>
      hrApi.employees.waiveDeduction(employeeId, DEDUCTION_PATH[kind], record.id, {
        reason: reason.trim(),
        expected_version: record.version ?? 0,
        ...(amount.trim() === '' || toCents(amount) === owedCents ? {} : { amount: Number(amount) }),
      }),
    onSuccess: onDone,
    onError: (err) => {
      onRefresh();
      setServerError(deductionErrorMessage(err, t('notify.deduction.waiveFailed')));
    },
  });

  return (
    <Shell
      title={t(kind === 'penalty' ? 'confirm.deduction.waivePenaltyTitle' : 'confirm.deduction.waiveAdvanceTitle')}
      confirmLabel={t('action.deduction.waiveConfirm')}
      tone="danger"
      ready={problem === null && reasonReady(reason)}
      busy={mutation.isPending}
      onClose={onClose}
      onConfirm={() => { setServerError(null); mutation.mutate(); }}
    >
      <ErrorBox message={serverError} />
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.deduction.waiveBody', { owed: centsText(owedCents) })}</p>
      <FormField label={t('option.deduction.waiveAmount')} htmlFor="waive-deduction-amount" error={problem ? t(problem) : undefined} hint={t('option.deduction.waiveAmountHint')}>
        <input id="waive-deduction-amount" type="text" inputMode="decimal" autoComplete="off" className={inputClasses} value={amount} onChange={(e) => setAmount(sanitizeAmountInput(e.target.value))} />
      </FormField>
      <ReasonField id="waive-deduction-reason" label={t('option.deduction.waiveReason')} value={reason} onChange={setReason} />
    </Shell>
  );
}
