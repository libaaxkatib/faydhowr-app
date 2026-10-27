import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/useI18n';
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
  const { t } = useI18n();
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
  const startText = start !== '' ? formatDate(start) : t('option.hr.dayAfterLastDay');

  return (
    <fieldset className="mb-4 rounded-sm border border-border p-3" aria-describedby={`${idPrefix}-help`}>
      <legend className="px-1 text-sm font-medium text-ink">{t('option.hr.successorQuestion')} <span className="text-danger">*</span></legend>
      <p id={`${idPrefix}-help`} className="mb-3 text-xs text-ink-muted">
        {t('option.hr.successorHelp', { start: startText })}
      </p>

      <div className="mb-3 flex flex-col gap-2 text-sm text-ink">
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'temporary'} onChange={() => choose('temporary')} />
          {t('option.hr.successorTemporaryChoice')}
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" name={`${idPrefix}-type`} checked={value.type === 'waiting_list'} onChange={() => choose('waiting_list')} />
          {t('option.hr.successorWaitingChoice')}
        </label>
      </div>

      <FormField label={t('option.hr.successorEffectiveDate')} htmlFor={`${idPrefix}-effective`} hint={t('option.hr.successorEffectiveDateHint')}>
        <input id={`${idPrefix}-effective`} type="date" className={inputClasses} value={value.effectiveDate} onChange={(e) => onChange({ ...value, effectiveDate: e.target.value })} />
      </FormField>

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

      {value.type === 'waiting_list' && (
        <>
          <FormField label={t('option.hr.searchWaitingList')} htmlFor={`${idPrefix}-search`} hint={t('option.hr.typeNameOrNumber')}>
            <input id={`${idPrefix}-search`} className={inputClasses} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('option.hr.nameOrEmployeeNumber')} autoComplete="off" />
          </FormField>
          <FormField label={t('option.hr.personFromWaitingList')} htmlFor={`${idPrefix}-waiting`} required hint={t('option.hr.waitingAppointHint')}>
            <Select
              id={`${idPrefix}-waiting`}
              value={value.employeeId === null ? '' : String(value.employeeId)}
              onChange={(e) => onChange({ ...value, employeeId: e.target.value === '' ? null : Number(e.target.value) })}
              placeholder={options.isLoading ? t('option.label.loading') : t('option.hr.choosePerson')}
              options={(options.data?.waiting_list ?? []).map((w) => ({ value: String(w.employee_id), label: `${w.name} (${w.employee_number})` }))}
            />
          </FormField>
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
