// Dates and months for the language being shown. English keeps the exact browser output it always had (en-US); Somali uses Somali month names
// and a 24-hour clock. Pure functions with no `@/` imports, so Node's test runner can run them. The Somali month names are proposed wording.

import type { Language } from './language.ts';

export const SOMALI_MONTHS = ['Janaayo', 'Febraayo', 'Maarso', 'Abriil', 'Maajo', 'Juun', 'Luuliyo', 'Agoosto', 'Sebtembar', 'Oktoobar', 'Nofembar', 'Desembar'] as const;
export const SOMALI_MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Abr', 'Maj', 'Jun', 'Lul', 'Ago', 'Seb', 'Okt', 'Nof', 'Des'] as const;

/** "October 2026" / "Oktoobar 2026". `monthIndex` is 0-11. */
export function monthYearLabel(language: Language, year: number, monthIndex: number): string {
  if (language === 'so') return `${SOMALI_MONTHS[monthIndex]} ${year}`;
  return new Date(year, monthIndex, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
}

/** "Oct 1, 2026" / "1 Okt 2026". */
export function mediumDate(language: Language, date: Date): string {
  if (language === 'so') return `${date.getDate()} ${SOMALI_MONTHS_SHORT[date.getMonth()]} ${date.getFullYear()}`;
  return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date);
}

/** "Oct 1, 2026, 3:45 PM" / "1 Okt 2026, 15:45". */
export function mediumDateTime(language: Language, date: Date): string {
  if (language === 'so') {
    const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date);
    return `${mediumDate(language, date)}, ${time}`;
  }
  return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
