// Pure rules behind the report date-range buttons (no React, no `@/` imports) so Node's test runner can import them. The range lives in the address bar
// (preset, from, to), so a reload, the Back button and a shared link bring the same report back.

export type DateRangePreset = 'today' | 'this_week' | 'this_month' | 'previous_month' | 'custom';

export const DATE_RANGE_KEYS = ['preset', 'from', 'to'] as const;

const PRESETS: readonly DateRangePreset[] = ['today', 'this_week', 'this_month', 'previous_month', 'custom'];

/** Formats using LOCAL date parts, not `toISOString()`: that converts to UTC first and shifts local midnight back a day for any positive UTC offset (Somalia is UTC+3). */
export function toDateString(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

/** {from, to} for a preset, or null for "custom" (which keeps whatever dates were typed). */
export function computeRange(preset: DateRangePreset, now: Date = new Date()): { from: string; to: string } | null {
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

  switch (preset) {
    case 'today': {
      const today = startOfDay(now);

      return { from: toDateString(today), to: toDateString(today) };
    }
    case 'this_week': {
      const today = startOfDay(now);
      const start = new Date(today);
      start.setDate(today.getDate() - today.getDay());

      return { from: toDateString(start), to: toDateString(today) };
    }
    case 'this_month':
      return { from: toDateString(new Date(now.getFullYear(), now.getMonth(), 1)), to: toDateString(startOfDay(now)) };
    case 'previous_month':
      return { from: toDateString(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: toDateString(new Date(now.getFullYear(), now.getMonth(), 0)) };
    case 'custom':
      return null;
  }
}

/** An unknown preset in the address bar means "custom", so a bad link never hides records. */
export function parsePreset(value: string | null): DateRangePreset {
  return PRESETS.includes(value as DateRangePreset) ? (value as DateRangePreset) : 'custom';
}

/** A real calendar date written YYYY-MM-DD, otherwise empty (a hand-edited link cannot send nonsense to the server). */
export function parseIsoDate(value: string | null): string {
  if (value === null || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';
  const date = new Date(`${value}T00:00:00`);

  return Number.isNaN(date.getTime()) || toDateString(date) !== value ? '' : value;
}

/** The query string after choosing a preset: its range replaces from/to (custom keeps them); the page number goes back to 1; other parameters stay. */
export function withPreset(current: URLSearchParams, preset: DateRangePreset, now: Date = new Date()): URLSearchParams {
  const next = new URLSearchParams(current);
  const range = computeRange(preset, now);

  next.set('preset', preset);
  if (range) {
    next.set('from', range.from);
    next.set('to', range.to);
  }
  next.delete('page');

  return next;
}

/** The query string after typing a date: the preset becomes "custom" (it no longer describes the range); empty removes the key; the page returns to 1. */
export function withTypedDate(current: URLSearchParams, key: 'from' | 'to', value: string): URLSearchParams {
  const next = new URLSearchParams(current);

  next.set('preset', 'custom');
  if (value === '') next.delete(key);
  else next.set(key, value);
  next.delete('page');

  return next;
}
