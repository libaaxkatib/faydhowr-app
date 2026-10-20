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
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import {
  ASSIGNMENT_TYPE_LABELS,
  ASSIGNMENT_TYPE_OPTIONS,
  assignmentShiftEdit,
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
  const [serverMessage, setServerMessage] = useState<string | null>(null);
  const [serverFields, setServerFields] = useState<Record<string, string[]>>({});
  const [needsCorrection, setNeedsCorrection] = useState(false);

  const failed = (error: unknown) => {
    if (isCorrectionReasonRequired(error)) setNeedsCorrection(true);
    setServerMessage(friendlyErrorMessage(error, 'Something went wrong. Nothing was changed.'));
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

  return (
    <FormField label="Position" htmlFor={id} required error={error}>
      <Select
        id={id}
        value={value === '' ? '' : String(value)}
        onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
        placeholder="Choose a position…"
        options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
      />
    </FormField>
  );
}

function TypeSelect({ id, value, onChange, error, hint, options = ASSIGNMENT_TYPE_OPTIONS }: { id: string; value: AssignmentTypeValue | ''; onChange: (value: AssignmentTypeValue | '') => void; error?: string; hint?: string; options?: { value: string; label: string }[] }) {
  return (
    <FormField label="Type" htmlFor={id} required error={error} hint={hint ?? 'Permanent holds the position; Replacement covers for someone else. It is never assumed.'}>
      <Select id={id} value={value} onChange={(event) => onChange(event.target.value as AssignmentTypeValue | '')} placeholder="Choose Permanent or Replacement…" options={options} />
    </FormField>
  );
}

const PERMANENT_ONLY = [{ value: 'permanent', label: 'Permanent' }];

/** Day or night shift of the assignment. Optional and information only: it never changes salary, dates, type or company. */
function ShiftSelect({ id, value, onChange, hint }: { id: string; value: ShiftValue | ''; onChange: (value: ShiftValue | '') => void; hint?: string }) {
  return (
    <FormField label="Shift" htmlFor={id} hint={hint ?? 'Day or night. Optional - leave it empty if it is not known.'}>
      <Select id={id} value={value} onChange={(event) => onChange(event.target.value as ShiftValue | '')} placeholder="Not recorded" options={SHIFT_OPTIONS} />
    </FormField>
  );
}

const money = (amount: number | string, currency: string) => `${Number(amount).toFixed(2)} ${currency} / month`;

/**
 * Shown only after the server said this change reaches an approved or finalized payroll month. Only a Super Admin can record such a
 * change, and only with a reason; the closed month itself is never edited.
 */
function CorrectionReasonField({ visible, value, onChange, error }: { visible: boolean; value: string; onChange: (value: string) => void; error?: string }) {
  if (!visible) return null;
  return (
    <div className="mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-3" data-testid="correction-reason">
      <p className="mb-2 text-xs text-warning">
        This change reaches payroll that is already approved or finalized. Only a Super Admin can record it, as a correction with a reason. The closed month stays exactly as it was; if someone was overpaid, the amount is deducted from their later salaries until it is repaid.
      </p>
      <FormField label="Correction reason" htmlFor="correction-reason" required error={error} hint="At least 5 characters. It is kept in the audit history.">
        <input id="correction-reason" className={inputClasses} value={value} onChange={(event) => onChange(event.target.value)} />
      </FormField>
    </div>
  );
}

function correctionError(state: { needsCorrection: boolean }, reason: string): string | undefined {
  return state.needsCorrection && reason.trim().length < 5 ? 'Enter the correction reason (at least 5 characters).' : undefined;
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
  return (
    <div className="grid grid-cols-[1fr_120px] gap-3">
      <FormField label="Monthly salary" htmlFor={`${idPrefix}-salary`} required error={errors.salary_amount}>
        <input id={`${idPrefix}-salary`} type="number" min="0" step="0.01" className={inputClasses} value={amount} onChange={(event) => onAmount(event.target.value)} />
      </FormField>
      <FormField label="Currency" htmlFor={`${idPrefix}-currency`} required error={errors.salary_currency}>
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
  const [picked, setPicked] = useState<CompanyEmployeeCandidate | null>(null);
  const [positionId, setPositionId] = useState<number | ''>('');
  const [shift, setShift] = useState<ShiftValue | ''>('');
  const [startDate, setStartDate] = useState(today());
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [notes, setNotes] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (isOpen) {
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
    mutationFn: () =>
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
      }),
    onSuccess: () => state.succeeded('Employee added to the company.', onClose),
    onError: state.failed,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: Record<string, string | undefined> = { ...validateAssignForm({ employee_id: picked?.id ?? '', assignment_type: 'permanent', position_id: positionId, start_date: startDate, salary_amount: amount, salary_currency: currency }), correction_reason: correctionError(state, correctionReason) };
    if (found.correction_reason === undefined) delete found.correction_reason;
    setErrors(found);
    if (Object.keys(found).length === 0) mutation.mutate();
  };
  const err = (field: string) => errors[field] ?? state.serverFields[field]?.[0];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Add employee to ${companyName}`}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
          <Button size="sm" onClick={submit} isLoading={mutation.isPending}>Add employee</Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <ErrorBanner message={state.serverMessage} />
        <EmployeePicker companyId={companyId} inputId="assign-picker" label="Registered employee" value={picked} onChange={setPicked} error={err('employee_id')} />
        <PositionSelect id="assign-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
        <ShiftSelect id="assign-shift" value={shift} onChange={setShift} />
        <FormField label="Start date" htmlFor="assign-start" required error={err('start_date')}>
          <input id="assign-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        <SalaryFields idPrefix="assign" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        <FormField label="Note" htmlFor="assign-notes">
          <input id="assign-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </FormField>
        <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={err('correction_reason')} />
        <p className="text-xs text-ink-muted">This places an existing employee at the company as a Permanent employee. Their HR registration is not changed and no second record is created. To put someone in the place of an existing employee, use Replace on that employee.</p>
      </form>
    </Modal>
  );
}

// ----------------------------------------------------------------------------------------------------------- edit

export function EditAssignmentDialog({ row, onClose, companyId }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number }) {
  const state = useActionState(companyId);
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
    onSuccess: () => state.succeeded('Assignment updated.', onClose),
    onError: state.failed,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: Record<string, string | undefined> = {};
    if (startDate === '') found.start_date = 'Enter the start date.';
    if (salaryChanged && (Number(amount) <= 0 || Number.isNaN(Number(amount)))) found.salary_amount = 'Enter a salary greater than zero.';
    if (salaryChanged && !/^[A-Za-z]{3}$/.test(currency)) found.salary_currency = 'Use a 3-letter currency code.';
    if (salaryChanged && effectiveFrom < (row?.start_date ?? '')) found.salary_effective_from = 'A salary cannot take effect before the assignment starts.';
    const needed = correctionError(state, correctionReason);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) mutation.mutate();
  };
  const err = (field: string) => errors[field] ?? state.serverFields[field]?.[0];

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={row ? `Edit assignment — ${row.full_name}` : 'Edit assignment'}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
          <Button size="sm" onClick={submit} isLoading={mutation.isPending}>Save changes</Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <ErrorBanner message={state.serverMessage} />
        <p className="mb-4 text-xs text-ink-muted">These are the details of this company assignment only. The employee's own HR record (name, phone, status) is edited from the HR employee page.</p>
        <PositionSelect id="edit-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
        <ShiftSelect id="edit-shift" value={shift} onChange={setShift} hint="Changing the shift does not change the salary, the dates or the company." />
        <TypeSelect id="edit-type" value={type} onChange={setType} error={err('assignment_type')} options={isReplacement ? ASSIGNMENT_TYPE_OPTIONS : PERMANENT_ONLY} hint={isReplacement ? "Changing a Replacement to Permanent makes the position theirs; a Permanent holder cannot be 'returned from'." : 'An assignment becomes a Replacement only through Replace Employee.'} />
        <FormField label="Start date" htmlFor="edit-start" required error={err('start_date')}>
          <input id="edit-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        {warning && <div role="note" className="-mt-2 mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning">{warning}</div>}
        {isReplacement ? (
          <p className="mb-4 rounded-md border border-border bg-surface-muted px-3.5 py-2.5 text-xs text-ink-muted" data-testid="inherited-salary">
            Salary: {row ? money(row.salary.amount, row.salary.currency) : ''}. A Replacement has no salary of its own; it uses the monthly salary of the Permanent employee whose position it holds{row?.replaces ? ` (${row.replaces.full_name})` : ''}, so it cannot be edited here. When that Permanent salary changes, the new salary applies to the Replacement for the whole month of the change.
          </p>
        ) : (
          <SalaryFields idPrefix="edit" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        )}
        {salaryChanged && (
          <>
            <FormField label="New salary applies from" htmlFor="edit-effective" required error={err('salary_effective_from')} hint="The old salary stays in the history and keeps applying before this date. If a Replacement holds this position, the new salary applies to the Replacement for the whole month of the change. Approved or finalized payroll months are only changed by a Super Admin correction.">
              <input id="edit-effective" type="date" className={inputClasses} value={effectiveFrom} onChange={(event) => setEffectiveFrom(event.target.value)} />
            </FormField>
            <FormField label="Reason for the salary change" htmlFor="edit-reason">
              <input id="edit-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
            </FormField>
          </>
        )}
        <FormField label="Add a note" htmlFor="edit-notes">
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
    onSuccess: () => state.succeeded('Employee replaced. Both assignments are kept in the history.', onClose),
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
    if (type === 'replacement' && isTemporaryWorker(picked) && !acknowledgeTemporary) found.replacement_employee_id = 'This person is a Temporary worker. Confirm below that you really want a Replacement, or choose Permanent / correct the registration.';
    const needed = correctionError(state, correctionReason);
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
      title={row ? `Replace ${row.full_name}` : 'Replace employee'}
      footer={
        step === 'form' ? (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
            <Button size="sm" onClick={review}>Review</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={() => setStep('form')} disabled={mutation.isPending}>Back</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>Confirm replacement</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {step === 'form' && row && (
        <form onSubmit={review} noValidate>
          <p className="mb-4 text-xs text-ink-muted">
            {row.full_name} ({row.employee_number}) at {companyName}, {row.position_name ?? 'no position'}, since {formatDate(row.start_date)}. Their assignment and pay history are kept.
          </p>
          <EmployeePicker companyId={companyId} inputId="replace-picker" label="Replacement employee" value={picked} onChange={setPicked} error={err('replacement_employee_id')} excludeIds={[row.employee_id]} />
          <FormField label="Replacement date (first day of the replacement)" htmlFor="replace-date" required error={err('replacement_date')}>
            <input id="replace-date" type="date" className={inputClasses} value={replacementDate} onChange={(event) => setReplacementDate(event.target.value)} />
          </FormField>
          <FormField label="Last day of the original employee" htmlFor="replace-last" error={err('original_last_day')} hint="Leave empty to use the day before the replacement date. A gap between the two is allowed; an overlap is not.">
            <input id="replace-last" type="date" className={inputClasses} value={lastDay} placeholder={effectiveLastDay} onChange={(event) => setLastDay(event.target.value)} />
          </FormField>
          <TypeSelect id="replace-type" value={type} onChange={setType} error={err('assignment_type')} hint="Is the replacement meant to be Permanent, or a Replacement who may later be returned from? Choose explicitly." />
          {type === 'replacement' && isTemporaryWorker(picked) && (
            <div role="alert" className="-mt-2 mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning" data-testid="temporary-warning">
              <p className="mb-1.5">{picked?.full_name} is registered as a Temporary worker. Temporary cover is recorded as Temporary cover (paid per verified day), not as a Replacement who takes over the position. Registering them as a Replacement is probably a mistake. Nothing is converted for you. If you go on, your confirmation is recorded in the audit history, but NO payroll line is calculated for these days and payroll for the month cannot be calculated until the employee type (or this record) is corrected.</p>
              <label className="flex items-center gap-2">
                <input type="checkbox" id="replace-ack-temporary" checked={acknowledgeTemporary} onChange={(event) => setAcknowledgeTemporary(event.target.checked)} />
                I have checked, and I want a Replacement for this person anyway
              </label>
            </div>
          )}
          <PositionSelect id="replace-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
          <ShiftSelect id="replace-shift" value={shift} onChange={setShift} hint="Empty = the replacement takes over the shift of this position." />
          {type === 'replacement' ? (
            <p className="mb-4 rounded-md border border-border bg-surface-muted px-3.5 py-2.5 text-xs text-ink-muted" data-testid="inherited-salary">
              Salary: {money(row.salary.amount, row.salary.currency)}, the monthly salary of {row.full_name}. A Replacement has no salary of its own; it is paid from {row.full_name}'s salary for the calendar days it covers. If that salary changes, the new salary applies to the Replacement for the whole month of the change, not from the day it changed.
            </p>
          ) : (
            <SalaryFields idPrefix="replace" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
          )}
          <FormField label="Reason (optional)" htmlFor="replace-reason">
            <input id="replace-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
          </FormField>
          <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={err('correction_reason')} />
        </form>
      )}
      {step === 'review' && row && picked && (
        <div>
          <p className="mb-3 text-sm text-ink">Please check these changes before saving:</p>
          <Summary
            rows={[
              ['Company', companyName],
              ['Position', positionName],
              ['Original employee', `${row.full_name} (${row.employee_number})`],
              ['Original last day', formatDate(effectiveLastDay)],
              ['Replacement employee', `${picked.full_name} (${picked.employee_number})`],
              ['Replacement starts', formatDate(replacementDate)],
              ['Replacement is', type === '' ? '—' : ASSIGNMENT_TYPE_LABELS[type]],
              ['Salary', type === 'replacement' ? `${money(row.salary.amount, row.salary.currency)} (inherited from ${row.full_name})` : money(amount, currency)],
            ]}
          />
          <p className="text-xs text-ink-muted">The original employee stays an active HR employee. Both assignments stay in the history and no payroll record is changed.</p>
        </div>
      )}
    </Modal>
  );
}

// --------------------------------------------------------------------------------------------------------- return

export function ReturnEmployeeDialog({ row, onClose, companyId, companyName }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number; companyName: string }) {
  const state = useActionState(companyId);
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
    onSuccess: () => state.succeeded('Original employee returned. All assignments are kept in the history.', onClose),
    onError: (e) => {
      state.failed(e);
      setStep('form');
    },
  });

  const review = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: ReturnFormErrors & { correction_reason?: string } = validateReturnForm(returnDate, row?.start_date ?? '', { amount, currency, confirmed: salaryConfirmed });
    const needed = correctionError(state, correctionReason);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) setStep('review');
  };

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title="Return original employee"
      footer={
        step === 'form' ? (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
            <Button size="sm" onClick={review}>Review</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={() => setStep('form')} disabled={mutation.isPending}>Back</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>Confirm return</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {row && (
        <>
          <Summary
            rows={[
              ['Current replacement', `${row.full_name} (${row.employee_number}), since ${formatDate(row.start_date)}`],
              ['Original employee', original ? `${original.employee_name} (${original.employee_number})` : 'Loading…'],
            ]}
          />
          {step === 'form' && (
            <form onSubmit={review} noValidate>
              <FormField label="Return date (first day back)" htmlFor="return-date" required error={errors.return_date ?? state.serverFields.return_date?.[0]}>
                <input id="return-date" type="date" className={inputClasses} value={returnDate} onChange={(event) => setReturnDate(event.target.value)} />
              </FormField>
              <SalaryFields idPrefix="return" amount={amount} currency={currency} onAmount={(v) => { setAmount(v); setSalaryConfirmed(false); }} onCurrency={(v) => { setCurrency(v); setSalaryConfirmed(false); }} errors={{ salary_amount: errors.salary_amount ?? state.serverFields.salary_amount?.[0], salary_currency: errors.salary_currency ?? state.serverFields.salary_currency?.[0] }} />
              <p className="-mt-2 mb-3 text-xs text-ink-muted" data-testid="previous-salary">Previous salary: {row.replaces ? money(row.replaces.salary.amount, row.replaces.salary.currency) : 'not known'}. It is only a proposal: change it if the salary is different now.</p>
              <div className="mb-4">
                <label className="flex items-center gap-2 text-sm text-ink">
                  <input type="checkbox" id="return-salary-confirmed" checked={salaryConfirmed} onChange={(event) => setSalaryConfirmed(event.target.checked)} />
                  I checked this salary for the new Permanent assignment
                </label>
                {errors.salary_confirmed && <p role="alert" className="mt-1 text-xs text-danger">{errors.salary_confirmed}</p>}
              </div>
              <ShiftSelect id="return-shift" value={shift} onChange={setShift} hint="Empty = the returning employee gets the shift of the position as it is run now." />
              <FormField label="Note (optional)" htmlFor="return-notes">
                <input id="return-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
              </FormField>
              <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={errors.correction_reason} />
              <p className="text-xs text-ink-muted">The original employee gets a NEW Permanent assignment with their original position and the salary you confirm above. The Replacement's assignment ends the day before the return date and everything is kept in the history.</p>
            </form>
          )}
          {step === 'review' && (
            <div>
              <p className="mb-3 text-sm text-ink">After you confirm at {companyName}:</p>
              <Summary
                rows={[
                  [`${row.full_name}'s assignment`, `ends ${formatDate(dayBefore(returnDate))}`],
                  [original ? `${original.employee_name}` : 'Original employee', `starts again ${formatDate(returnDate)} as Permanent`],
                  ['Confirmed salary', money(amount, currency)],
                ]}
              />
              <p className="text-xs text-ink-muted">Nothing is deleted: the replacement's assignment, pay, attendance and history all stay.</p>
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
  const [endDate, setEndDate] = useState(today());
  const [reason, setReason] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const [errors, setErrors] = useState<{ end_date?: string; reason?: string; correction_reason?: string }>({});
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (row) {
      setEndDate(today() < row.start_date ? row.start_date : today());
      setCorrectionReason('');
      setReason('');
      setErrors({});
      setConfirming(false);
      state.reset(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.stop(companyId, row!.assignment_id, { end_date: endDate, reason: reason.trim(), ...(state.needsCorrection ? { correction_reason: correctionReason.trim() } : {}) }),
    onSuccess: () => state.succeeded("Assignment stopped. The employee's HR status was not changed.", onClose),
    onError: (e) => {
      state.failed(e);
      setConfirming(false);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found: { end_date?: string; reason?: string; correction_reason?: string } = validateStopForm(endDate, reason, row?.start_date ?? '');
    const needed = correctionError(state, correctionReason);
    if (needed) found.correction_reason = needed;
    setErrors(found);
    if (Object.keys(found).length === 0) setConfirming(true);
  };

  return (
    <Modal
      isOpen={row !== null}
      onClose={onClose}
      title={row ? `Stop ${row.full_name}` : 'Stop employee'}
      footer={
        confirming ? (
          <>
            <Button variant="outline" size="sm" onClick={() => setConfirming(false)} disabled={mutation.isPending}>Back</Button>
            <Button size="sm" onClick={() => mutation.mutate()} isLoading={mutation.isPending}>Yes, stop this assignment</Button>
          </>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
            <Button size="sm" onClick={submit}>Continue</Button>
          </>
        )
      }
    >
      <ErrorBanner message={state.serverMessage} />
      {row && !confirming && (
        <form onSubmit={submit} noValidate>
          <p className="mb-4 text-xs text-ink-muted">
            This ends only {row.full_name}'s assignment at {companyName}. It does not end their employment or change their HR status — to end employment altogether, use Separation on the employee's HR record. History and payroll are kept, and the position can be given to another registered employee.
          </p>
          <FormField label="Last day at this company" htmlFor="stop-date" required error={errors.end_date ?? state.serverFields.end_date?.[0]}>
            <input id="stop-date" type="date" className={inputClasses} value={endDate} onChange={(event) => setEndDate(event.target.value)} />
          </FormField>
          <FormField label="Reason" htmlFor="stop-reason" required error={errors.reason ?? state.serverFields.reason?.[0]}>
            <input id="stop-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
          </FormField>
          <CorrectionReasonField visible={state.needsCorrection} value={correctionReason} onChange={setCorrectionReason} error={errors.correction_reason} />
        </form>
      )}
      {row && confirming && (
        <Summary rows={[['Employee', `${row.full_name} (${row.employee_number})`], ['Company', companyName], ['Last day', formatDate(endDate)], ['Reason', reason.trim()], ['HR employment status', 'not changed']]} />
      )}
    </Modal>
  );
}
