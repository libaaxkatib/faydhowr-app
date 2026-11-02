import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/useI18n';
import type { MutatingWorkflowId, RecoveryOverview, RecoveryParams } from '@/types/recovery';
import { monthBounds, WORKFLOW_FIELDS } from '@/utils/recovery';

/** The inputs of the chosen correction. Date inputs are limited to the chosen month: a correction never reaches another one. */
export function WorkflowForm({ workflow, overview, params, onChange }: { workflow: MutatingWorkflowId; overview: RecoveryOverview; params: RecoveryParams; onChange: (next: RecoveryParams) => void }) {
  const { t } = useI18n();
  const fields = WORKFLOW_FIELDS[workflow];

  if (fields.length === 0) return null;

  const set = (field: keyof RecoveryParams, value: string) => onChange({ ...params, [field]: value });
  const bounds = monthBounds(params.month ?? '');
  const dateProps = { type: 'date', min: bounds?.min, max: bounds?.max, className: inputClasses } as const;

  return (
    <div className="mt-4 grid gap-x-4 md:grid-cols-2">
      <FormField label={t('option.recovery.month')} htmlFor="recovery-month" required>
        <input id="recovery-month" type="month" className={inputClasses} value={params.month ?? ''} onChange={(event) => onChange({ ...params, month: event.target.value })} />
      </FormField>

      {fields.includes('absence_id') && (
        <FormField label={t('option.recovery.absence')} htmlFor="recovery-absence" required>
          <Select
            id="recovery-absence"
            value={params.absence_id ?? ''}
            onChange={(event) => set('absence_id', event.target.value)}
            placeholder={t('option.recovery.absence')}
            options={overview.absences.map((x) => ({ value: String(x.id), label: `#${x.id} · ${x.last_working_day} → ${x.returned_on ?? t('option.recovery.open')}` }))}
          />
        </FormField>
      )}

      {fields.includes('start_date') && (
        <FormField label={t('option.recovery.startDate')} htmlFor="recovery-start" hint={overview.assignment.start_date}>
          <input id="recovery-start" {...dateProps} value={params.start_date ?? ''} onChange={(event) => set('start_date', event.target.value)} />
        </FormField>
      )}

      {fields.includes('end_date') && (
        <FormField label={t('option.recovery.endDate')} htmlFor="recovery-end" hint={overview.assignment.end_date ?? t('option.recovery.open')}>
          <input id="recovery-end" {...dateProps} value={params.end_date ?? ''} onChange={(event) => set('end_date', event.target.value)} />
        </FormField>
      )}

      {fields.includes('last_working_day') && (
        <FormField label={t('option.recovery.lastWorkingDay')} htmlFor="recovery-last-day">
          <input id="recovery-last-day" {...dateProps} value={params.last_working_day ?? ''} onChange={(event) => set('last_working_day', event.target.value)} />
        </FormField>
      )}

      {fields.includes('returned_on') && (
        <FormField label={t('option.recovery.returnedOn')} htmlFor="recovery-returned">
          <input id="recovery-returned" {...dateProps} value={params.returned_on ?? ''} onChange={(event) => set('returned_on', event.target.value)} />
        </FormField>
      )}
    </div>
  );
}
