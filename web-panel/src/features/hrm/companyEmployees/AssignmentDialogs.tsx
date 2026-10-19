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
  dayBefore,
  startDateChangeWarning,
  validateAssignForm,
  validateReplaceForm,
  validateReturnForm,
  validateStopForm,
  type AssignmentTypeValue,
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

  const failed = (error: unknown) => {
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
  const reset = () => {
    setServerMessage(null);
    setServerFields({});
  };

  return { serverMessage, serverFields, failed, succeeded, reset };
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

function TypeSelect({ id, value, onChange, error, hint }: { id: string; value: AssignmentTypeValue | ''; onChange: (value: AssignmentTypeValue | '') => void; error?: string; hint?: string }) {
  return (
    <FormField label="Type" htmlFor={id} required error={error} hint={hint ?? 'Permanent holds the position; Replacement covers for someone else. It is never assumed.'}>
      <Select id={id} value={value} onChange={(event) => onChange(event.target.value as AssignmentTypeValue | '')} placeholder="Choose Permanent or Replacement…" options={ASSIGNMENT_TYPE_OPTIONS} />
    </FormField>
  );
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
  const [type, setType] = useState<AssignmentTypeValue | ''>('');
  const [positionId, setPositionId] = useState<number | ''>('');
  const [startDate, setStartDate] = useState(today());
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (isOpen) {
      setPicked(null);
      setType('');
      setPositionId('');
      setStartDate(today());
      setAmount('');
      setCurrency('USD');
      setNotes('');
      setErrors({});
      state.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const mutation = useMutation({
    mutationFn: () =>
      companyEmployeesApi.assign(companyId, {
        employee_id: Number(picked?.id),
        assignment_type: type as AssignmentTypeValue,
        position_id: Number(positionId),
        start_date: startDate,
        salary_amount: Number(amount),
        salary_currency: currency,
        salary_frequency: 'monthly',
        notes: notes.trim() === '' ? null : notes.trim(),
      }),
    onSuccess: () => state.succeeded('Employee added to the company.', onClose),
    onError: state.failed,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found = validateAssignForm({ employee_id: picked?.id ?? '', assignment_type: type, position_id: positionId, start_date: startDate, salary_amount: amount, salary_currency: currency });
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
        <TypeSelect id="assign-type" value={type} onChange={setType} error={err('assignment_type')} />
        <PositionSelect id="assign-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
        <FormField label="Start date" htmlFor="assign-start" required error={err('start_date')}>
          <input id="assign-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        <SalaryFields idPrefix="assign" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        <FormField label="Note" htmlFor="assign-notes">
          <input id="assign-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </FormField>
        <p className="text-xs text-ink-muted">This places an existing employee at the company. Their HR registration is not changed and no second record is created.</p>
      </form>
    </Modal>
  );
}

// ----------------------------------------------------------------------------------------------------------- edit

export function EditAssignmentDialog({ row, onClose, companyId }: { row: CompanyEmployeeRow | null; onClose: () => void; companyId: number }) {
  const state = useActionState(companyId);
  const [positionId, setPositionId] = useState<number | ''>('');
  const [type, setType] = useState<AssignmentTypeValue | ''>('');
  const [startDate, setStartDate] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [effectiveFrom, setEffectiveFrom] = useState(today());
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (row) {
      setPositionId(row.position_id ?? '');
      setType(row.assignment_type);
      setStartDate(row.start_date);
      setAmount(String(Number(row.salary.amount)));
      setCurrency(row.salary.currency);
      setEffectiveFrom(today() < row.start_date ? row.start_date : today());
      setReason('');
      setNotes('');
      setErrors({});
      state.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const salaryChanged = row !== null && (Number(amount) !== Number(row.salary.amount) || currency !== row.salary.currency);
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
        <TypeSelect id="edit-type" value={type} onChange={setType} error={err('assignment_type')} hint="Changing a Replacement to Permanent makes the position theirs; a Permanent holder cannot be 'returned from'." />
        <FormField label="Start date" htmlFor="edit-start" required error={err('start_date')}>
          <input id="edit-start" type="date" className={inputClasses} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </FormField>
        {warning && <div role="note" className="-mt-2 mb-4 rounded-md border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning">{warning}</div>}
        <SalaryFields idPrefix="edit" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
        {salaryChanged && (
          <>
            <FormField label="New salary applies from" htmlFor="edit-effective" required error={err('salary_effective_from')} hint="The old salary stays in the history and keeps applying before this date. Approved or finalized payroll months cannot be changed.">
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
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });

  useEffect(() => {
    if (row) {
      setStep('form');
      setPicked(null);
      setReplacementDate(today() > row.start_date ? today() : row.start_date);
      setLastDay('');
      setType('');
      setPositionId(row.position_id ?? '');
      setAmount(String(Number(row.salary.amount)));
      setCurrency(row.salary.currency);
      setReason('');
      setErrors({});
      state.reset();
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
        salary_amount: Number(amount),
        salary_currency: currency,
        salary_frequency: 'monthly',
        reason: reason.trim() || null,
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
    );
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
          <PositionSelect id="replace-position" value={positionId} onChange={setPositionId} error={err('position_id')} />
          <SalaryFields idPrefix="replace" amount={amount} currency={currency} onAmount={setAmount} onCurrency={setCurrency} errors={{ salary_amount: err('salary_amount'), salary_currency: err('salary_currency') }} />
          <FormField label="Reason (optional)" htmlFor="replace-reason">
            <input id="replace-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
          </FormField>
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
              ['Salary', `${Number(amount).toFixed(2)} ${currency} / month`],
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
  const [notes, setNotes] = useState('');
  const [step, setStep] = useState<'form' | 'review'>('form');
  const [error, setError] = useState<string | undefined>();
  const { data: history } = useQuery({
    queryKey: ['company-employee-history', companyId, 'return', row?.assignment_id],
    queryFn: () => companyEmployeesApi.history(companyId),
    enabled: row !== null,
  });

  useEffect(() => {
    if (row) {
      setReturnDate(today() > row.start_date ? today() : dayAfter(row.start_date));
      setNotes('');
      setStep('form');
      setError(undefined);
      state.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const original = history?.chain.find((entry) => entry.assignment_id === row?.replaces_assignment_id);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.returnOriginal(companyId, row!.assignment_id, { return_date: returnDate, notes: notes.trim() || null }),
    onSuccess: () => state.succeeded('Original employee returned. All assignments are kept in the history.', onClose),
    onError: (e) => {
      state.failed(e);
      setStep('form');
    },
  });

  const review = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found = validateReturnForm(returnDate, row?.start_date ?? '');
    setError(found.return_date);
    if (!found.return_date) setStep('review');
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
              <FormField label="Return date (first day back)" htmlFor="return-date" required error={error ?? state.serverFields.return_date?.[0]}>
                <input id="return-date" type="date" className={inputClasses} value={returnDate} onChange={(event) => setReturnDate(event.target.value)} />
              </FormField>
              <FormField label="Note (optional)" htmlFor="return-notes">
                <input id="return-notes" className={inputClasses} value={notes} onChange={(event) => setNotes(event.target.value)} />
              </FormField>
              <p className="text-xs text-ink-muted">The original employee gets a new assignment with the position and salary of their first one. Change them afterwards with Edit if they differ.</p>
            </form>
          )}
          {step === 'review' && (
            <div>
              <p className="mb-3 text-sm text-ink">After you confirm at {companyName}:</p>
              <Summary
                rows={[
                  [`${row.full_name}'s assignment`, `ends ${formatDate(dayBefore(returnDate))}`],
                  [original ? `${original.employee_name}` : 'Original employee', `starts again ${formatDate(returnDate)} as Permanent`],
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
  const [errors, setErrors] = useState<{ end_date?: string; reason?: string }>({});
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (row) {
      setEndDate(today() < row.start_date ? row.start_date : today());
      setReason('');
      setErrors({});
      setConfirming(false);
      state.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row]);

  const mutation = useMutation({
    mutationFn: () => companyEmployeesApi.stop(companyId, row!.assignment_id, { end_date: endDate, reason: reason.trim() }),
    onSuccess: () => state.succeeded("Assignment stopped. The employee's HR status was not changed.", onClose),
    onError: (e) => {
      state.failed(e);
      setConfirming(false);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    state.reset();
    const found = validateStopForm(endDate, reason, row?.start_date ?? '');
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
        </form>
      )}
      {row && confirming && (
        <Summary rows={[['Employee', `${row.full_name} (${row.employee_number})`], ['Company', companyName], ['Last day', formatDate(endDate)], ['Reason', reason.trim()], ['HR employment status', 'not changed']]} />
      )}
    </Modal>
  );
}
