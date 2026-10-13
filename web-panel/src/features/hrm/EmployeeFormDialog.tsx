import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import { duplicateMatchesFromError, friendlyErrorMessage } from '@/utils/hrHelpers';
import type {
  CreateEmployeePayload,
  DuplicateEmployeeMatch,
  Employee,
  EmployeeGender,
  EmploymentType,
  UpdateEmployeePayload,
} from '@/types/employee';

interface EmployeeFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  employee?: Employee;
}

const GENDER_OPTIONS: { value: EmployeeGender; label: string }[] = [
  { value: 'female', label: 'Female' },
  { value: 'male', label: 'Male' },
];

const EMPLOYMENT_TYPE_OPTIONS: { value: EmploymentType; label: string }[] = [
  { value: 'permanent', label: 'Permanent' },
  { value: 'temporary', label: 'Temporary' },
];

const MIN_DUPLICATE_REASON_LENGTH = 10;

const emptyForm = {
  employment_type: 'permanent' as EmploymentType,
  full_name: '',
  phone: '',
  alternate_phone: '',
  payment_account_number: '',
  location: '',
  gender: '' as EmployeeGender | '',
  age: '',
  marital_status: '',
  lives_with: '',
  reference_name: '',
  secondary_contact_name: '',
  secondary_contact_phone: '',
  employee_category_id: '',
  category_specialization: '',
  department_id: '',
  position_id: '',
  application_date: new Date().toISOString().slice(0, 10),
  joining_date: '',
  experience: '',
  training_fee_amount: '',
  training_fee_status: '',
  source: '',
  notes: '',
};

