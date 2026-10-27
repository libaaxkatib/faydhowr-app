import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey, Translate } from '@/i18n/messages';
import { formatDate } from '@/utils/formatters';
import { EMPTY_SUCCESSOR, effectiveStart, needsSuccessor, toSuccessorPayload, validateSuccessor, type SuccessorDraft } from '@/utils/successor';
import { SuccessorPicker } from '@/features/hrm/companyEmployees/SuccessorPicker';
import { additionalCompanyQuestion, friendlyErrorMessage } from '@/utils/hrHelpers';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import {
  ASSIGNMENT_TYPE_OPTIONS,
  assignmentShiftEdit,
  assignmentTypeLabel,
  dayBefore,
  isCorrectionReasonRequired,
  isTemporaryWorker,
  SHIFT_OPTIONS,
  startDateChangeWarning,
  validateAssignForm,
  validateReplaceForm,
  validateReturnForm,
  validateStopForm,
  type AssignmentTypeValue,
  type ReturnFormErrors,
  type ShiftValue,
} from '@/utils/companyEmployees';
import { EmployeePicker } from '@/features/hrm/companyEmployees/EmployeePicker';
import { CreatePositionDialog } from '@/features/hrm/QuickCreateDialogs';
import type { CompanyEmployeeCandidate, CompanyEmployeeRow } from '@/types/companyEmployees';

const today = () => new Date().toISOString().slice(0, 10);

function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">
      {message}
    </div>
  );
}

function useActionState(companyId: number) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [serverMessage, setServerMessage] = useState<string | null>(null);
  const [serverFields, setServerFields] = useState<Record<string, string[]>>({});
  const [needsCorrection, setNeedsCorrection] = useState(false);

  const failed = (error: unknown) => {
    if (isCorrectionReasonRequired(error)) setNeedsCorrection(true);
    setServerMessage(friendlyErrorMessage(error, t('notify.company.somethingWentWrongNothingChanged')));
    setServerFields(error instanceof ApiClientError && error.isValidation ? (error.fieldErrors ?? {}) : {});
  };
  const succeeded = (message: string, close: () => void) => {
    queryClient.invalidateQueries({ queryKey: ['company-employees', companyId] });
    queryClient.invalidateQueries({ queryKey: ['company-employee-history', companyId] });
    queryClient.invalidateQueries({ queryKey: ['company-employee-candidates', companyId] });
    show(message);
    close();
  };
  /** Clears the last error; `full` (a dialog being opened) also forgets that a correction reason was asked for. */
  const reset = (full = false) => {
    setServerMessage(null);
    setServerFields({});
    if (full) setNeedsCorrection(false);
  };

  return { serverMessage, serverFields, needsCorrection, failed, succeeded, reset };
}

function PositionSelect({ id, value, onChange, error }: { id: string; value: number | ''; onChange: (value: number | '') => void; error?: string }) {
  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });
  const [creating, setCreating] = useState(false);
  const { t } = useI18n();

  return (
    <FormField label={t('confirm.company.fieldPosition')} htmlFor={id} required error={error}>
      <Select
        id={id}
        value={value === '' ? '' : String(value)}
        onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
        placeholder={t('option.company.choosePosition')}
        options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
      />
      <button type="button" onClick={() => setCreating(true)} className="mt-1 text-xs font-semibold text-primary hover:underline">
        {t('action.company.createPosition')}
      </button>
      <CreatePositionDialog isOpen={creating} onClose={() => setCreating(false)} onCreated={(position) => onChange(position.id)} />
    </FormField>
  );
}

function TypeSelect({ id, value, onChange, error, hint, options = ASSIGNMENT_TYPE_OPTIONS }: { id: string; value: AssignmentTypeValue | ''; onChange: (value: AssignmentTypeValue | '') => void; error?: string; hint?: string; options?: { value: string; label: string; labelKey: MessageKey }[] }) {
  const { t } = useI18n();

  return (
    <FormField label={t('confirm.company.fieldType')} htmlFor={id} required error={error} hint={hint ?? t('confirm.company.typeHint')}>
      <Select id={id} value={value} onChange={(event) => onChange(event.target.value as AssignmentTypeValue | '')} placeholder={t('option.company.choosePermanentOrReplacement')} options={options.map((option) => ({ value: option.value, label: t(option.labelKey) }))} />
    </FormField>
  );
}

