import { getActiveLanguage, tNow, tNowCount } from '../i18n/active.ts';
import { mediumDate, mediumDateTime } from '../i18n/dates.ts';

export function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-US').format(value);
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  return mediumDate(getActiveLanguage(), new Date(value));
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  return mediumDateTime(getActiveLanguage(), new Date(value));
}

/** Humanizes a past ISO timestamp as "X days"/"X months" - used for Waiting duration, not a precise elapsed-time display. */
export function formatDurationSince(value: string | null | undefined): string {
  if (!value) return '—';
  const days = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / (1000 * 60 * 60 * 24)));
  if (days === 0) return tNow('status.common.durationToday');
  if (days < 30) return tNowCount('status.common.durationDays', days);
  return tNowCount('status.common.durationMonths', Math.floor(days / 30));
}

export function initialsOf(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
}
