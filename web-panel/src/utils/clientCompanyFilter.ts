// Pure helpers for the Companies & Workplaces category filter (no React, no network, no `@/` imports) so the
// filter and its counts can be unit-tested by Node's native test runner (clientCompanyFilter.test.ts).
//
// The filter reads the record's own `category` field ('company' | 'guri'); it never guesses from the name.
// Counts come from the same list the page renders, so the number on a button always equals the rows it shows.

import type { MessageKey } from '../i18n/messages/index.ts';

export type CompanyCategoryFilter = 'all' | 'company' | 'guri';

export const CATEGORY_FILTERS: { value: CompanyCategoryFilter; label: string; labelKey: MessageKey }[] = [
  { value: 'all', label: 'All', labelKey: 'option.company.filterAll' },
  { value: 'company', label: 'Company', labelKey: 'option.companyCategory.company' },
  { value: 'guri', label: 'Guri', labelKey: 'option.company.filterGuri' },
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

/** Other spellings worth showing under a company name: an alias that is just the name again (ignoring upper/lower case) adds nothing. A different spacing ("5GHOTEL" for "5G HOTEL") is a real alias and stays visible. */
export function visibleAliases(name: string, aliases: readonly { alias: string }[] | undefined): string[] {
  const key = (value: string) => value.trim().replace(/\s+/g, ' ').toLowerCase();
  const seen = new Set<string>([key(name)]);
  const out: string[] = [];

  for (const { alias } of aliases ?? []) {
    if (!seen.has(key(alias))) {
      seen.add(key(alias));
      out.push(alias);
    }
  }

  return out;
}

// ------------------------------------------------------------------ search and filters of the Companies & Workplaces list (2026-10-29)
// Everything below reads and writes the address bar, so a reload, a shared link and the browser Back button bring the list back exactly as it was.

export type CompanyStatusFilter = '' | 'active' | 'inactive';
export type CompanyWorkplaceFilter = '' | 'with' | 'without';

/** The address-bar keys of this list's filters (the page number is handled separately). `q` is the search text. */
export const COMPANY_FILTER_KEYS = ['q', 'category', 'status', 'workplaces'] as const;

export interface CompanyListFilters {
  search: string;
  category: CompanyCategoryFilter;
  status: CompanyStatusFilter;
  workplaces: CompanyWorkplaceFilter;
}

/** Reads the filters from the address bar; an unknown value counts as "no filter", so a bad link never hides records. */
export function parseCompanyListFilters(params: URLSearchParams): CompanyListFilters {
  const status = params.get('status');
  const workplaces = params.get('workplaces');

  return {
    search: (params.get('q') ?? '').replace(/\s+/g, ' ').trimStart(),
    category: parseCategoryFilter(params),
    status: status === 'active' || status === 'inactive' ? status : '',
    workplaces: workplaces === 'with' || workplaces === 'without' ? workplaces : '',
  };
}

/** The query sent to the server: only the filters that are set, trimmed, together with the page. Every filter applies at once. */
export function companyListQuery(filters: CompanyListFilters, page: number, perPage: number): Record<string, string | number> {
  const query: Record<string, string | number> = { page, per_page: perPage };
  const search = filters.search.trim();

  if (search !== '') query.search = search;
  if (filters.category !== 'all') query.category = filters.category;
  if (filters.status !== '') query.status = filters.status;
  if (filters.workplaces !== '') query.workplaces = filters.workplaces;

  return query;
}

/** Is any search word or filter active? (Decides between "there are no records" and "no records match".) */
export function hasActiveCompanyFilters(filters: CompanyListFilters): boolean {
  return filters.search.trim() !== '' || filters.category !== 'all' || filters.status !== '' || filters.workplaces !== '';
}

export interface CompanyListMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  counts: Record<CompanyCategoryFilter, number>;
  unfiltered_total: number;
}