const PERMANENT_ONLY: { value: string; label: string; labelKey: MessageKey }[] = [{ value: 'permanent', label: 'Permanent', labelKey: 'option.assignmentType.permanent' }];

/** Day or night shift of the assignment. Optional and information only: it never changes salary, dates, type or company. */
function ShiftSelect({ id, value, onChange, hint }: { id: string; value: ShiftValue | ''; onChange: (value: ShiftValue | '') => void; hint?: string }) {
  const { t } = useI18n();

  return (
    <FormField label={t('confirm.company.fieldShift')} htmlFor={id} hint={hint ?? t('confirm.company.shiftHint')}>
      <Select id={id} value={value} onChange={(event) => onChange(event.target.value as ShiftValue | '')} placeholder={t('option.company.shiftNotRecorded')} options={SHIFT_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))} />
    </FormField>
  );
}

function useMoney() {
  const { t } = useI18n();

  return (amount: number | string, currency: string) => t('confirm.company.moneyPerMonth', { amount: Number(amount).toFixed(2), currency });
}

/**
 * Shown only after the server said this change reaches an approved or finalized payroll month. Only a Super Admin can record such a
 * change, and only with a reason; the closed month itself is never edited.
 */
function CorrectionReasonField({ visible, value, onChange, error }: { visible: boolean; value: string; onChange: (value: string) => void; error?: string }) {
  const { t } = useI18n();
  if (!visible) return null;
  return (
    <div className="mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-3" data-testid="correction-reason">
      <p className="mb-2 text-xs text-warning">
        {t('warning.company.correctionNotice')}
      </p>
      <FormField label={t('confirm.company.correctionReasonLabel')} htmlFor="correction-reason" required error={error} hint={t('confirm.company.correctionReasonHint')}>
        <input id="correction-reason" className={inputClasses} value={value} onChange={(event) => onChange(event.target.value)} />
      </FormField>
    </div>
  );
}

function correctionError(state: { needsCorrection: boolean }, reason: string, t: Translate): string | undefined {
  return state.needsCorrection && reason.trim().length < 5 ? t('validation.company.correctionReasonRequired') : undefined;
}

function SalaryFields({
  idPrefix,
  amount,
  currency,
  onAmount,
  onCurrency,
  errors,
}: {
  idPrefix: string;
  amount: string;
  currency: string;
  onAmount: (value: string) => void;
  onCurrency: (value: string) => void;
  errors: { salary_amount?: string; salary_currency?: string };
}) {
  const { t } = useI18n();

  return (
    <div className="grid grid-cols-[1fr_120px] gap-3">
      <FormField label={t('confirm.company.monthlySalary')} htmlFor={`${idPrefix}-salary`} required error={errors.salary_amount}>
        <input id={`${idPrefix}-salary`} type="number" min="0" step="0.01" className={inputClasses} value={amount} onChange={(event) => onAmount(event.target.value)} />
      </FormField>
      <FormField label={t('confirm.company.currency')} htmlFor={`${idPrefix}-currency`} required error={errors.salary_currency}>
        <input id={`${idPrefix}-currency`} maxLength={3} className={`${inputClasses} uppercase`} value={currency} onChange={(event) => onCurrency(event.target.value.toUpperCase())} />
      </FormField>
    </div>
  );
}

