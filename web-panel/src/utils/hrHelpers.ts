// Pure helpers for the HR screens: no React, no network and no `@/` alias imports,
// so they can be unit-tested directly by Node's native test runner (hrHelpers.test.ts).

import { getActiveLanguage, tNow } from '../i18n/active.ts';
import type { MessageKey } from '../i18n/messages/index.ts';

export const OFFICE_LABEL = 'Fayadhowr Office';

interface AssignmentLike {
  location_type?: 'client' | 'office' | null;
  client_company_name?: string | null;
  work_location_name?: string | null;
}

/** The Fayadhowr Office in the language the HR screen is showing (the stored name stays `OFFICE_LABEL`). */
export function officeLabel(): string {
  return tNow('option.pipeline.fayadhowrOffice');
}

/** "Fayadhowr Office", "Company · Workplace", or just "Company" while the workplace is not set yet. */
export function assignmentLabel(assignment: AssignmentLike): string {
  if (assignment.location_type === 'office') return officeLabel();
  const parts = [assignment.client_company_name, assignment.work_location_name].filter((part): part is string => Boolean(part));
  return parts.length > 0 ? parts.join(' · ') : tNow('option.pipeline.companyNotRecorded');
}

/** True for a company assignment that still has no workplace (HR may set one, once). */
export function isWorkplaceMissing(assignment: { location_type?: 'client' | 'office' | null; work_location_id?: number | null }): boolean {
  return assignment.location_type !== 'office' && (assignment.work_location_id === null || assignment.work_location_id === undefined);
}

/**
 * The server's error codes that have plain-language wording for HR users. The wording itself (English and Somali) lives in the translation
 * catalogue under `notify.error.<CODE>`; anything not listed here falls back to the server's own message.
 */
const ERROR_CODES: ReadonlySet<string> = new Set([
  'LOCATION_COMPANY_MISMATCH',
  'OFFICE_ASSIGNMENT_HAS_NO_COMPANY',
  'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY',
  'EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION',
  'WORK_LOCATION_CAPACITY_EXCEEDED',
  'ASSIGNMENT_LOCATION_ALREADY_SET',
  'PAYROLL_PERIOD_LOCKED',
  'SALARY_BEFORE_ASSIGNMENT_START',
  'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY',
  'COVERED_EMPLOYEE_NOT_PERMANENT',
  'EMPLOYEE_CANNOT_REPLACE_SELF',
  'EMPLOYEE_NOT_ACTIVE',
  'REPLACEMENT_ALREADY_APPROVED',
  'WORKDAY_IN_FUTURE',
  'WORKDAY_OUTSIDE_REPLACEMENT_WINDOW',
  'WORKDAY_OUTSIDE_ASSIGNMENT',
  'WORKDAY_NO_PERMANENT_SALARY',
  'WORKDAY_ALREADY_CHARGED_FOR_POSITION',
  'EMPLOYEE_ALREADY_RECORDED_THAT_DAY',
  'PAYROLL_RUN_LOCKED',
  'PAYROLL_RUN_EMPTY',
  'APPROVER_MUST_DIFFER_FROM_REVIEWER',
  'PAYROLL_RUN_NOT_FINALIZED',
  'ADJUSTMENT_PERIOD_NOT_LATER',
  'ADJUSTMENT_PERIOD_LOCKED',
  'PAYROLL_ITEM_ALREADY_REVERSED',
  'PAYROLL_ITEM_ALREADY_PAID',
  'LEAVE_OVERLAPS_UNPAID_LEAVE',
  'APPROVER_MUST_DIFFER_FROM_RECORDER',
  'SUCCESSION_OVERLAP',
  'SUCCESSION_PREDECESSOR_STILL_OPEN',
  'SUCCESSION_ALREADY_REPLACED',
  'SUCCESSION_DIFFERENT_COMPANY',
  'SUCCESSION_SAME_EMPLOYEE',
  'SUCCESSION_PREDECESSOR_CANCELLED',
  'LEAVE_ALREADY_APPROVED',
  'LEAVE_ALREADY_REJECTED',
]);

/**
 * Server error codes whose English wording comes from the server itself (it names the people, dates and amounts involved), so English keeps showing
 * the server's own message exactly as before. Only when the panel is in Somali do these codes show the plain-language Somali sentence from the
 * catalogue (`notify.error.<CODE>`), which cannot name the person but says what is wrong.
 */
