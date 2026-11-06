// Pure helpers for the Marketing Reports period (no React, no `@/` imports). The presets only fill the two date boxes with ordinary dates; they add no
// rule, and the server filters by the dates exactly as it does for dates typed by hand.

export type ReportPreset = 'today' | 'last7' | 'last30' | 'thisMonth';

/** The local calendar date as YYYY-MM-DD (not toISOString, which is UTC and can be a day off). */
export function localIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

export function reportPresetRange(preset: ReportPreset, today: Date): { from: string; to: string } {
  const to = localIsoDate(today);
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());

  if (preset === 'today') return { from: to, to };
  if (preset === 'last7') start.setDate(start.getDate() - 6);
  else if (preset === 'last30') start.setDate(start.getDate() - 30);
  else start.setDate(1);

  return { from: localIsoDate(start), to };
}