function Summary({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="mb-4 divide-y divide-border rounded-md border border-border text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="flex justify-between gap-4 px-3.5 py-2">
          <dt className="text-ink-muted">{label}</dt>
          <dd className="text-right font-medium text-ink">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

// ------------------------------------------------------------------------------------------------------------ add

export function AssignEmployeeDialog({ isOpen, onClose, companyId, companyName }: { isOpen: boolean; onClose: () => void; companyId: number; companyName: string }) {
  const state = useActionState(companyId);
  const { t } = useI18n();
  const [picked, setPicked] = useState<CompanyEmployeeCandidate | null>(null);
  const [positionId, setPositionId] = useState<number | ''>('');
  const [shift, setShift] = useState<ShiftValue | ''>('');
  const [startDate, setStartDate] = useState(today());
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [notes, setNotes] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [question, setQuestion] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen) {
      setQuestion(null);
      setPicked(null);
      setPositionId('');
      setShift('');
      setStartDate(today());
      setAmount('');
      setCurrency('USD');
      setNotes('');
      setCorrectionReason('');
      setErrors({});
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const mutation = useMutation({
    mutationFn: (confirmAdditional: boolean) =>
      companyEmployeesApi.assign(companyId, {
        employee_id: Number(picked?.id),
        // A Replacement is never added on its own: it takes over a Permanent employee's position through Replace Employee.
        assignment_type: 'permanent',
        position_id: Number(positionId),
        start_date: startDate,
        salary_amount: Number(amount),
        salary_currency: currency,
        salary_frequency: 'monthly',
        notes: notes.trim() === '' ? null : notes.trim(),
        ...(shift !== '' ? { shift } : {}),
        ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}),
        ...(confirmAdditional ? { confirm_additional_company: true } : {}),
      }),
    onSuccess: () => state.succeeded(t('notify.company.employeeAdded'), onClose),
    // The server asks for an explicit yes before a person who already works for another company is given a second one.
    onError: (error) => {
      const asked = additionalCompanyQuestion(error);
      if (asked !== null) setQuestion(asked);
      else state.failed(error);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: Record<string, string | undefined> = { ...validateAssignForm({ employee_id: picked?.id ?? '', assignment_type: 'permanent', position_id: positionId, start_date: startDate, salary_amount: amount, salary_currency: currency }), correction_reason: correctionError(state, correctionReason, t) };
    if (found.correction_reason === undefined) delete found.correction_reason;
    setErrors(found);
    if (Object.keys(found).length === 0) mutation.mutate(false);
  };
  const err = (field: string) => errors[field] ?? state.serverFields[field]?.[0];

  return (
    <>
    <ConfirmDialog
      isOpen={question !== null}
      title={t('confirm.company.additionalCompanyTitle')}
      description={question ?? ''}
      confirmLabel={t('action.assignBoth')}
      isLoading={mutation.isPending}
      onCancel={() => setQuestion(null)}
      onConfirm={() => {
        setQuestion(null);
        mutation.mutate(true);
      }}
    />
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('confirm.company.addEmployeeTitle', { company: companyName })}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={submit} isLoading={mutation.isPending}>{t('action.addEmployee')}</Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <ErrorBanner message={state.serverMessage} />
        <EmployeePicker companyId={companyId} inputId="assign-picker" label={t('confirm.company.registeredEmployee')} value={picked} onChange={setPicked} error={err('employee_id')} />
        <PositionSelect id="assign-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
        <ShiftSelect id="assign-shift" value={shift} onChange={setShift} />
        <FormField label={t('confirm.company.startDate')} htmlFor="assign-start" required error={err('start_date')}>
          <input id="assign-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        <SalaryFields idPrefix="assign" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        <FormField label={t('confirm.company.note')} htmlFor="assign-notes">
          <input id="assign-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </FormField>
        <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={err('correction_reason')} />
        <p className="text-xs text-ink-muted">{t('warning.company.addEmployeeNote')}</p>
      </form>
    </Modal>
    </>
  );
}

// ----------------------------------------------------------------------------------------------------------- edit

export function EditAssignmentDialog({ row, onClose, companyId }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number }) {
  const state = useActionState(companyId);
  const { t } = useI18n();
  const money = useMoney();
  const [positionId, setPositionId] = useState<number | ''>('');
  const [shift, setShift] = useState<ShiftValue | ''>('');
  const [type, setType] = useState<AssignmentTypeValue | ''>('');
  const [startDate, setStartDate] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [effectiveFrom, setEffectiveFrom] = useState(today());
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (row) {
      setCorrectionReason('');
      setPositionId(row.position_id ?? '');
      setShift(row.shift ?? '');
      setType(row.assignment_type);
      setStartDate(row.start_date);
      setAmount(String(Number(row.salary.amount)));
      setCurrency(row.salary.currency);
      setEffectiveFrom(today() < row.start_date ? row.start_date : today());
      setReason('');
      setNotes('');
      setErrors({});
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const isReplacement = row?.assignment_type === 'replacement';
  const salaryChanged = row !== null && !isReplacement && (Number(amount) !== Number(row.salary.amount) || currency !== row.salary.currency);
  const warning = row ? startDateChangeWarning(row.start_date, startDate) : null;

  const mutation = useMutation({
    mutationFn: () => {
      const r = row!;
      return companyEmployeesApi.edit(companyId, r.assignment_id, {
        ...(positionId !== (r.position_id ?? '') ? { position_id: positionId === '' ? null : positionId } : {}),
        ...(type !== r.assignment_type && type !== '' ? { assignment_type: type } : {}),
        ...(startDate !== r.start_date ? { start_date: startDate } : {}),
        ...(salaryChanged ? { salary_amount: Number(amount), salary_currency: currency, salary_effective_from: effectiveFrom, salary_change_reason: reason.trim() || null } : {}),
        ...(notes.trim() !== '' ? { notes: notes.trim() } : {}),
        ...assignmentShiftEdit(r.shift, shift),
        ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}),
      });
    },
    onSuccess: () => state.succeeded(t('notify.company.assignmentUpdated'), onClose),
    onError: state.failed,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: Record<string, string | undefined> = {};
    if (startDate === '') found.start_date = t('validation.company.enterStartDate');
    if (salaryChanged && (Number(amount) <= 0 || Number.isNaN(Number(amount)))) found.salary_amount = t('validation.company.salaryAmount');
    if (salaryChanged && !/^[A-Za-z]{3}$/.test(currency)) found.salary_currency = t('validation.company.currencyCode');
    if (salaryChanged && effectiveFrom < (row?.start_date ?? '')) found.salary_effective_from = t('validation.company.salaryBeforeAssignment');
    const needed = correctionError(state, correctionReason, t);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) mutation.mutate();
  };
  const err = (field: string) => errors[field] ?? state.serverFields[field]?.[0];

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={row ? t('confirm.company.editAssignmentTitle', { name: row.full_name ?? '' }) : t('confirm.company.editAssignmentFallbackTitle')}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={submit} isLoading={mutation.isPending}>{t('action.company.saveChanges')}</Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <ErrorBanner message={state.serverMessage} />
        <p className="mb-4 text-xs text-ink-muted">{t('confirm.company.editAssignmentIntro')}</p>
        <PositionSelect id="edit-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
        <ShiftSelect id="edit-shift" value={shift} onChange={setShift} hint={t('confirm.company.shiftEditHint')} />
        <TypeSelect id="edit-type" value={type} onChange={setType} error={err('assignment_type')} options={isReplacement ? ASSIGNMENT_TYPE_OPTIONS : PERMANENT_ONLY} hint={isReplacement ? t('confirm.company.typeEditHintReplacement') : t('confirm.company.typeEditHintPermanent')} />
        <FormField label={t('confirm.company.startDate')} htmlFor="edit-start" required error={err('start_date')}>
          <input id="edit-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        {warning && <div role="note" className="-mt-2 mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning">{warning}</div>}
        {isReplacement ? (
          <p className="mb-4 rounded-md border border-border bg-surface-muted px-3.5 py-2.5 text-xs text-ink-muted" data-testid="inherited-salary">
            {t('warning.company.inheritedSalaryEdit', { money: row ? money(row.salary.amount, row.salary.currency) : '', replaces: row?.replaces ? ` (${row.replaces.full_name})` : '' })}
          </p>
        ) : (
          <SalaryFields idPrefix="edit" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        )}
        {salaryChanged && (
          <>
            <FormField label={t('confirm.company.newSalaryFrom')} htmlFor="edit-effective" required error={err('salary_effective_from')} hint={t('confirm.company.newSalaryFromHint')}>
              <input id="edit-effective" type="date" className={inputClasses} value={effectiveFrom} onChange={(event) => setEffectiveFrom(event.target.value)} />
            </FormField>
            <FormField label={t('confirm.company.salaryChangeReason')} htmlFor="edit-reason">
              <input id="edit-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
            </FormField>
          </>
        )}
        <FormField label={t('confirm.company.addNote')} htmlFor="edit-notes">
          <input id="edit-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </FormField>
        <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={err('correction_reason')} />
      </form>
    </Modal>
  );
}

