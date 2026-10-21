// Pure helpers for the "+ Create Position" / "+ Create Category" shortcuts (no React, no network, no `@/` imports) so Node's test runner can
// import them. The server stays the source of truth for duplicates; the panel only tidies the name and catches the obvious cases early.

/** Tidy a typed name the way the server does: trim and collapse repeated spaces. */
export function tidyName(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

/** Case- and space-insensitive key used to spot an existing entry with the same name. */
export function nameKey(value: string): string {
  return tidyName(value).toLowerCase();
}

export function validateNewName(value: string, existing: readonly { name: string }[], noun: string): string | null {
  const name = tidyName(value);
  if (name === '') return `Enter the ${noun} name.`;
  if (name.length > 100) return `The ${noun} name is too long (100 characters at most).`;
  if (existing.some((entry) => nameKey(entry.name) === nameKey(name))) return `A ${noun} called "${name}" already exists. Pick it from the list instead.`;

  return null;
}