export const SOMALI_WORDING_CODES: ReadonlySet<string> = new Set([
  'SUCCESSOR_TYPE_INVALID',
  'SUCCESSOR_LAST_DAY_BEFORE_START',
  'SUCCESSOR_NOT_FOUND',
  'SUCCESSOR_SAME_EMPLOYEE',
  'SUCCESSOR_EFFECTIVE_DATE_INVALID',
  'SUCCESSOR_NOT_TEMPORARY',
  'SUCCESSOR_NOT_COVERING',
  'SUCCESSOR_NOT_WAITING',
  'SUCCESSOR_NO_SALARY_TO_INHERIT',
  'SUCCESSOR_SALARY_REQUIRED',
  'SUCCESSOR_SALARY_FREQUENCY_INVALID',
  'TEMPORARY_REGISTERED_AS_REPLACEMENT',
  'START_DATE_PASSES_SALARY_CHANGE',
  'REPLACEMENT_BEFORE_ASSIGNMENT_START',
  'REPLACEMENT_NEEDS_REPLACED_ASSIGNMENT',
  'REPLACEMENT_NO_SALARY_BASIS',
  'REPLACEMENT_SALARY_IS_INHERITED',
  'REPLACEMENT_SAME_EMPLOYEE',
  'RETURN_BEFORE_REPLACEMENT_STARTED',
  'RETURN_NOT_A_REPLACEMENT',
  'RETURN_NO_ORIGINAL',
  'RETURN_SALARY_REQUIRED',
  'STOP_BEFORE_ASSIGNMENT_START',
  'USE_REPLACE_EMPLOYEE',
  'ASSIGNMENT_NOT_ACTIVE',
  'ASSIGNMENT_CANCELLED',
  'WORK_ASSIGNMENT_ALREADY_CANCELLED',
  'WORK_ASSIGNMENT_HAS_PAYROLL',
  'WORK_ASSIGNMENT_HAS_REPLACEMENTS',
  'WORKDAY_ALREADY_VOID',
  'WORKDAY_NOT_VERIFIABLE',
  'WORKDAY_DATES_REQUIRED',
  'TEMPORARY_REPLACEMENT_NOT_ACTIVE',
  'EXPECTED_PAYMENT_NO_SALARY',
  'PAYROLL_UNSUPPORTED_SALARY',
  'PAYROLL_UNRESOLVED_TEMPORARY_REPLACEMENT',
  'PAYROLL_RUN_NOT_REOPENABLE',
  'PAYROLL_RUN_WRONG_STATE',
  'PAYROLL_ITEM_NOTHING_TO_PAY',
  'PAYROLL_ITEM_NOT_PAID',
  'PAYROLL_ITEM_VOIDED',
  'PAYROLL_PAYMENT_VOID_NOT_ALLOWED',
  'PAYROLL_CORRECTION_NOT_ALLOWED',
  'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY',
  'PAYMENT_ALREADY_VOIDED',
  'PAYMENT_LEDGER_ENTRY_MISSING',
  'TRANSACTION_ALREADY_VOIDED',
  'TRANSACTION_NOT_VOIDABLE',
  'CORRECTION_WRONG_KIND',
  'VOID_REASON_REQUIRED',
  'WRITE_OFF_REASON_REQUIRED',
  'ADJUSTMENT_AMOUNT_REQUIRED',
  'AMOUNT_EXCEEDS_OUTSTANDING',
  'NO_PAYMENT_RECORDS',
  'DEEP_CLEANING_CHANGE_REASON_REQUIRED',
  'DEEP_CLEANING_ALREADY_RECORDED',
  'WORKPLACE_NOT_OF_COMPANY',
]);

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

  return typeof message === 'string' && message.length > 0 ? message : tNow('confirm.errors.additionalCompany');
}

/** A message safe to show an HR user: our plain-language wording for known codes, otherwise the server's message, otherwise `fallback`. */
export function friendlyErrorMessage(error: unknown, fallback: string): string {
  const code = errorCodeOf(error);
  if (code && ERROR_CODES.has(code)) return tNow(`notify.error.${code}` as MessageKey);
  if (code && SOMALI_WORDING_CODES.has(code) && getActiveLanguage() === 'so') return tNow(`notify.error.${code}` as MessageKey);
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
  if (rate.trim() !== '' && Number.isFinite(numeric)) return tNow('option.common.perDay', { amount: '$' + numeric.toFixed(2) });
  return rate;
}