// -------------------------------------------------------------------------------------------------------- replace

export function ReplaceEmployeeDialog({ row, onClose, companyId, companyName }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number; companyName: string }) {
  const state = useActionState(companyId);
  const { t } = useI18n();
  const money = useMoney();
  const [step, setStep] = useState<'form' | 'review'>('form');
  const [picked, setPicked] = useState<CompanyEmployeeCandidate | null>(null);
  const [replacementDate, setReplacementDate] = useState(today());
  const [lastDay, setLastDay] = useState('');
  const [type, setType] = useState<AssignmentTypeValue | ''>('');
  const [positionId, setPositionId] = useState<number | ''>('');
  const [shift, setShift] = useState<ShiftValue | ''>('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [acknowledgeTemporary, setAcknowledgeTemporary] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });

  useEffect(() => {
    if (row) {
      setCorrectionReason('');
      setAcknowledgeTemporary(false);
      setStep('form');
      setPicked(null);
      setReplacementDate(today() > row.start_date ? today() : row.start_date);
      setLastDay('');
      setType('');
      setPositionId(row.position_id ?? '');
      setShift(row.shift ?? '');
      setAmount(String(Number(row.salary.amount)));
      setCurrency(row.salary.currency);
      setReason('');
      setErrors({});
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const effectiveLastDay = lastDay || (replacementDate ? dayBefore(replacementDate) : '');

  const mutation = useMutation({
    mutationFn: () =>
      companyEmployeesApi.replace(companyId, row!.assignment_id, {
        replacement_employee_id: Number(picked?.id),
        replacement_date: replacementDate,
        original_last_day: lastDay || null,
        assignment_type: type as AssignmentTypeValue,
        position_id: positionId === '' ? null : positionId,
        // A Replacement has no salary of its own: the server uses the replaced Permanent's. Only a Permanent successor brings one.
        ...(type === 'replacement' ? {} : { salary_amount: Number(amount), salary_currency: currency, salary_frequency: 'monthly' }),
        ...(acknowledgeTemporary ? { acknowledge_temporary_as_replacement: true } : {}),
        // Left out = the replacement takes over the shift of the position; sent only when HR chose a different one.
        ...assignmentShiftEdit(row!.shift, shift),
        reason: reason.trim() || null,
        ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}),
      }),
    onSuccess: () => state.succeeded(t('notify.company.employeeReplaced'), onClose),
    onError: (error) => {
      state.failed(error);
      setStep('form');
    },
  });

  const review = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found = validateReplaceForm(
      { replacement_employee_id: picked?.id ?? '', replacement_date: replacementDate, original_last_day: lastDay, assignment_type: type, position_id: positionId, salary_amount: amount, salary_currency: currency },
      row?.start_date ?? '',
      row?.employee_id ?? 0,
    ) as Record<string, string | undefined>;
    if (type === 'replacement' && isTemporaryWorker(picked) && !acknowledgeTemporary) found.replacement_employee_id = t('validation.company.temporaryAcknowledge');
    const needed = correctionError(state, correctionReason, t);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) setStep('review');
  };
  const err = (field: string) => errors[field] ?? state.serverFields[field]?.[0];
  const positionName = positions?.find((p) => p.id === positionId)?.name ?? row?.position_name ?? '—';

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={row ? t('confirm.company.replaceTitle', { name: row.full_name ?? '' }) : t('confirm.company.replaceFallbackTitle')}
      footer={
        step === 'form' ? (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
            <Button size="sm" onClick={review}>{t('action.reviewReplacement')}</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={() => setStep('form')} disabled={mutation.isPending}>{t('action.back')}</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>{t('action.confirmReplacement')}</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {step === 'form' && row && (
        <form onSubmit={review} noValidate>
          <p className="mb-4 text-xs text-ink-muted">
            {t('confirm.company.replaceIntro', { name: row.full_name ?? '', number: row.employee_number ?? '', company: companyName, position: row.position_name ?? t('option.company.noPosition'), date: formatDate(row.start_date) })}
          </p>
          <EmployeePicker companyId={companyId} inputId="replace-picker" label={t('confirm.company.replacementEmployee')} value={picked} onChange={setPicked} error={err('replacement_employee_id')} excludeIds={[row.employee_id]} />
          <FormField label={t('confirm.company.replacementDate')} htmlFor="replace-date" required error={err('replacement_date')}>
            <input id="replace-date" type="date" className={inputClasses} value={replacementDate} onChange={(event) => setReplacementDate(event.target.value)} />
          </FormField>
          <FormField label={t('confirm.company.originalLastDay')} htmlFor="replace-last" error={err('original_last_day')} hint={t('confirm.company.originalLastDayHint')}>
            <input id="replace-last" type="date" className={inputClasses} value={lastDay} placeholder={effectiveLastDay} onChange={(event) => setLastDay(event.target.value)} />
          </FormField>
          <TypeSelect id="replace-type" value={type} onChange={setType} error={err('assignment_type')} hint={t('confirm.company.replaceTypeHint')} />
          {type === 'replacement' && isTemporaryWorker(picked) && (
            <div role="alert" className="-mt-2 mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning" data-testid="temporary-warning">
              <p className="mb-1.5">{t('warning.company.temporaryAsReplacement', { name: picked?.full_name ?? '' })}</p>
              <label className="flex items-center gap-2">
                <input type="checkbox" id="replace-ack-temporary" checked={acknowledgeTemporary} onChange={(event) => setAcknowledgeTemporary(event.target.checked)} />
                {t('confirm.company.temporaryAcknowledge')}
              </label>
            </div>
          )}
          <PositionSelect id="replace-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
          <ShiftSelect id="replace-shift" value={shift} onChange={setShift} hint={t('confirm.company.shiftReplaceHint')} />
          {type === 'replacement' ? (
            <p className="mb-4 rounded-md border border-border bg-surface-muted px-3.5 py-2.5 text-xs text-ink-muted" data-testid="inherited-salary">
              {t('warning.company.inheritedSalaryReplace', { money: money(row.salary.amount, row.salary.currency), name: row.full_name ?? '' })}
            </p>
          ) : (
            <SalaryFields idPrefix="replace" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
          )}
          <FormField label={t('confirm.company.reasonOptional')} htmlFor="replace-reason">
            <input id="replace-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
          </FormField>
          <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={err('correction_reason')} />
        </form>
      )}
      {step === 'review' && row && picked && (
        <div>
          <p className="mb-3 text-sm text-ink">{t('confirm.company.checkChanges')}</p>
          <Summary
            rows={[
              [t('confirm.company.summaryCompany'), companyName],
              [t('confirm.company.summaryPosition'), positionName],
              [t('confirm.company.summaryOriginalEmployee'), `${row.full_name} (${row.employee_number})`],
              [t('confirm.company.summaryOriginalLastDay'), formatDate(effectiveLastDay)],
              [t('confirm.company.summaryReplacementEmployee'), `${picked.full_name} (${picked.employee_number})`],
              [t('confirm.company.summaryReplacementStarts'), formatDate(replacementDate)],
              [t('confirm.company.summaryReplacementIs'), type === '' ? '—' : assignmentTypeLabel(type)],
              [t('confirm.company.summarySalary'), type === 'replacement' ? t('confirm.company.salaryInheritedFrom', { money: money(row.salary.amount, row.salary.currency), name: row.full_name ?? '' }) : money(amount, currency)],
            ]}
          />
          <p className="text-xs text-ink-muted">{t('confirm.company.replaceFooterNote')}</p>
        </div>
      )}
    </Modal>
  );
}

// --------------------------------------------------------------------------------------------------------- return

export function ReturnEmployeeDialog({ row, onClose, companyId, companyName }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number; companyName: string }) {
  const state = useActionState(companyId);
  const { t } = useI18n();
  const money = useMoney();
  const [returnDate, setReturnDate] = useState(today());
  const [shift, setShift] = useState<ShiftValue | ''>('');
  const [notes, setNotes] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [salaryConfirmed, setSalaryConfirmed] = useState(false);
  const [correctionReason, setCorrectionReason] = useState('');
  const [step, setStep] = useState<'form' | 'review'>('form');
  const [errors, setErrors] = useState<ReturnFormErrors & { correction_reason?: string }>({});
  const { data: history } = useQuery({
    queryKey: ['company-employee-history', companyId, 'return', row?.assignment_id],
    queryFn: () => companyEmployeesApi.history(companyId),
    enabled: row !== null,
  });

  useEffect(() => {
    if (row) {
      setReturnDate(today() > row.start_date ? today() : dayAfter(row.start_date));
      setNotes('');
      setShift('');
      // The previous salary is only a proposal: HR must confirm or change it before saving.
      setAmount(String(Number(row.replaces?.salary.amount ?? row.salary.amount)));
      setCurrency(row.replaces?.salary.currency ?? row.salary.currency);
      setSalaryConfirmed(false);
      setCorrectionReason('');
      setStep('form');
      setErrors({});
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const original = history?.chain.find((entry) => entry.assignment_id === row?.replaces_assignment_id);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.returnOriginal(companyId, row!.assignment_id, {
        return_date: returnDate,
        salary_amount: Number(amount),
        salary_currency: currency,
        salary_frequency: 'monthly',
        notes: notes.trim() || null,
        ...(shift !== '' ? { shift } : {}),
        ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}),
      }),
    onSuccess: () => state.succeeded(t('notify.company.employeeReturned'), onClose),
    onError: (e) => {
      state.failed(e);
      setStep('form');
    },
  });

  const review = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: ReturnFormErrors & { correction_reason?: string } = validateReturnForm(returnDate, row?.start_date ?? '', { amount, currency, confirmed: salaryConfirmed });
    const needed = correctionError(state, correctionReason, t);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) setStep('review');
  };

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={t('action.returnOriginal')}
      footer={
        step === 'form' ? (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
            <Button size="sm" onClick={review}>{t('action.review')}</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={() => setStep('form')} disabled={mutation.isPending}>{t('action.back')}</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>{t('action.confirmReturn')}</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {row && (
        <>
          <Summary
            rows={[
              [t('confirm.company.currentReplacement'), t('confirm.company.currentReplacementValue', { name: row.full_name ?? '', number: row.employee_number ?? '', date: formatDate(row.start_date) })],
              [t('confirm.company.summaryOriginalEmployee'), original ? `${original.employee_name} (${original.employee_number})` : 'Loading…'],
            ]}
          />
          {step === 'form' && (
            <form onSubmit={review} noValidate>
              <FormField label={t('confirm.company.returnDate')} htmlFor="return-date" required error={errors.return_date ?? state.serverFields.return_date?.[0]}>
                <input id="return-date" type="date" className={inputClasses} value={returnDate} onChange={(event) => setReturnDate(event.target.value)} />
              </FormField>
              <SalaryFields idPrefix="return" amount={amount} currency={currency} onAmount={(v) => { setAmount(v); setSalaryConfirmed(false); }} onCurrency={(v) => { setCurrency(v); setSalaryConfirmed(false); }} errors={{ salary_amount: errors.salary_amount ?? state.serverFields.salary_amount?.[0], salary_currency: errors.salary_currency ?? state.serverFields.salary_currency?.[0] }} />
              <p className="-mt-2 mb-3 text-xs text-ink-muted" data-testid="previous-salary">{t('confirm.company.previousSalary', { salary: row.replaces ? money(row.replaces.salary.amount, row.replaces.salary.currency) : t('option.company.notKnown') })}</p>
              <div className="mb-4">
                <label className="flex items-center gap-2 text-sm text-ink">
                  <input type="checkbox" id="return-salary-confirmed" checked={salaryConfirmed} onChange={(event) => setSalaryConfirmed(event.target.checked)} />
                  {t('confirm.company.returnSalaryChecked')}
                </label>
                {errors.salary_confirmed && <p role="alert" className="mt-1 text-xs text-danger">{errors.salary_confirmed}</p>}
              </div>
              <ShiftSelect id="return-shift" value={shift} onChange={setShift} hint={t('confirm.company.shiftReturnHint')} />
              <FormField label={t('confirm.company.noteOptional')} htmlFor="return-notes">
                <input id="return-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
              </FormField>
              <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={errors.correction_reason} />
              <p className="text-xs text-ink-muted">{t('confirm.company.returnFooterNote')}</p>
            </form>
          )}
          {step === 'review' && (
            <div>
              <p className="mb-3 text-sm text-ink">{t('confirm.company.afterConfirm', { company: companyName })}</p>
              <Summary
                rows={[
                  [t('confirm.company.assignmentOfName', { name: row.full_name ?? '' }), t('confirm.company.endsOn', { date: formatDate(dayBefore(returnDate)) })],
                  [original ? `${original.employee_name}` : t('confirm.company.summaryOriginalEmployee'), t('confirm.company.startsAgainAsPermanent', { date: formatDate(returnDate) })],
                  [t('confirm.company.confirmedSalary'), money(amount, currency)],
                ]}
              />
              <p className="text-xs text-ink-muted">{t('confirm.company.returnNothingDeleted')}</p>
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

function dayAfter(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10);
}

// ---------------------------------------------------------------------------------------------------------- stop

export function StopEmployeeDialog({ row, onClose, companyId, companyName }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number; companyName: string }) {
  const state = useActionState(companyId);
  const { t } = useI18n();
  const [endDate, setEndDate] = useState(today());
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [errors, setErrors] = useState<{ end_date?: string; reason?: string; correction_reason?: string; successor?: string }>({});
  const [confirming, setConfirming] = useState(false);
  // A Permanent position cannot be left without a replacement (docs/HR_PAYROLL_RULES.md N1).
  const [successor, setSuccessor] = useState<SuccessorDraft>(EMPTY_SUCCESSOR);
  const askSuccessor = row !== null && needsSuccessor(row.assignment_type);

  useEffect(() => {
    if (row) {
      setEndDate(today() < row.start_date ? row.start_date : today());
      setCorrectionReason('');
      setReason('');
      setSuccessor(EMPTY_SUCCESSOR);
      setErrors({});
      setConfirming(false);
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.stop(companyId, row!.assignment_id, { end_date: endDate, reason: reason.trim(), ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}), ...(askSuccessor ? { successor: toSuccessorPayload(successor, endDate) ?? undefined } : {}) }),
    onSuccess: () => state.succeeded(t('notify.company.assignmentStopped'), onClose),
    onError: (e) => {
      state.failed(e);
      setConfirming(false);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: { end_date?: string; reason?: string; correction_reason?: string; successor?: string } = validateStopForm(endDate, reason, row?.start_date ?? '');
    const needed = correctionError(state, correctionReason, t);
    if (needed) found.correction_reason = needed;
    if (askSuccessor) {
      const missing = validateSuccessor(successor, endDate);
      if (missing) found.successor = missing;
    }
    setErrors(found);
    if (Object.keys(found).length === 0) setConfirming(true);
  };

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={row ? t('confirm.company.stopTitle', { name: row.full_name ?? '' }) : t('confirm.company.stopFallbackTitle')}
      footer={
        confirming ? (
          <>
            <Button variant="outline" size="sm" onClick={() => setConfirming(false)} disabled={mutation.isPending}>{t('action.back')}</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>{t('action.stopYes')}</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
            <Button size="sm" onClick={submit}>{t('action.continue')}</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {row && !confirming && (
        <form onSubmit={submit} noValidate>
          <p className="mb-4 text-xs text-ink-muted">
            {t('confirm.company.stopIntro', { name: row.full_name ?? '', company: companyName })}
          </p>
          <FormField label={t('confirm.company.stopLastDay')} htmlFor="stop-date" required error={errors.end_date ?? state.serverFields.end_date?.[0]}>
            <input id="stop-date" type="date" className={inputClasses} value={endDate} onChange={(event) => setEndDate(event.target.value)} />
          </FormField>
          <FormField label={t('confirm.company.reason')} htmlFor="stop-reason" required error={errors.reason ?? state.serverFields.reason?.[0]}>
            <input id="stop-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
          </FormField>
          {askSuccessor && <SuccessorPicker assignmentId={row.assignment_id} lastDay={endDate} value={successor} onChange={setSuccessor} error={errors.successor ?? state.serverFields.successor?.[0]} idPrefix="stop-successor" />}
          <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={errors.correction_reason} />
        </form>
      )}
      {row && confirming && (
        <Summary rows={[[t('confirm.company.stopSummaryEmployee'), `${row.full_name} (${row.employee_number})`], [t('confirm.company.summaryCompany'), companyName], [t('confirm.company.stopSummaryLastDay'), formatDate(endDate)], [t('confirm.company.reason'), reason.trim()], ...(askSuccessor ? [[t('confirm.company.stopSummaryReplacement'), successor.type === 'temporary' ? t('confirm.company.stopSummaryTemporaryTakes', { date: formatDate(effectiveStart(endDate, successor)) }) : t('confirm.company.stopSummaryWaitingTakes', { date: formatDate(effectiveStart(endDate, successor)) })] as [string, string]] : []), [t('confirm.company.stopSummaryHrStatus'), t('confirm.company.stopSummaryNotChanged')]]} />
      )}
    </Modal>
  );
}
