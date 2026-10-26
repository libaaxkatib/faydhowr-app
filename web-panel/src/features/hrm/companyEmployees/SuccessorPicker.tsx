import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { formatDate } from '@/utils/formatters';
import { EMPTY_SUCCESSOR, effectiveStart, type SuccessorDraft } from '@/utils/successor';

/**
 * The replacement choice that is required when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1-N3). Exactly two kinds are offered:
 * a Temporary employee who has been covering this very position, or a person from the HR Waiting List. Nobody is preselected. A Temporary becomes
 * Permanent as soon as this is saved (the record shows it at once) and inherits the departing salary automatically; a Waiting List person is appointed
 * through the normal status workflow and needs the salary HR agrees. Pay and the assignment begin on the EFFECTIVE DATE, which is included: it defaults to
 * the day after the last day, can be set later, and is never moved by the system.
 */
export function SuccessorPicker({
  assignmentId,
  lastDay,
  value,
  onChange,
  error,
  idPrefix = 'successor',
}: {
  assignmentId: number;
  /** The chosen last day of the departing assignment (YYYY-MM-DD), used to show when the replacement starts. */
  lastDay: string;
  value: SuccessorDraft;
  onChange: (next: SuccessorDraft) => void;
  error?: string;
  idPrefix?: string;
}) {
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(timer);
  }, [search]);

  const options = useQuery({
    queryKey: ['hr', 'successor-options', assignmentId, debounced],
    queryFn: () => hrApi.employees.workAssignments.successorOptions(assignmentId, debounced),
    enabled: assignmentId > 0,
  });

  const choose = (type: SuccessorDraft['type']) => onChange({ ...EMPTY_SUCCESSOR, type, effectiveDate: value.effectiveDate, salaryCurrency: value.salaryCurrency || 'USD' });
  const start = effectiveStart(lastDay, value);
  const startText = start !== '' ? formatDate(start) : 'the day after the last day';

  return (
    <fieldset className="mb-4 rounded-sm border border-border p-3" aria-describedby={`${idPrefix}-help`}>
      <legend className="px-1 text-sm font-medium text-ink">Who takes over this Permanent position? <span className="text-danger">*</span></legend>
      <p id={`${idPrefix}-help`} className="mb-3 text-xs text-ink-muted">
        This position cannot be left without a replacement. The replacement&apos;s Permanent assignment and salary start on {startText} (that day is included).
      </p>

      <div className="mb-3 flex flex-col gap-2 text-sm text-ink">
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'temporary'} onChange={() => choose('temporary')} />
          A Temporary employee who has been covering this position
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'waiting_list'} onChange={() => choose('waiting_list')} />
          A person from the HR Waiting List
        </label>
      </div>

      <FormField label="Effective date (first day of the replacement)" htmlFor={`${idPrefix}-effective`} hint="Leave empty for the day after the last day. That day is included in the new salary. It must be after the last day.">
        <input id={`${idPrefix}-effective`} type="date" className={inputClasses} value={value.effectiveDate} onChange={(e) => onChange({ ...value, effectiveDate: e.target.value })} />
      </FormField>

      {value.type === 'temporary' && (
        <FormField label="Temporary employee" htmlFor={`${idPrefix}-temp`} required hint="Becomes Permanent with the same employee record. The salary of the departing employee is inherited automatically; nothing to enter.">
          {options.isLoading ? (
            <p className="text-xs text-ink-faint">Loading…</p>
          ) : (options.data?.temporary.length ?? 0) === 0 ? (
            <p className="text-sm text-ink-muted">No Temporary employee has covered this position. Choose a person from the Waiting List instead.</p>
          ) : (
            <Select
              id={`${idPrefix}-temp`}
              value={value.employeeId === null ? '' : String(value.employeeId)}
              onChange={(e) => onChange({ ...value, employeeId: e.target.value === '' ? null : Number(e.target.value) })}
              placeholder="Choose the Temporary employee"
              options={(options.data?.temporary ?? []).map((t) => ({ value: String(t.employee_id), label: `${t.name} (${t.employee_number}) - covered from ${formatDate(t.cover_start_date)}` }))}
            />
          )}
        </FormField>
      )}

      {value.type === 'waiting_list' && (
        <>
          <FormField label="Search the Waiting List" htmlFor={`${idPrefix}-search`} hint="Type a name or an employee number.">
            <input id={`${idPrefix}-search`} className={inputClasses} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or employee number" autoComplete="off" />
          </FormField>
          <FormField label="Person from the Waiting List" htmlFor={`${idPrefix}-waiting`} required hint="The person is appointed through the normal status workflow; a registration that is not complete is refused.">
            <Select
              id={`${idPrefix}-waiting`}
              value={value.employeeId === null ? '' : String(value.employeeId)}
              onChange={(e) => onChange({ ...value, employeeId: e.target.value === '' ? null : Number(e.target.value) })}
              placeholder={options.isLoading ? 'Loading…' : 'Choose a person'}
              options={(options.data?.waiting_list ?? []).map((w) => ({ value: String(w.employee_id), label: `${w.name} (${w.employee_number})` }))}
            />
          </FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Agreed salary (per month)" htmlFor={`${idPrefix}-salary`} required>
              <input id={`${idPrefix}-salary`} type="number" min="0" step="0.01" className={inputClasses} value={value.salaryAmount} onChange={(e) => onChange({ ...value, salaryAmount: e.target.value })} />
            </FormField>
            <FormField label="Currency" htmlFor={`${idPrefix}-currency`} required>
              <input id={`${idPrefix}-currency`} maxLength={3} className={inputClasses} value={value.salaryCurrency} onChange={(e) => onChange({ ...value, salaryCurrency: e.target.value.toUpperCase() })} />
            </FormField>
          </div>
        </>
      )}

      {error && <p className="mt-1 text-xs text-danger" role="alert">{error}</p>}
    </fieldset>
  );
}
