// Pure helpers for the Employees list filters that live in the URL (no React, no network, no `@/` imports) so Node's test runner can import
// them. Keeping every filter in the address bar means a reload, a shared link and the browser Back button (coming back from an employee's page)
// all restore exactly what the HR user had on screen.

export type TriState = '' | 'true' | 'false';

export interface ExtraEmployeeFilters {
  search: string;
  departmentId: string;
  location: string;
  profileComplete: TriState;
  guarantorNeeded: TriState;
  isSupervisor: TriState;
  /** Show only the archived people (the working lists never include them). */
  archived: boolean;
  joiningDateFrom: string;
  joiningDateTo: string;
  page: number;
}

export const EXTRA_FILTER_KEYS = [
  'q',
  'department_id',
  'location',
  'profile_complete',
  'guarantor_needed',
  'is_supervisor',
  'archived',
  'joining_date_from',
  'joining_date_to',
  'page',
] as const;

/** The filters tucked under "More filters" - the first row keeps search, status, category and department. */
const MORE_FILTER_KEYS = ['location', 'profile_complete', 'guarantor_needed', 'is_supervisor', 'joining_date_from', 'joining_date_to', 'application_date_from', 'application_date_to'] as const;

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function tri(value: string | null): TriState {
  return value === 'true' || value === 'false' ? value : '';
}

export function parseExtraEmployeeFilters(search: string | URLSearchParams): ExtraEmployeeFilters {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const department = params.get('department_id');
  const page = Number(params.get('page'));

  return {
    search: params.get('q') ?? '',
    departmentId: department !== null && /^\d+$/.test(department) ? department : '',
    location: params.get('location') ?? '',
    profileComplete: tri(params.get('profile_complete')),
    guarantorNeeded: tri(params.get('guarantor_needed')),
    isSupervisor: tri(params.get('is_supervisor')),
    archived: params.get('archived') === 'true',
    joiningDateFrom: DATE.test(params.get('joining_date_from') ?? '') ? (params.get('joining_date_from') as string) : '',
    joiningDateTo: DATE.test(params.get('joining_date_to') ?? '') ? (params.get('joining_date_to') as string) : '',
    page: Number.isInteger(page) && page >= 1 ? page : 1,
  };
}

/** How many of the collapsed filters are set - shown on the "More filters" button so a hidden filter is never a surprise. */
export function activeMoreFilterCount(search: string | URLSearchParams): number {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;

  return MORE_FILTER_KEYS.filter((key) => (params.get(key) ?? '') !== '').length;
}

/** Every filter the page owns, so "Clear filters" removes all of them and nothing else. */
export const ALL_FILTER_KEYS = [
  ...EXTRA_FILTER_KEYS,
  'status',
  'employee_category_id',
  'application_date_from',
  'application_date_to',
  'client_company_only',
] as const;

export function activeFilterCount(search: string | URLSearchParams): number {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;

  return ALL_FILTER_KEYS.filter((key) => key !== 'page' && (params.get(key) ?? '') !== '').length;
}