export function EmployeeFormDialog({ isOpen, onClose, mode, employee }: EmployeeFormDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  // Live warning from GET employees/duplicate-check, shown while typing.
  const [liveMatches, setLiveMatches] = useState<DuplicateEmployeeMatch[]>([]);
  // Matches the server returned with a 422 DUPLICATE_EMPLOYEE_PHONE - these block saving until HR confirms.
  const [serverMatches, setServerMatches] = useState<DuplicateEmployeeMatch[]>([]);
  const [confirmDuplicate, setConfirmDuplicate] = useState(false);
  const [duplicateReason, setDuplicateReason] = useState('');
  const checkSequence = useRef(0);
  const duplicatePanelRef = useRef<HTMLDivElement>(null);

  const { data: categories } = useQuery({ queryKey: ['employee-categories'], queryFn: hrApi.employeeCategories.list });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });
  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });

  useEffect(() => {
    if (isOpen) {
      setFieldErrors({});
      setLiveMatches([]);
      setServerMatches([]);
      setConfirmDuplicate(false);
      setDuplicateReason('');
      setForm(
        employee
          ? {
              employment_type: employee.employment_type ?? 'permanent',
              full_name: employee.full_name,
              // Legitimately null for migrated employees — never fabricated, just left blank to edit.
              phone: employee.phone ?? '',
              alternate_phone: employee.alternate_phone ?? '',
              payment_account_number: employee.payment_account_number ?? '',
              location: employee.location ?? '',
              gender: employee.gender ?? '',
              age: employee.age?.toString() ?? '',
              marital_status: employee.marital_status ?? '',
              lives_with: employee.lives_with ?? '',
              reference_name: employee.reference_name ?? '',
              secondary_contact_name: employee.secondary_contact_name ?? '',
              secondary_contact_phone: employee.secondary_contact_phone ?? '',
              employee_category_id: String(employee.employee_category_id),
              category_specialization: employee.category_specialization ?? '',
              department_id: employee.department_id ? String(employee.department_id) : '',
              position_id: employee.position_id ? String(employee.position_id) : '',
              application_date: employee.application_date ?? '',
              joining_date: employee.joining_date ?? '',
              experience: employee.experience ?? '',
              training_fee_amount: employee.training_fee_amount ?? '',
              training_fee_status: employee.training_fee_status ?? '',
              source: employee.source ?? '',
              notes: employee.notes ?? '',
            }
          : emptyForm,
      );
    }
  }, [isOpen, employee]);

  /**
   * Asks the server whether this phone / name already belongs to someone. Runs when a
   * field loses focus; a failure is ignored on purpose because saving re-checks anyway.
   */
  async function runDuplicateCheck() {
    const phone = form.phone.trim();
    const alternatePhone = form.alternate_phone.trim();
    const fullName = form.full_name.trim();
    if (!phone && !alternatePhone && fullName.length < 3) {
      setLiveMatches([]);
      return;
    }
    const sequence = ++checkSequence.current;
    try {
      const matches = await hrApi.employees.duplicateCheck({
        phone: phone || undefined,
        alternate_phone: alternatePhone || undefined,
        full_name: fullName || undefined,
        ignore_employee_id: employee?.id,
      });
      if (sequence === checkSequence.current) setLiveMatches(matches);
    } catch {
      /* the server checks again when the form is saved */
    }
  }

  /** Editing a phone number invalidates any earlier "different person" confirmation. */
  function handlePhoneChange(field: 'phone' | 'alternate_phone', value: string) {
    setForm({ ...form, [field]: value });
    setServerMatches([]);
    setConfirmDuplicate(false);
    setDuplicateReason('');
  }

  const phoneMatches = serverMatches.length > 0 ? serverMatches : liveMatches.filter((match) => match.matched_on.includes('phone'));
  const nameOnlyMatches = liveMatches.filter((match) => !match.matched_on.includes('phone'));
  const needsDuplicateConfirmation = serverMatches.length > 0;
  const duplicateConfirmationMissing =
    needsDuplicateConfirmation && (!confirmDuplicate || duplicateReason.trim().length < MIN_DUPLICATE_REASON_LENGTH);

  const mutation = useMutation({
    mutationFn: async () => {
      const duplicateOverride = needsDuplicateConfirmation && confirmDuplicate
        ? { confirm_duplicate: true, duplicate_reason: duplicateReason.trim() }
        : {};
      if (mode === 'create') {
        const payload: CreateEmployeePayload = {
          ...duplicateOverride,
          employment_type: form.employment_type,
          full_name: form.full_name,
          phone: form.phone,
          alternate_phone: form.alternate_phone || null,
          payment_account_number: form.payment_account_number.trim() || null,
          location: form.location,
          gender: form.gender as EmployeeGender,
          age: form.age ? Number(form.age) : null,
          marital_status: form.marital_status || null,
          lives_with: form.lives_with || null,
          reference_name: form.reference_name || null,
          secondary_contact_name: form.secondary_contact_name || null,
          secondary_contact_phone: form.secondary_contact_phone || null,
          employee_category_id: Number(form.employee_category_id),
          department_id: form.department_id ? Number(form.department_id) : null,
          position_id: form.position_id ? Number(form.position_id) : null,
          application_date: form.application_date,
          experience: form.experience || null,
          training_fee_amount: form.training_fee_amount ? Number(form.training_fee_amount) : null,
          training_fee_status: form.training_fee_status || null,
          source: form.source || null,
          notes: form.notes || null,
        };
        return hrApi.employees.create(payload);
      }
      const payload: UpdateEmployeePayload = {
        ...duplicateOverride,
        employment_type: form.employment_type,
        full_name: form.full_name,
        // Never fabricated: an empty field is saved as null, not as a placeholder string.
        phone: form.phone || null,
        alternate_phone: form.alternate_phone || null,
        // Sent only when the record actually carried the field (it is withheld from users who cannot manage HR), so an
        // edit can never blank a value this screen was not allowed to see.
        ...(employee?.payment_account_number !== undefined ? { payment_account_number: form.payment_account_number.trim() || null } : {}),
        location: form.location || null,
        gender: form.gender ? (form.gender as EmployeeGender) : undefined,
        age: form.age ? Number(form.age) : null,
        marital_status: form.marital_status || null,
        lives_with: form.lives_with || null,
        reference_name: form.reference_name || null,
        secondary_contact_name: form.secondary_contact_name || null,
        secondary_contact_phone: form.secondary_contact_phone || null,
        employee_category_id: Number(form.employee_category_id),
        category_specialization: form.category_specialization || null,
        department_id: form.department_id ? Number(form.department_id) : null,
        position_id: form.position_id ? Number(form.position_id) : null,
        application_date: form.application_date || null,
        joining_date: form.joining_date || null,
        experience: form.experience || null,
        training_fee_amount: form.training_fee_amount ? Number(form.training_fee_amount) : null,
        training_fee_status: form.training_fee_status || null,
        source: form.source || null,
        notes: form.notes || null,
      };
      return hrApi.employees.update(employee!.id, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      if (employee) queryClient.invalidateQueries({ queryKey: ['employee', employee.id] });
      show(mode === 'create' ? 'Employee registered.' : 'Employee updated.');
      onClose();
    },
    onError: (error) => {
      const duplicates = duplicateMatchesFromError(error);
      if (duplicates) {
        // Same phone as an existing person: stop and ask HR to confirm this is someone different.
        setServerMatches(duplicates as DuplicateEmployeeMatch[]);
        setConfirmDuplicate(false);
        setFieldErrors({});
        window.setTimeout(() => duplicatePanelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50);
      } else if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(friendlyErrorMessage(error, 'Something went wrong.'), 'error');
      }
    },
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (duplicateConfirmationMissing) return;
    mutation.mutate();
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === 'create' ? 'Register Employee' : 'Edit Employee'}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending} disabled={duplicateConfirmationMissing}>
            {needsDuplicateConfirmation
              ? mode === 'create'
                ? 'Register as a different person'
                : 'Save as a different person'
              : mode === 'create'
                ? 'Register'
                : 'Save Changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Full name" htmlFor="full_name" required error={fieldErrors.full_name?.[0]}>
            <input
              id="full_name"
              required
              className={inputClasses}
              value={form.full_name}
              onChange={(e) => setForm({ ...form, full_name: e.target.value })}
              onBlur={runDuplicateCheck}
            />
          </FormField>
          <FormField
            label="Phone"
            htmlFor="phone"
            required={mode === 'create'}
            hint={mode === 'edit' ? 'May be left blank — some migrated employees have no phone on record.' : undefined}
            error={fieldErrors.phone?.[0]}
          >
            <input
              id="phone"
              required={mode === 'create'}
              className={inputClasses}
              value={form.phone}
              onChange={(e) => handlePhoneChange('phone', e.target.value)}
              onBlur={runDuplicateCheck}
            />
          </FormField>
        </div>

        <div ref={duplicatePanelRef}>
          <DuplicateWarnings
            phoneMatches={phoneMatches}
            nameOnlyMatches={nameOnlyMatches}
            needsConfirmation={needsDuplicateConfirmation}
            confirmed={confirmDuplicate}
            onConfirmedChange={setConfirmDuplicate}
            reason={duplicateReason}
            onReasonChange={setDuplicateReason}
            reasonError={fieldErrors.duplicate_reason?.[0]}
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Alternate phone (employee's own)" htmlFor="alternate_phone" error={fieldErrors.alternate_phone?.[0]}>
            <input
              id="alternate_phone"
              className={inputClasses}
              value={form.alternate_phone}
              onChange={(e) => handlePhoneChange('alternate_phone', e.target.value)}
              onBlur={runDuplicateCheck}
            />
          </FormField>
          <FormField label="Reference / guarantor name" htmlFor="reference_name" error={fieldErrors.reference_name?.[0]}>
            <input id="reference_name" className={inputClasses} value={form.reference_name} onChange={(e) => setForm({ ...form, reference_name: e.target.value })} />
          </FormField>
        </div>

        {(mode === 'create' || employee?.payment_account_number !== undefined) && (
          <FormField
            label="Payment / account number (optional)"
            htmlFor="payment_account_number"
            error={fieldErrors.payment_account_number?.[0]}
            hint="Used only to pay this employee. It is not their phone number and is never used to find or match them."
          >
            <input
              id="payment_account_number"
              className={inputClasses}
              value={form.payment_account_number}
              maxLength={50}
              autoComplete="off"
              onChange={(e) => setForm({ ...form, payment_account_number: e.target.value })}
            />
          </FormField>
        )}

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Secondary/emergency contact name" htmlFor="secondary_contact_name" error={fieldErrors.secondary_contact_name?.[0]}>
            <input id="secondary_contact_name" className={inputClasses} value={form.secondary_contact_name} onChange={(e) => setForm({ ...form, secondary_contact_name: e.target.value })} />
          </FormField>
          <FormField label="Secondary/emergency contact phone" htmlFor="secondary_contact_phone" error={fieldErrors.secondary_contact_phone?.[0]}>
            <input id="secondary_contact_phone" className={inputClasses} value={form.secondary_contact_phone} onChange={(e) => setForm({ ...form, secondary_contact_phone: e.target.value })} />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField
            label="Location"
            htmlFor="location"
            required={mode === 'create'}
            hint={mode === 'create' ? 'Residential/home location — used for future Waiting-list matching.' : 'May be left blank — some migrated employees have no location on record.'}
            error={fieldErrors.location?.[0]}
          >
            <input id="location" required={mode === 'create'} className={inputClasses} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          </FormField>
          <FormField
            label="Gender"
            htmlFor="gender"
            required={mode === 'create'}
            hint={mode === 'edit' ? 'May be left unset — some migrated employees have no gender on record.' : undefined}
            error={fieldErrors.gender?.[0]}
          >
            <Select
              id="gender"
              value={form.gender}
              onChange={(e) => setForm({ ...form, gender: e.target.value as EmployeeGender })}
              placeholder="Select gender"
              options={GENDER_OPTIONS}
            />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Age" htmlFor="age" error={fieldErrors.age?.[0]}>
            <input id="age" type="number" min={14} max={100} className={inputClasses} value={form.age} onChange={(e) => setForm({ ...form, age: e.target.value })} />
          </FormField>
          <FormField label="Marital status" htmlFor="marital_status" error={fieldErrors.marital_status?.[0]}>
            <input id="marital_status" className={inputClasses} value={form.marital_status} onChange={(e) => setForm({ ...form, marital_status: e.target.value })} />
          </FormField>
        </div>

        <FormField label="Lives with" htmlFor="lives_with" error={fieldErrors.lives_with?.[0]}>
          <input id="lives_with" className={inputClasses} value={form.lives_with} onChange={(e) => setForm({ ...form, lives_with: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-3 gap-3">
          <FormField label="Category" htmlFor="employee_category_id" required error={fieldErrors.employee_category_id?.[0]}>
            <Select
              id="employee_category_id"
              value={form.employee_category_id}
              onChange={(e) => setForm({ ...form, employee_category_id: e.target.value })}
              placeholder="Select category"
              options={(categories ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
            />
          </FormField>
          <FormField label="Department" htmlFor="department_id" error={fieldErrors.department_id?.[0]}>
            <Select
              id="department_id"
              value={form.department_id}
              onChange={(e) => setForm({ ...form, department_id: e.target.value })}
              placeholder="Not set"
              options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
            />
          </FormField>
          <FormField label="Position" htmlFor="position_id" error={fieldErrors.position_id?.[0]}>
            <Select
              id="position_id"
              value={form.position_id}
              onChange={(e) => setForm({ ...form, position_id: e.target.value })}
              placeholder="Not set"
              options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
            />
          </FormField>
        </div>

        {mode === 'edit' && (
          <FormField
            label="Category specialization"
            htmlFor="category_specialization"
            hint="Home Team: Work Type (e.g. Full Time – Jiif). Cooking: Specialization (e.g. Cook)."
            error={fieldErrors.category_specialization?.[0]}
          >
            <input
              id="category_specialization"
              className={inputClasses}
              value={form.category_specialization}
              onChange={(e) => setForm({ ...form, category_specialization: e.target.value })}
            />
          </FormField>
        )}

        <div className="grid grid-cols-2 gap-3">
          <FormField
            label="Application date"
            htmlFor="application_date"
            required={mode === 'create'}
            hint={mode === 'edit' ? 'May be left blank — some migrated employees have no application date on record.' : undefined}
            error={fieldErrors.application_date?.[0]}
          >
            <input
              id="application_date"
              type="date"
              required={mode === 'create'}
              className={inputClasses}
              value={form.application_date}
              onChange={(e) => setForm({ ...form, application_date: e.target.value })}
            />
          </FormField>
          {mode === 'edit' && (
            <FormField label="Joining date" htmlFor="joining_date" error={fieldErrors.joining_date?.[0]}>
              <input id="joining_date" type="date" className={inputClasses} value={form.joining_date} onChange={(e) => setForm({ ...form, joining_date: e.target.value })} />
            </FormField>
          )}
        </div>

        <FormField label="Experience" htmlFor="experience" error={fieldErrors.experience?.[0]}>
          <textarea id="experience" rows={2} className={inputClasses + ' h-auto py-2'} value={form.experience} onChange={(e) => setForm({ ...form, experience: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Training fee amount" htmlFor="training_fee_amount" error={fieldErrors.training_fee_amount?.[0]}>
            <input
              id="training_fee_amount"
              type="number"
              min={0}
              step="0.01"
              className={inputClasses}
              value={form.training_fee_amount}
              onChange={(e) => setForm({ ...form, training_fee_amount: e.target.value })}
            />
          </FormField>
          <FormField label="Training fee status" htmlFor="training_fee_status" error={fieldErrors.training_fee_status?.[0]}>
            <input
              id="training_fee_status"
              className={inputClasses}
              value={form.training_fee_status}
              onChange={(e) => setForm({ ...form, training_fee_status: e.target.value })}
            />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Source" htmlFor="source" error={fieldErrors.source?.[0]}>
            <input id="source" className={inputClasses} value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })} />
          </FormField>
          <FormField
            label="Employment type"
            htmlFor="employment_type"
            hint="Temporary employees can cover a Permanent employee's assignment."
            error={fieldErrors.employment_type?.[0]}
          >
            <Select
              id="employment_type"
              value={form.employment_type}
              onChange={(e) => setForm({ ...form, employment_type: e.target.value as EmploymentType })}
              options={EMPLOYMENT_TYPE_OPTIONS}
            />
          </FormField>
        </div>

        <FormField label="Notes" htmlFor="notes" error={fieldErrors.notes?.[0]}>
          <textarea id="notes" rows={2} className={inputClasses + ' h-auto py-2'} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </form>
    </Modal>
  );
}

interface DuplicateWarningsProps {
  phoneMatches: DuplicateEmployeeMatch[];
  nameOnlyMatches: DuplicateEmployeeMatch[];
  needsConfirmation: boolean;
  confirmed: boolean;
  onConfirmedChange: (value: boolean) => void;
  reason: string;
  onReasonChange: (value: string) => void;
  reasonError?: string;
}

function matchDescription(match: DuplicateEmployeeMatch): string {
  const status = match.archived ? 'archived record' : match.status;
  return `${match.full_name} (${match.employee_number}, ${status})`;
}

/**
 * Shows who already has this phone number (blocking once the server confirms it) or
 * the same name (a heads-up only). Links open the existing profile in a new tab so
 * the half-filled form is not lost.
 */
function DuplicateWarnings({
  phoneMatches,
  nameOnlyMatches,
  needsConfirmation,
  confirmed,
  onConfirmedChange,
  reason,
  onReasonChange,
  reasonError,
}: DuplicateWarningsProps) {
  if (phoneMatches.length === 0 && nameOnlyMatches.length === 0) return null;

  return (
    <div className="mb-4 space-y-3">
      {phoneMatches.length > 0 && (
        <div className="rounded-sm border border-danger/40 bg-danger-soft px-3 py-3 text-sm" role="alert">
          <p className="font-semibold text-danger">This phone number is already on record.</p>
          <ul className="mt-1.5 space-y-1">
            {phoneMatches.map((match) => (
              <li key={match.id} className="text-ink">
                This phone number already belongs to {matchDescription(match)}.{' '}
                <Link to={`/hr/employees/${match.id}`} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">
                  Open profile
                </Link>
              </li>
            ))}
          </ul>
          {needsConfirmation ? (
            <div className="mt-3 border-t border-danger/20 pt-3">
              <p className="mb-2 text-xs text-ink-muted">
                Registering the same person twice causes problems. Continue only if this really is a different person who shares the number.
              </p>
              <label className="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" checked={confirmed} onChange={(e) => onConfirmedChange(e.target.checked)} />
                This is a different person
              </label>
              {confirmed && (
                <div className="mt-3">
                  <FormField
                    label="Why is this a different person?"
                    htmlFor="duplicate_reason"
                    required
                    hint="At least 10 characters. This is kept in the audit log."
                    error={reasonError}
                  >
                    <textarea
                      id="duplicate_reason"
                      rows={2}
                      maxLength={500}
                      className={inputClasses + ' h-auto py-2'}
                      value={reason}
                      onChange={(e) => onReasonChange(e.target.value)}
                    />
                  </FormField>
                </div>
              )}
            </div>
          ) : (
            <p className="mt-2 text-xs text-ink-muted">Fix the number, or save and then confirm that this is a different person.</p>
          )}
        </div>
      )}

      {nameOnlyMatches.length > 0 && (
        <div className="rounded-sm border border-warning/40 bg-warning-soft px-3 py-3 text-sm">
          <p className="font-semibold text-warning">Someone with the same name is already registered.</p>
          <ul className="mt-1.5 space-y-1">
            {nameOnlyMatches.map((match) => (
              <li key={match.id} className="text-ink">
                {matchDescription(match)}{' '}
                <Link to={`/hr/employees/${match.id}`} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">
                  Open profile
                </Link>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-ink-muted">Different people can share a name, so this will not stop you from saving.</p>
        </div>
      )}
    </div>
  );
}
