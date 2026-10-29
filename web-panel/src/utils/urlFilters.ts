// Pure helpers for list state that lives in the address bar (no React, no `@/` imports) so Node's test runner can import them.
// A list whose search, filters and page are in the URL comes back exactly as it was left after Back, a reload or a shared link.

/** The page number from the address bar: a positive whole number, otherwise page 1 (a bad link never breaks the list). */
export function pageFromParam(value: string | null): number {
  const page = Number(value);

  return Number.isInteger(page) && page >= 1 ? page : 1;
}

/**
 * The next query string after changing one key. An empty value removes the key; changing anything except the page itself sends the list back to
 * page 1 (a new search must not land on page 7 of the old one). Other parameters are kept untouched.
 */
export function withParam(current: URLSearchParams, key: string, value: string): URLSearchParams {
  const next = new URLSearchParams(current);

  if (value === '') next.delete(key);
  else next.set(key, value);

  if (key !== 'page') next.delete('page');

  return next;
}

/** Where a detail screen's "Back" button returns to: the list as it was (path, search, filters and page) plus its label. */
export function returnState(pathname: string, search: string, label: string): { fromPath: string; fromLabel: string } {
  return { fromPath: `${pathname}${search}`, fromLabel: label };
}

/** How many of the given filter keys currently hold a value (the page number never counts as a filter). Used for "Reset filters (3)". */
export function activeFilterCount(current: URLSearchParams, keys: readonly string[]): number {
  return keys.filter((key) => key !== 'page' && (current.get(key) ?? '') !== '').length;
}

/** The query string after clearing the given filter keys and the page number; every other parameter is kept as it is. */
export function withoutFilters(current: URLSearchParams, keys: readonly string[]): URLSearchParams {
  const next = new URLSearchParams(current);
  keys.forEach((key) => next.delete(key));
  next.delete('page');

  return next;
}
