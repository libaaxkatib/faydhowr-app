import { useState } from 'react';

import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';

export type DateRangePreset = 'today' | 'this_week' | 'this_month' | 'previous_month' | 'custom';

const PRESET_OPTIONS: { value: DateRangePreset; labelKey: MessageKey }[] = [
  { value: 'today', labelKey: 'option.common.preset.today' },
  { value: 'this_week', labelKey: 'option.common.preset.thisWeek' },
  { value: 'this_month', labelKey: 'option.common.preset.thisMonth' },
  { value: 'previous_month', labelKey: 'option.common.preset.previousMonth' },
  { value: 'custom', labelKey: 'option.common.preset.custom' },
];

/**
 * Formats using LOCAL date parts, not `toISOString()` - that converts to UTC
 * first, which shifts local midnight back a calendar day for any positive
 * UTC offset (e.g. Somalia, UTC+3, this app's actual deployment timezone).
 */
function toDateString(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/** Computes {from, to} for a preset - frontend-only convenience, no backend change (see HRM Phase 6 plan §9). */
function computeRange(preset: DateRangePreset): { from: string; to: string } | null {
  const now = new Date();
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

  switch (preset) {
    case 'today': {
      const today = startOfDay(now);
      return { from: toDateString(today), to: toDateString(today) };
    }
    case 'this_week': {
      const today = startOfDay(now);
      const dayOfWeek = today.getDay();
      const start = new Date(today);
      start.setDate(today.getDate() - dayOfWeek);
      return { from: toDateString(start), to: toDateString(today) };
    }
    case 'this_month': {
      const start = new Date(now.getFullYear(), now.getMonth(), 1);
      return { from: toDateString(start), to: toDateString(startOfDay(now)) };
    }
    case 'previous_month': {
      const start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const end = new Date(now.getFullYear(), now.getMonth(), 0);
      return { from: toDateString(start), to: toDateString(end) };
    }
    case 'custom':
      return null;
  }
}

/** Shared date-range + preset state for HR report filter bars. Custom keeps whatever from/to the user typed. */
export function useDateRangePreset() {
  const { t } = useI18n();
  const [preset, setPresetState] = useState<DateRangePreset>('custom');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  function setPreset(next: DateRangePreset) {
    setPresetState(next);
    const range = computeRange(next);
    if (range) {
      setFrom(range.from);
      setTo(range.to);
    }
  }

  function setCustomFrom(value: string) {
    setPresetState('custom');
    setFrom(value);
  }

  function setCustomTo(value: string) {
    setPresetState('custom');
    setTo(value);
  }

  return { preset, setPreset, from, to, setFrom: setCustomFrom, setTo: setCustomTo, options: PRESET_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) })) };
}
