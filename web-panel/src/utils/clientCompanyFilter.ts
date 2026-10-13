// Pure helpers for the Companies & Locations category filter (no React, no network, no `@/` imports) so the
// filter and its counts can be unit-tested by Node's native test runner (clientCompanyFilter.test.ts).
//
// The filter reads the record's own `category` field ('company' | 'guri'); it never guesses from the name.
// Counts come from the same list the page renders, so the number on a button always equals the rows it shows.

export type CompanyCategoryFilter = 'all' | 'company' | 'guri';

export const CATEGORY_FILTERS: { value: CompanyCategoryFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'company', label: 'Company' },
  { value: 'guri', label: 'Guri' },
];

interface HasCategory {
  category: string;
}

/** Reads `?category=` from the URL. Anything unknown means "all" so a bad link never hides records. */
export function parseCategoryFilter(search: string | URLSearchParams): CompanyCategoryFilter {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const value = params.get('category');

  return value === 'company' || value === 'guri' ? value : 'all';
}

export function filterByCategory<T extends HasCategory>(companies: readonly T[], filter: CompanyCategoryFilter): T[] {
  return filter === 'all' ? [...companies] : companies.filter((company) => company.category === filter);
}

export function categoryCounts(companies: readonly HasCategory[]): Record<CompanyCategoryFilter, number> {
  return {
    all: companies.length,
    company: companies.filter((company) => company.category === 'company').length,
    guri: companies.filter((company) => company.category === 'guri').length,
  };
}

/** The Fayadhowr Office is an internal location, never a client: it must not appear in the client list or its counts. */
export function isInternalOffice(entry: { location_type?: string | null }): boolean {
  return entry.location_type === 'office';
}
