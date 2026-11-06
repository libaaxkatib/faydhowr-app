import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/useI18n';
import { formatDate } from '@/utils/formatters';
import { EMPTY_SUCCESSOR, effectiveStart, type SuccessorDraft } from '@/utils/successor';

/**
 * The replacement choice that is required when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1-N3, amended 2026-11-02). Two kinds are
 * offered: a Temporary employee who has been covering this very position, or ANY other person in the HR system, found by name or employee number (the
 * Waiting List is only a suggestion, not a requirement). Nobody is preselected. A Temporary becomes Permanent as soon as this is saved and inherits the
 * departing salary automatically; any other person needs the salary HR agrees, and if they have not completed the registration steps the appointment still
 * goes ahead and is recorded for the HR compliance report. Pay and the assignment begin on the EFFECTIVE DATE, which is included: it defaults to
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
  const { t, tValue } = useI18n();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [chosen, setChosen] = useState<{ id: number; label: string } | null>(null);

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
  // A search shows everyone who matches; before anything is typed the Waiting List is offered as a shortlist (a suggestion, not a requirement).
  const personOptions = (debounced === '' ? (options.data?.waiting_list ?? []).map((w) => ({ value: String(w.employee_id), label: `${w.name} (${w.employee_number})` })) : (options.data?.people ?? []).map((p) => ({
    value: String(p.employee_id),
    label: t('option.hr.successorPersonLabel', {
      name: p.name,
      number: p.employee_number,
      status: tValue('status', p.status) ?? p.status,
      type: tValue('option.employmentType', p.employment_type) ?? p.employment_type,
      details: [p.category, p.phone_hint, p.current_assignments.length > 0 ? t('option.hr.successorWorksAt', { places: p.current_assignments.join(', ') }) : null].filter(Boolean).map((x) => `, ${x}`).join(''),
    }),
  })));
  // The person already chosen stays in the list while the search term changes (the select would otherwise go blank and lose the choice).
  const shownOptions = chosen !== null && value.employeeId === chosen.id && !personOptions.some((o) => o.value === String(chosen.id)) ? [{ value: String(chosen.id), label: chosen.label }, ...personOptions] : personOptions;
  const start = effectiveStart(lastDay, value);
  const startText = start !== '' ? formatDate(start) : t('option.hr.dayAfterLastDay');

  return (
    <fieldset className="mb-4 rounded-sm border border-border p-3" aria-describedby={`${idPrefix}-help`}>
      <legend className="px-1 text-sm font-medium text-ink">{t('option.hr.successorQuestionOptional')}</legend>
      <p id={`${idPrefix}-help`} className="mb-3 text-xs text-ink-muted">
        {t('option.hr.successorHelpOptional', { start: startText })}
      </p>

      <div className="mb-3 flex flex-col gap-2 text-sm text-ink">
        {/* The replacement is OPTIONAL (owner decision 2026-11-04): this choice puts the picker back to "nobody chosen", so a half-made choice never blocks Save. */}
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} data-testid={`${idPrefix}-none`} checked={value.type === ''} onChange={() => onChange(EMPTY_SUCCESSOR)} />
          {t('option.hr.successorNone')}
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'temporary'} onChange={() => choose('temporary')} />
          {t('option.hr.successorTemporaryChoice')}
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'person' || value.type === 'waiting_list'} onChange={() => choose('person')} />
          {t('option.hr.successorWaitingChoice')}
        </label>
      </div>

      {value.type !== '' && (
        <FormField label={t('option.hr.successorEffectiveDate')} htmlFor={`${idPrefix}-effective`} hint={t('option.hr.successorEffectiveDateHint')}>
          <input id={`${idPrefix}-effective`} type="date" className={inputClasses} value={value.effectiveDate} onChange={(e) => onChange({ ...value, effectiveDate: e.target.value })} />
        </FormField>
      )}

      {value.type === 'temporary' && (
        <FormField label={t('option.pipeline.temporaryEmployeeFallback')} htmlFor={`${idPrefix}-temp`} required hint={t('option.hr.successorTemporaryHint')}>
          {options.isLoading ? (
            <p className="text-xs text-ink-faint">{t('option.label.loading')}</p>
          ) : (options.data?.temporary.length ?? 0) === 0 ? (
            <p className="text-sm text-ink-muted">{t('option.hr.successorNoTemporary')}</p>
          ) : (
            <Select
              id={`${idPrefix}-temp`}
              value={value.employeeId === null ? '' : String(value.employeeId)}
              onChange={(e) => onChange({ ...value, employeeId: e.target.value === '' ? null : Number(e.target.value) })}
              placeholder={t('option.hr.chooseTemporaryEmployee')}
              options={(options.data?.temporary ?? []).map((candidate) => ({ value: String(candidate.employee_id), label: t('option.hr.successorCoveredFrom', { name: candidate.name, number: candidate.employee_number, date: formatDate(candidate.cover_start_date) }) }))}
            />
          )}
        </FormField>
      )}

      {(value.type === 'person' || value.type === 'waiting_list') && (
        <>
          <FormField label={t('option.hr.searchWaitingList')} htmlFor={`${idPrefix}-search`} hint={t('option.hr.typeNameOrNumber')}>
            <input id={`${idPrefix}-search`} className={inputClasses} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('option.hr.nameOrEmployeeNumber')} autoComplete="off" />
          </FormField>
          <FormField label={t('option.hr.personFromWaitingList')} htmlFor={`${idPrefix}-waiting`} required hint={t('option.hr.waitingAppointHint')}>
            <Select
              id={`${idPrefix}-waiting`}
              value={value.employeeId === null ? '' : String(value.employeeId)}
              onChange={(e) => {
                const id = e.target.value === '' ? null : Number(e.target.value);
                setChosen(id === null ? null : { id, label: personOptions.find((o) => o.value === e.target.value)?.label ?? String(id) });
                onChange({ ...value, employeeId: id });
              }}
              placeholder={options.isLoading ? t('option.label.loading') : t('option.hr.choosePerson')}
              options={shownOptions}
            />
          </FormField>
          {debounced === '' && personOptions.length > 0 && <p className="-mt-2 mb-3 text-xs text-ink-faint">{t('option.hr.successorShortlist')}</p>}
          {debounced !== '' && !options.isLoading && personOptions.length === 0 && <p className="-mt-2 mb-3 text-xs text-ink-muted">{t('option.hr.successorNoPeople')}</p>}
          <div className="grid grid-cols-2 gap-3">
            <FormField label={t('option.hr.agreedSalaryPerMonth')} htmlFor={`${idPrefix}-salary`} required>
              <input id={`${idPrefix}-salary`} type="number" min="0" step="0.01" className={inputClasses} value={value.salaryAmount} onChange={(e) => onChange({ ...value, salaryAmount: e.target.value })} />
            </FormField>
            <FormField label={t('confirm.company.currency')} htmlFor={`${idPrefix}-currency`} required>
              <input id={`${idPrefix}-currency`} maxLength={3} className={inputClasses} value={value.salaryCurrency} onChange={(e) => onChange({ ...value, salaryCurrency: e.target.value.toUpperCase() })} />
            </FormField>
          </div>
        </>
      )}

      {error && <p className="mt-1 text-xs text-danger" role="alert">{error}</p>}
    </fieldset>
  );
}
