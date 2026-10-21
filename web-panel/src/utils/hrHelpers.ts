// Pure helpers for the HR screens: no React, no network and no `@/` alias imports,
// so they can be unit-tested directly by Node's native test runner (hrHelpers.test.ts).

export const OFFICE_LABEL = 'Fayadhowr Office';

interface AssignmentLike {
  location_type?: 'client' | 'office' | null;
  client_company_name?: string | null;
  work_location_name?: string | null;
}

/** "Fayadhowr Office", "Company · Workplace", or just "Company" while the workplace is not set yet. */
export function assignmentLabel(assignment: AssignmentLike): string {
  if (assignment.location_type === 'office') return OFFICE_LABEL;
  const parts = [assignment.client_company_name, assignment.work_location_name].filter((part): part is string => Boolean(part));
  return parts.length > 0 ? parts.join(' · ') : 'Company not recorded';
}

/** True for a company assignment that still has no workplace (HR may set one, once). */
export function isWorkplaceMissing(assignment: { location_type?: 'client' | 'office' | null; work_location_id?: number | null }): boolean {
  return assignment.location_type !== 'office' && (assignment.work_location_id === null || assignment.work_location_id === undefined);
}

/** Plain-language replacements for the server's error codes that HR users meet most. Anything else falls back to the server's own message. */
const ERROR_MESSAGES: Record<string, string> = {
  LOCATION_COMPANY_MISMATCH: 'That workplace belongs to a different company. Choose a workplace that belongs to the selected company.',
  OFFICE_ASSIGNMENT_HAS_NO_COMPANY: 'The Fayadhowr Office is not a client company, so it cannot be linked to one. Choose the office on its own.',
  EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY:
    'This employee is already working for this company. End the current assignment first if they are moving.',
  EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION:
    'This employee is already working at this workplace. End the current assignment first if they are moving.',
  WORK_LOCATION_CAPACITY_EXCEEDED: 'This workplace is already full. Choose another workplace, or ask a Super Admin to override the limit.',
  ASSIGNMENT_LOCATION_ALREADY_SET:
    'This assignment already has a workplace. Moving someone to a different workplace is a new assignment.',
  PAYROLL_PERIOD_LOCKED:
    "That month's payroll is already approved or finalized, so this cannot be changed. Ask for a payroll adjustment instead.",
  SALARY_BEFORE_ASSIGNMENT_START: 'The new salary cannot start before the assignment itself started. Choose a later effective date.',
  REPLACEMENT_EMPLOYEE_NOT_TEMPORARY: 'The person doing the cover must be a Temporary employee. Change their employment type first.',
  COVERED_EMPLOYEE_NOT_PERMANENT: 'The person being covered must be a Permanent employee.',
  EMPLOYEE_CANNOT_REPLACE_SELF: 'An employee cannot cover their own assignment.',
  EMPLOYEE_NOT_ACTIVE: 'Both employees must be Active. Make the employee Active first.',
  REPLACEMENT_ALREADY_APPROVED: 'This replacement has already been approved.',
  WORKDAY_IN_FUTURE: 'You cannot record a day that has not happened yet.',
  WORKDAY_OUTSIDE_REPLACEMENT_WINDOW: 'One of the days falls outside the dates this replacement covers.',
  WORKDAY_OUTSIDE_ASSIGNMENT: 'One of the days falls outside the covered assignment.',
  WORKDAY_NO_PERMANENT_SALARY: 'The covered employee had no salary on one of those days, so the day cannot be charged.',
  WORKDAY_ALREADY_CHARGED_FOR_POSITION:
    'Another temporary employee is already charged for the same position on one of those days. A position is only charged once per day.',
  EMPLOYEE_ALREADY_RECORDED_THAT_DAY: 'This temporary employee already has a recorded day on one of those dates.',
  PAYROLL_RUN_LOCKED: 'This month is already approved or finalized and cannot be regenerated. Reopen it first if it is only approved.',
  PAYROLL_RUN_EMPTY: 'There are no payroll lines to review for this month.',
  APPROVER_MUST_DIFFER_FROM_REVIEWER: 'A different person must approve this payroll. The person who marked it reviewed cannot approve it.',
  PAYROLL_RUN_NOT_FINALIZED: 'Adjustments and payments can only be recorded on finalized payroll.',
  ADJUSTMENT_PERIOD_NOT_LATER: 'An adjustment must apply in a later month than the one it corrects.',
  ADJUSTMENT_PERIOD_LOCKED: 'That later month is already approved or finalized. Choose a month after it.',
  PAYROLL_ITEM_ALREADY_REVERSED: 'This payroll line has already been reversed.',
  PAYROLL_ITEM_ALREADY_PAID: 'This payroll line is already marked as paid.',
  LEAVE_OVERLAPS_UNPAID_LEAVE: 'This employee already has unpaid leave on some of those dates. Change the dates or reject the other leave first.',
  APPROVER_MUST_DIFFER_FROM_RECORDER: 'A different person must approve this leave. The person who recorded it cannot approve it.',
  SUCCESSION_OVERLAP: 'That would pay two people for the same calendar day. The replacement must start the day after the last paid day of the previous employee.',
  SUCCESSION_PREDECESSOR_STILL_OPEN: 'Record the separation of the previous employee (or end their assignment) first, so their last paid day is known.',
  SUCCESSION_ALREADY_REPLACED: 'Someone already takes over this assignment.',
  SUCCESSION_DIFFERENT_COMPANY: 'The assignment being replaced belongs to a different company.',
  SUCCESSION_SAME_EMPLOYEE: 'An employee cannot take over their own assignment.',
  SUCCESSION_PREDECESSOR_CANCELLED: 'The assignment being replaced was cancelled.',
  LEAVE_ALREADY_APPROVED: 'This leave is already approved.',
  LEAVE_ALREADY_REJECTED: 'This leave is already rejected.',
};

