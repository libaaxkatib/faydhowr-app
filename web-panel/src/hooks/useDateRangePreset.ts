import { useUrlParamUpdater } from '@/hooks/useUrlParamUpdater';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { parseIsoDate, parsePreset, withPreset, withTypedDate, type DateRangePreset } from '@/utils/dateRangePreset';

export type { DateRangePreset };

const PRESET_OPTIONS: { value: DateRangePreset; labelKey: MessageKey }[] = [
  { value: 'today', labelKey: 'option.common.preset.today' },
  { value: 'this_week', labelKey: 'option.common.preset.thisWeek' },
  { value: 'this_month', labelKey: 'option.common.preset.thisMonth' },
  { value: 'previous_month', labelKey: 'option.common.preset.previousMonth' },
  { value: 'custom', labelKey: 'option.common.preset.custom' },
];

/**
 * Shared date-range + preset for the HR report filter bars. The range is kept in the address bar (preset, from, to), so a reload, the Back button and a
 * shared link bring the same report back; choosing a preset or typing a date also returns the list to its first page. Custom keeps the typed dates.
 */
export function useDateRangePreset() {
  const { t } = useI18n();
  const [params, update] = useUrlParamUpdater();

  return {
    preset: parsePreset(params.get('preset')),
    from: parseIsoDate(params.get('from')),
    to: parseIsoDate(params.get('to')),
    setPreset: (next: DateRangePreset) => update((previous) => withPreset(previous, next)),
    setFrom: (value: string) => update((previous) => withTypedDate(previous, 'from', value)),
    setTo: (value: string) => update((previous) => withTypedDate(previous, 'to', value)),
    options: PRESET_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) })),
  };
}
