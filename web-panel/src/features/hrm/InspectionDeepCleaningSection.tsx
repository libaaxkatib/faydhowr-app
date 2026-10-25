import { FormField, inputClasses } from '@/components/ui/FormField';
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

const CHOICES: { value: DeepCleaningFormState['choice']; label: string }[] = [
  { value: 'none', label: 'Not recorded in this inspection' },
  { value: 'completed', label: 'Deep Cleaning completed' },
];

/**
 * The "Deep Cleaning" section of the inspection form. Every facility should have one at least once a calendar month, but an inspection never
 * assumes it: the default is "Not recorded" and saving an ordinary inspection changes nothing. A completion is recorded only when it is chosen
 * here, with its own date. A completion that is already saved can only be changed or withdrawn with a reason, which stays in the audit history.
 */
export function InspectionDeepCleaningSection({ form, onChange, saved, problems, showErrors }: Props) {
  const counted = saved !== null && !saved.withdrawn;
  const changed = counted && (form.choice === 'none' || form.completedOn !== saved.completed_on || form.notes.trim() !== (saved.notes ?? '').trim());
  const reasonNeeded = changed || (saved?.withdrawn === true && form.choice === 'completed');

  return (
    <section className="mb-5 rounded-md border border-border" aria-labelledby="deep-cleaning-heading" data-testid="deep-cleaning-section">
      <div className="border-b border-border bg-surface-alt/50 px-3.5 py-2.5">
        <h3 id="deep-cleaning-heading" className="font-display text-sm font-bold text-ink">Deep Cleaning</h3>
        <p className="text-xs text-ink-muted">Each facility should be deep cleaned at least once every calendar month. Record it here only if it was actually completed.</p>
      </div>
      <div className="px-3.5 py-3">
        {counted && (
          <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-saved">
            Recorded: completed on {formatCalendarDate(saved.completed_on)}
            {saved.recorded_by ? ` by ${saved.recorded_by}` : ''}.
            {saved.changed_by ? ` Last changed by ${saved.changed_by}${saved.change_reason ? ` (${saved.change_reason})` : ''}.` : ''}
          </p>
        )}
        {saved?.withdrawn && (
          <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-withdrawn">
            A Deep Cleaning recorded on {formatCalendarDate(saved.completed_on)} was withdrawn{saved.withdrawn_reason ? `: ${saved.withdrawn_reason}` : ''}. It is kept on record but not counted.
          </p>
        )}

        <div role="radiogroup" aria-label="Deep Cleaning" className="flex flex-wrap gap-2">
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
              {choice.label}
            </button>
          ))}
        </div>

        {form.choice === 'completed' && (
          <div className="mt-3 grid grid-cols-1 gap-x-3 sm:grid-cols-2">
            <FormField label="Date completed" htmlFor="deep-cleaning-date" required hint="The day the Deep Cleaning was done (Mogadishu date), not the inspection date. It cannot be in the future.">
              <input id="deep-cleaning-date" type="date" max={facilityToday()} className={inputClasses} value={form.completedOn} onChange={(e) => onChange({ ...form, completedOn: e.target.value })} />
            </FormField>
            <FormField label="Deep Cleaning notes" htmlFor="deep-cleaning-notes" hint="Optional.">
              <input id="deep-cleaning-notes" className={inputClasses} value={form.notes} onChange={(e) => onChange({ ...form, notes: e.target.value })} />
            </FormField>
          </div>
        )}

        {reasonNeeded && (
          <FormField
            label={form.choice === 'none' ? 'Why is the recorded Deep Cleaning being withdrawn?' : 'Why is the Deep Cleaning record being changed?'}
            htmlFor="deep-cleaning-reason"
            required
            hint={`At least ${DEEP_CLEANING_REASON_MIN} characters. The earlier values stay in the audit history with this reason.`}
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
