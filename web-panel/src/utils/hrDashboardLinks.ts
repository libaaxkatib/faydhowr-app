// Pure helpers for the clickable HR Dashboard cards (no React, no network, no `@/` imports) so the
// destination of EVERY card can be unit-tested by Node's native test runner (hrDashboardLinks.test.ts).
//
// Rule: a card only links somewhere that really applies the filter it promises. Destinations are either a
// dedicated page that is already locked to the right population (Waiting, Former Employees, Supervisor Pool,
// Office Staff) or a page that reads the query parameters produced here (Employees, Workforce Requests,
// Temporary Replacements). The backend counts these cards show are asserted equal to the filtered lists in
// HrDashboardCardDestinationsTest.php.

import { tNow } from '../i18n/active.ts';

export const EMPLOYEE_STATUS_VALUES = ['applicant', 'recruitment', 'practical', 'waiting', 'approved', 'active', 'inactive'] as const;
export type EmployeeStatusValue = (typeof EMPLOYEE_STATUS_VALUES)[number];

export const WORKFORCE_REQUEST_STATUS_VALUES = ['open', 'partially_filled', 'fulfilled', 'cancelled'] as const;
export type WorkforceRequestStatusValue = (typeof WORKFORCE_REQUEST_STATUS_VALUES)[number];

/** Dashboard "Open" = open + partially filled, exactly as GetHrDashboardAction counts it. */
export const OPEN_REQUEST_STATUSES: readonly WorkforceRequestStatusValue[] = ['open', 'partially_filled'];

export const REPLACEMENT_STATUS_VALUES = ['active', 'ended', 'expired'] as const;
export type ReplacementStatusValue = (typeof REPLACEMENT_STATUS_VALUES)[number];

export const NEW_APPLICANT_WINDOW_DAYS = 30;

export type DashboardCardKey =
  | 'total_employees'
  | 'active_employees'
  | 'inactive_employees'
  | 'new_applicants'
  | 'practical'
  | 'waiting'
  | 'approved'
  | 'supervisor_pool'
  | 'active_replacements'
  | 'expired_replacements'
  | 'office_staff'
  | 'client_company_employees'
  | 'open_workforce_requests'
  | 'fulfilled_workforce_requests';

/** YYYY-MM-DD of `now` minus `days`, in UTC - the server's own timezone (config/app.php), so the window matches the card count. */
export function utcDateDaysAgo(now: Date, days: number): string {
  const start = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()) - days * 86_400_000;

  return new Date(start).toISOString().slice(0, 10);
}

function withQuery(path: string, params: Record<string, string>): string {
  const query = new URLSearchParams(params).toString();

  return query === '' ? path : `${path}?${query}`;
}

/** Where a dashboard card goes. `now` is injectable so the "last 30 days" link can be tested. */
export function dashboardCardTo(key: DashboardCardKey, now: Date = new Date()): string {
  switch (key) {
    case 'total_employees':
      return '/hr/employees';
    case 'active_employees':
      return withQuery('/hr/employees', { status: 'active' });
    case 'inactive_employees':
      return '/hr/former-employees'; // already locked to status=inactive
    case 'new_applicants':
      return withQuery('/hr/employees', { status: 'applicant', application_date_from: utcDateDaysAgo(now, NEW_APPLICANT_WINDOW_DAYS) });
    case 'practical':
      return withQuery('/hr/employees', { status: 'practical' });
    case 'waiting':
      return '/hr/waiting'; // already locked to status=waiting
    case 'approved':
      return withQuery('/hr/employees', { status: 'approved' });
    case 'supervisor_pool':
      return '/hr/supervisor-pool'; // already locked to is_supervisor=true
    case 'active_replacements':
      return withQuery('/hr/temporary-replacements', { status: 'active' });
    case 'expired_replacements':
      return withQuery('/hr/temporary-replacements', { status: 'expired' });
    case 'office_staff':
      return '/hr/office-staff'; // already locked to office_only=true
    case 'client_company_employees':
      return withQuery('/hr/employees', { client_company_only: 'true' });
    case 'open_workforce_requests':
      return withQuery('/hr/workforce-requests', { status: 'open' });
    case 'fulfilled_workforce_requests':
      return withQuery('/hr/workforce-requests', { status: 'fulfilled' });
  }
}

/** Category Breakdown tile -> the Employees list for that category. */
export function categoryTileTo(categoryId: number): string {
  return withQuery('/hr/employees', { employee_category_id: String(categoryId) });
}

/**
 * Label of a Category Breakdown tile. The "Office Staff" CATEGORY tile counts employees who carry that category, while the
 * "Office Staff" card above counts active office ASSIGNMENTS, so the two can differ. The tile says "(Category)" so they are
 * never mistaken for each other. Every other category keeps its own name.
 */
export function categoryTileLabel(name: string): string {
  return name.trim().toLowerCase() === 'office staff' ? tNow('option.hr.officeStaffCategory') : name;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function validDate(value: string | null): string {
  if (value === null || !ISO_DATE.test(value)) return '';
  const parsed = new Date(`${value}T00:00:00Z`);

  return Number.isNaN(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== value ? '' : value;
}

export interface EmployeeListUrlFilters {
  status: EmployeeStatusValue | '';
  employeeCategoryId: string;
  applicationDateFrom: string;
  applicationDateTo: string;
  clientCompanyOnly: boolean;
}

/** Reads the Employees list filters from the URL. Anything unknown or malformed is dropped, never half-applied. */
export function parseEmployeeListUrl(search: string | URLSearchParams): EmployeeListUrlFilters {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const status = params.get('status');
  const category = params.get('employee_category_id');

  return {
    status: (EMPLOYEE_STATUS_VALUES as readonly string[]).includes(status ?? '') ? (status as EmployeeStatusValue) : '',
    employeeCategoryId: category !== null && /^\d+$/.test(category) ? category : '',
    applicationDateFrom: validDate(params.get('application_date_from')),
    applicationDateTo: validDate(params.get('application_date_to')),
    clientCompanyOnly: params.get('client_company_only') === 'true',
  };
}

/** `?status=open` on Workforce Requests means open + partially filled; any other known status means itself. */
export function parseWorkforceRequestStatuses(search: string | URLSearchParams): WorkforceRequestStatusValue[] | null {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const status = params.get('status');
  if (status === 'open') return [...OPEN_REQUEST_STATUSES];

  return (WORKFORCE_REQUEST_STATUS_VALUES as readonly string[]).includes(status ?? '') ? [status as WorkforceRequestStatusValue] : null;
}

export function parseReplacementStatus(search: string | URLSearchParams): ReplacementStatusValue | null {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const status = params.get('status');

  return (REPLACEMENT_STATUS_VALUES as readonly string[]).includes(status ?? '') ? (status as ReplacementStatusValue) : null;
}