interface ErrorLike {
  code?: unknown;
  message?: unknown;
}

/** The server's error code, if the error came from the API. */
export function errorCodeOf(error: unknown): string | undefined {
  const code = (error as ErrorLike | null | undefined)?.code;
  return typeof code === 'string' ? code : undefined;
}

/** The server's code when an employee already works for another company and adding a second one needs an explicit "yes". */
export const ADDITIONAL_COMPANY_CODE = 'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED';

/**
 * The question to put to the HR user ("X is currently assigned to A. Do you want to assign them to B as well?") when the server asked for
 * confirmation of an additional company; null for any other error. The wording names the employee, the current and the new company.
 */
export function additionalCompanyQuestion(error: unknown): string | null {
  if (errorCodeOf(error) !== ADDITIONAL_COMPANY_CODE) return null;
  const message = (error as ErrorLike | null | undefined)?.message;

  return typeof message === 'string' && message.length > 0 ? message : 'This employee is already assigned to another company. Do you want to assign them to this company as well?';
}

/** A message safe to show an HR user: our plain-language wording for known codes, otherwise the server's message, otherwise `fallback`. */
export function friendlyErrorMessage(error: unknown, fallback: string): string {
  const code = errorCodeOf(error);
  if (code && ERROR_MESSAGES[code]) return ERROR_MESSAGES[code];
  // A generic "The given data was invalid." tells HR nothing - show the first specific field message instead.
  if (code === undefined || code === 'VALIDATION_ERROR') {
    const fieldErrors = (error as { fieldErrors?: Record<string, unknown> } | null | undefined)?.fieldErrors;
    const firstFieldMessage = Object.values(fieldErrors ?? {})
      .flat()
      .find((value): value is string => typeof value === 'string' && value.length > 0);
    if (firstFieldMessage) return firstFieldMessage;
  }
  const message = (error as ErrorLike | null | undefined)?.message;
  return typeof message === 'string' && message.length > 0 ? message : fallback;
}

export interface DuplicateMatchLike {
  id: number;
  employee_number: string;
  full_name: string;
  status: string;
  archived: boolean;
  matched_on: ('phone' | 'name')[];
}

function isDuplicateMatch(value: unknown): value is DuplicateMatchLike {
  if (typeof value !== 'object' || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return typeof candidate.id === 'number' && typeof candidate.full_name === 'string' && Array.isArray(candidate.matched_on);
}

/**
 * The 422 DUPLICATE_EMPLOYEE_PHONE answer carries the matching people in
 * `errors.matches`. The API client keeps `errors` as `fieldErrors`; returns the
 * matches, or null when this error is not a duplicate-phone answer.
 */
export function duplicateMatchesFromError(error: unknown): DuplicateMatchLike[] | null {
  if (errorCodeOf(error) !== 'DUPLICATE_EMPLOYEE_PHONE') return null;
  const errors = (error as { fieldErrors?: Record<string, unknown> }).fieldErrors;
  const matches = errors?.matches;
  return Array.isArray(matches) ? matches.filter(isDuplicateMatch) : [];
}

/** Today in the user's own time zone as YYYY-MM-DD (toISOString would give the UTC day). */
export function localToday(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

/** The current month as YYYY-MM in the user's own time zone. */
export function localMonth(now: Date = new Date()): string {
  return localToday(now).slice(0, 7);
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Every date from `from` to `to`, inclusive, as YYYY-MM-DD. Empty when the range is invalid, backwards or longer than `maxDays`. */
export function expandDateRange(from: string, to: string, maxDays = 62): string[] {
  if (!ISO_DATE.test(from) || !ISO_DATE.test(to)) return [];
  const start = Date.UTC(Number(from.slice(0, 4)), Number(from.slice(5, 7)) - 1, Number(from.slice(8, 10)));
  const end = Date.UTC(Number(to.slice(0, 4)), Number(to.slice(5, 7)) - 1, Number(to.slice(8, 10)));
  if (Number.isNaN(start) || Number.isNaN(end) || end < start) return [];
  const dayMs = 86_400_000;
  const count = Math.round((end - start) / dayMs) + 1;
  if (count > maxDays) return [];
  return Array.from({ length: count }, (_, index) => new Date(start + index * dayMs).toISOString().slice(0, 10));
}

/** The first day of the month after `period` (YYYY-MM), as YYYY-MM. */
export function nextMonth(period: string): string {
  const [year, month] = period.split('-').map(Number);
  const date = new Date(Date.UTC(year, month, 1));
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** A daily rate such as '10.0000' becomes '$10.00 per day'; text such as 'varies...' is shown as it is. */
export function describeDailyRate(rate: string): string {
  const numeric = Number(rate);
  if (rate.trim() !== '' && Number.isFinite(numeric)) return `$${numeric.toFixed(2)} per day`;
  return rate;
}
