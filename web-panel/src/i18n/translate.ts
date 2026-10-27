// Pure translation lookup (no React, no `@/` imports).
//
//  - A Somali message that is missing or empty falls back to its English text; an unknown key shows the key itself, so it is noticed.
//  - `{name}` placeholders are replaced by plain string substitution. The value is inserted as text (React escapes it), a placeholder with no
//    value is left as written, and a value can never inject another placeholder or a replacement pattern.
//  - Plurals: a message with two forms uses the keys `<base>.one` and `<base>.other`.

import type { Catalogue } from './catalog.ts';
import type { Language } from './language.ts';

export type Params = Readonly<Record<string, string | number>>;

export function interpolate(template: string, params?: Params): string {
  if (!params) return template;

  return template.replace(/\{([A-Za-z0-9_]+)\}/g, (whole, name: string) => (Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole));
}

export function lookup(catalogue: Catalogue, language: Language, key: string): string {
  const entry = catalogue[key];
  if (!entry) return key;
  if (language === 'so' && typeof entry.so === 'string' && entry.so.trim() !== '') return entry.so;

  return entry.en;
}

export function translate(catalogue: Catalogue, language: Language, key: string, params?: Params): string {
  return interpolate(lookup(catalogue, language, key), params);
}

/** Which plural form a count uses. Both languages have "one" (exactly 1) and "other". */
export function pluralCategory(language: Language, count: number): 'one' | 'other' {
  try {
    return new Intl.PluralRules(language).select(count) === 'one' ? 'one' : 'other';
  } catch {
    return count === 1 ? 'one' : 'other';
  }
}

/**
 * The Somali label of a stored value (a status, a stage, a leave type...) under `<prefix>.<value>`, or undefined. Undefined in English and when the
 * catalogue has no entry, so the caller keeps showing exactly what the screen showed before (`label={tValue('status', value)}` on a badge that used to
 * show the raw value).
 */
export function translateValue(catalogue: Catalogue, language: Language, prefix: string, value: string): string | undefined {
  if (language !== 'so') return undefined;
  const entry = catalogue[`${prefix}.${value}`];
  if (!entry || typeof entry.so !== 'string' || entry.so.trim() === '') return undefined;

  return entry.so;
}

export function translatePlural(catalogue: Catalogue, language: Language, base: string, count: number, params?: Params): string {
  const preferred = `${base}.${pluralCategory(language, count)}`;
  const key = catalogue[preferred] ? preferred : `${base}.other`;

  return translate(catalogue, language, key, { n: count, ...params });
}
