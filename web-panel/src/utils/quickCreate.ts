// Pure helpers for the "+ Create Position" / "+ Create Category" shortcuts (no React, no network, no `@/` imports) so Node's test runner can
// import them. The server stays the source of truth for duplicates; the panel only tidies the name and catches the obvious cases early.

import { tNow } from '../i18n/active.ts';

/** Tidy a typed name the way the server does: trim and collapse repeated spaces. */
export function tidyName(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

/** Case- and space-insensitive key used to spot an existing entry with the same name. */
export function nameKey(value: string): string {
  return tidyName(value).toLowerCase();
}

/** The two nouns the callers pass in English are shown in the language of the screen; any other noun is used as given. */
function nounText(noun: string): string {
  if (noun === 'position') return tNow('option.company.nounPosition');
  if (noun === 'category') return tNow('option.company.nounCategory');

  return noun;
}

export function validateNewName(value: string, existing: readonly { name: string }[], noun: string): string | null {
  const name = tidyName(value);
  if (name === '') return tNow('validation.company.enterName', { noun: nounText(noun) });
  if (name.length > 100) return tNow('validation.company.nameTooLong', { noun: nounText(noun) });
  if (existing.some((entry) => nameKey(entry.name) === nameKey(name))) return tNow('validation.company.nameExists', { noun: nounText(noun), name });

  return null;
}
