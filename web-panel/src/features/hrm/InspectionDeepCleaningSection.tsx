import { FormField, inputClasses } from '@/components/ui/FormField';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { DEEP_CLEANING_REASON_MIN, facilityToday, formatCalendarDate, type DeepCleaningFormState } from '@/utils/facility';
import type { FacilityDeepCleaningDetail } from '@/types/facility';

interface Props {
  form: DeepCleaningFormState;
  onChange: (next: DeepCleaningFormState) => void;
  /** What the saved inspection already says (editing only). */
  saved: FacilityDeepCleaningDetail | null;
  problems: string[];
  showErrors: boolean;
}

const CHOICES: { value: DeepCleaningFormState['choice']; labelKey: MessageKey }[] = [
  { value: 'none', labelKey: 'option.hr.dcNotRecordedChoice' },
  { value: 'completed', labelKey: 'option.hr.dcCompletedChoice' },
];

/**
 * The "Deep Cleaning" section of the inspection form. Every facility should have one at least once a calendar month, but an inspection never
 * assumes it: the default is "Not recorded" and saving an ordinary inspection changes nothing. A completion is recorded only when it is chosen
 * here, with its own date. A completion that is already saved can only be changed or withdrawn with a reason, which stays in the audit history.
 */
export function InspectionDeepCleaningSection({ form, onChange, saved, problems, showErrors }: Props) {
  const { t } = useI18n();
  const counted = saved !== null && !saved.withdrawn;
  const changed = counted && (form.choice === 'none' || form.completedOn !== saved.completed_on || form.notes.trim() !== (saved.notes ?? '').trim());
  const reasonNeeded = changed || (saved?.withdrawn === true && form.choice === 'completed');

  return (
    <section className="mb-5 rounded-md border border-border" aria-labelledby="deep-cleaning-heading" data-testid="deep-cleaning-section">
      <div className="border-b border-border bg-surface-alt/50 px-3.5 py-2.5">
        <h3 id="deep-cleaning-heading" className="font-display text-sm font-bold text-ink">{t('option.hr.deepCleaning')}</h3>
        <p className="text-xs text-ink-muted">{t('option.hr.dcSectionHint')}</p>
      </div>
      <div className="px-3.5 py-3">
        {counted && (
          <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-saved">
            {saved.recorded_by
              ? t('option.hr.dcSavedBy', { date: formatCalendarDate(saved.completed_on), name: saved.recorded_by })
              : t('option.hr.dcSaved', { date: formatCalendarDate(saved.completed_on) })}
            {saved.changed_by
              ? ` ${saved.change_reason ? t('option.hr.dcChangedByReason', { name: saved.changed_by, reason: saved.change_reason }) : t('option.hr.dcChangedBy', { name: saved.changed_by })}`
              : ''}
          </p>
        )}
        {saved?.withdrawn && (
          <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-withdrawn">
            {t('warning.hr.dcWithdrawn', { date: formatCalendarDate(saved.completed_on), reason: saved.withdrawn_reason ? `: ${saved.withdrawn_reason}` : '' })}
          </p>
        )}

        <div role="radiogroup" aria-label={t('option.hr.deepCleaning')} className="flex flex-wrap gap-2">
          {CHOICES.map((choice) => (
            <button
              key={choice.value}
              type="button"
              role="radio"
              aria-checked={form.choice === choice.value}
              onClick={() => onChange({ ...form, choice: choice.value })}
              className={`rounded-full border px-3 py-1.5 text-sm font-medium transition ${
                form.choice === choice.value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-ink-muted hover:border-primary/40'
              }`}
            >
              {t(choice.labelKey)}
            </button>
          ))}
        </div>

        {form.choice === 'completed' && (
          <div className="mt-3 grid grid-cols-1 gap-x-3 sm:grid-cols-2">
            <FormField label={t('option.hr.dcDateCompleted')} htmlFor="deep-cleaning-date" required hint={t('option.hr.dcDateCompletedHint')}>
              <input id="deep-cleaning-date" type="date" max={facilityToday()} className={inputClasses} value={form.completedOn} onChange={(e) => onChange({ ...form, completedOn: e.target.value })} />
            </FormField>
            <FormField label={t('option.hr.dcNotes')} htmlFor="deep-cleaning-notes" hint={t('option.hr.optionalDot')}>
              <input id="deep-cleaning-notes" className={inputClasses} value={form.notes} onChange={(e) => onChange({ ...form, notes: e.target.value })} />
            </FormField>
          </div>
        )}

        {reasonNeeded && (
          <FormField
            label={form.choice === 'none' ? t('option.hr.dcWhyWithdrawn') : t('option.hr.dcWhyChanged')}
            htmlFor="deep-cleaning-reason"
            required
            hint={t('option.hr.dcReasonHint', { min: DEEP_CLEANING_REASON_MIN })}
          >
            <input id="deep-cleaning-reason" className={inputClasses} value={form.changeReason} onChange={(e) => onChange({ ...form, changeReason: e.target.value })} />
          </FormField>
        )}

        {showErrors && problems.length > 0 && (
          <p role="alert" className="text-xs text-danger" data-testid="deep-cleaning-problems">{problems.join(' ')}</p>
        )}
      </div>
    </section>
  );
}
