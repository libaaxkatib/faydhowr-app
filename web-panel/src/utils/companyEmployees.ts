/**
 * Pure helpers of the Company Employee Management screen: month handling, money by currency, and the client-side checks that save a
 * round trip. The server validates everything again and is the authority; nothing here decides who may do what.
 */

import { getActiveLanguage, tNow, tValueNow } from '../i18n/active.ts';
import { monthYearLabel } from '../i18n/dates.ts';
import type { MessageKey } from '../i18n/messages/index.ts';

export type AssignmentTypeValue = 'permanent' | 'replacement';

export const ASSIGNMENT_TYPE_LABELS: Record<AssignmentTypeValue, string> = { permanent: 'Permanent', replacement: 'Replacement' };

const ASSIGNMENT_TYPE_KEYS: Record<AssignmentTypeValue, MessageKey> = { permanent: 'option.assignmentType.permanent', replacement: 'option.assignmentType.replacement' };

/** The label of an assignment type in the language the HR screen is showing. */
export function assignmentTypeLabel(type: AssignmentTypeValue): string {
  return tNow(ASSIGNMENT_TYPE_KEYS[type]);
}

export const ASSIGNMENT_TYPE_OPTIONS: { value: string; label: string; labelKey: MessageKey }[] = [
  { value: 'permanent', label: 'Permanent', labelKey: 'option.assignmentType.permanent' },
  { value: 'replacement', label: 'Replacement', labelKey: 'option.assignmentType.replacement' },
];

export type ShiftValue = 'day' | 'night';

export const SHIFT_LABELS: Record<ShiftValue, string> = { day: 'Day shift', night: 'Night shift' };

const SHIFT_KEYS: Record<ShiftValue, MessageKey> = { day: 'option.shift.day', night: 'option.shift.night' };

export const SHIFT_OPTIONS: { value: string; label: string; labelKey: MessageKey }[] = [
  { value: 'day', label: 'Day shift', labelKey: 'option.shift.day' },
  { value: 'night', label: 'Night shift', labelKey: 'option.shift.night' },
];

/** What the screens show for the shift of an assignment. An assignment that never had one is "Not recorded" - it is never assumed. */
export function assignmentShiftLabel(shift: string | null | undefined): string {
  return shift === 'day' || shift === 'night' ? tNow(SHIFT_KEYS[shift]) : tNow('option.company.shiftNotRecorded');
}

/**
 * The shift part of an EDIT: sent only when HR changed it, so editing anything else never touches the shift. An empty choice means
 * "not recorded" and clears it. The shift never carries salary, dates, type or company.
 */
export function assignmentShiftEdit(current: string | null | undefined, chosen: ShiftValue | ''): { shift?: ShiftValue | null } {
  const before: ShiftValue | '' = current === 'day' || current === 'night' ? current : '';
  if (chosen === before) return {};
  return { shift: chosen === '' ? null : chosen };
}

// ---------------------------------------------------------------- months

export function currentPeriod(now: Date = new Date()): string {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

export function shiftPeriod(period: string, months: number): string {
  const [year, month] = period.split('-').map(Number);
  const date = new Date(year, month - 1 + months, 1);
  return currentPeriod(date);
}

export function isValidPeriod(period: string): boolean {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(period);
}

export function periodLabel(period: string): string {
  if (!isValidPeriod(period)) return period;
  const [year, month] = period.split('-').map(Number);
  return monthYearLabel(getActiveLanguage(), year, month - 1);
}

/** The day before a date (YYYY-MM-DD), used as the default last day of the person being replaced. */
export function dayBefore(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const prior = new Date(Date.UTC(y, m - 1, d - 1));
  return prior.toISOString().slice(0, 10);
}

// ---------------------------------------------------------------- money (never mixed across currencies)

export function formatMoney(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
  } catch {
    return `${amount.toFixed(2)} ${currency}`;
  }
}

export function formatCents(cents: number, currency: string): string {
  return formatMoney(cents / 100, currency);
}

/** One entry per currency, sorted; "—" when there is nothing. Currencies are listed side by side, never summed together. */
export function totalsByCurrency(totals: Record<string, number> | undefined): { currency: string; text: string }[] {
  return Object.entries(totals ?? {})
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([currency, cents]) => ({ currency, text: formatCents(cents, currency) }));
}

// ---------------------------------------------------------------- form checks

export type FieldErrors<T> = Partial<Record<keyof T, string>>;

export interface SalaryFields {
  salary_amount: string;
  salary_currency: string;
}

function salaryErrors(f: SalaryFields): { salary_amount?: string; salary_currency?: string } {
  const out: { salary_amount?: string; salary_currency?: string } = {};
  const amount = Number(f.salary_amount);
  if (f.salary_amount.trim() === '' || Number.isNaN(amount) || amount <= 0) out.salary_amount = tNow('validation.company.salaryAmount');
  if (!/^[A-Za-z]{3}$/.test(f.salary_currency.trim())) out.salary_currency = tNow('validation.company.currencyCodeExample');
  return out;
}

export interface AssignForm extends SalaryFields {
  employee_id: number | '';
  assignment_type: AssignmentTypeValue | '';
  position_id: number | '';
  start_date: string;
}

export function validateAssignForm(f: AssignForm): FieldErrors<AssignForm> {
  const errors: FieldErrors<AssignForm> = { ...salaryErrors(f) };
  if (f.employee_id === '') errors.employee_id = tNow('validation.company.chooseEmployee');
  if (f.assignment_type === '') errors.assignment_type = tNow('validation.company.chooseAssignmentType');
  if (f.position_id === '') errors.position_id = tNow('validation.company.choosePosition');
  if (f.start_date === '') errors.start_date = tNow('validation.company.enterStartDate');
  return errors;
}

export interface ReplaceForm extends SalaryFields {
  replacement_employee_id: number | '';
  replacement_date: string;
  original_last_day: string;
  assignment_type: AssignmentTypeValue | '';
  position_id: number | '';
}

export function validateReplaceForm(f: ReplaceForm, originalStart: string, originalEmployeeId: number): FieldErrors<ReplaceForm> {
  // A Replacement has no salary of its own (the server uses the replaced Permanent's), so only a Permanent successor needs one.
  const errors: FieldErrors<ReplaceForm> = f.assignment_type === 'replacement' ? {} : { ...salaryErrors(f) };
  if (f.replacement_employee_id === '') errors.replacement_employee_id = tNow('validation.company.chooseReplacementEmployee');
  else if (f.replacement_employee_id === originalEmployeeId) errors.replacement_employee_id = tNow('validation.company.cannotReplaceSelf');
  if (f.replacement_date === '') errors.replacement_date = tNow('validation.company.enterReplacementDate');
  if (f.assignment_type === '') errors.assignment_type = tNow('validation.company.chooseReplacementKind');
  const lastDay = f.original_last_day || (f.replacement_date ? dayBefore(f.replacement_date) : '');
  if (f.replacement_date !== '' && lastDay !== '') {
    if (lastDay >= f.replacement_date) errors.original_last_day = tNow('validation.company.lastDayBeforeReplacement');
    else if (lastDay < originalStart) errors.replacement_date = tNow('validation.company.replacementAfterStart', { date: originalStart });
  }
  return errors;
}

export interface ReturnFormErrors {
  return_date?: string;
  salary_amount?: string;
  salary_currency?: string;
  salary_confirmed?: string;
}

/** The returning employee's salary is never assumed: HR must enter (or keep) it and tick that they checked it. */
export function validateReturnForm(returnDate: string, replacementStart: string, salary: { amount: string; currency: string; confirmed: boolean }): ReturnFormErrors {
  const errors: ReturnFormErrors = {};
  if (returnDate === '') errors.return_date = tNow('validation.company.enterReturnDate');
  else if (returnDate <= replacementStart) errors.return_date = tNow('validation.company.returnAfterReplacementStart', { date: replacementStart });
  const amount = Number(salary.amount);
  if (salary.amount.trim() === '' || Number.isNaN(amount) || amount <= 0) errors.salary_amount = tNow('validation.company.returnSalary');
  if (!/^[A-Za-z]{3}$/.test(salary.currency.trim())) errors.salary_currency = tNow('validation.company.currencyCodeExample');
  if (!salary.confirmed) errors.salary_confirmed = tNow('validation.company.confirmReturnSalary');
  return errors;
}

/** The server asks for a reason (Super Admin only) when a change would alter an approved or finalized payroll month. */
export function isCorrectionReasonRequired(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { code?: unknown }).code === 'CORRECTION_REASON_REQUIRED';
}

/** A Temporary worker is paid per verified day of Temporary cover; registering one as a Replacement needs a deliberate HR decision. */
export function isTemporaryWorker(candidate: { employment_type?: string | null } | null): boolean {
  return candidate?.employment_type === 'temporary';
}

export function validateStopForm(endDate: string, reason: string, start: string): { end_date?: string; reason?: string } {
  const errors: { end_date?: string; reason?: string } = {};
  if (endDate === '') errors.end_date = tNow('validation.company.enterLastDay');
  else if (endDate < start) errors.end_date = tNow('validation.company.stopLastDayBeforeStart', { date: start });
  if (reason.trim().length < 3) errors.reason = tNow('validation.company.stopReason');
  return errors;
}

/** A warning (not a refusal) shown while editing a start date: what the change touches. */
export function startDateChangeWarning(oldStart: string, newStart: string): string | null {
  if (oldStart === newStart || newStart === '') return null;
  const earlier = newStart < oldStart ? newStart : oldStart;
  return tNow('warning.company.startDateChange', { month: earlier.slice(0, 7), oldStart });
}

export const EVENT_LABELS: Record<string, string> = {
  assigned: 'Added to company',
  replaced: 'Replaced',
  returned: 'Returned',
  stopped: 'Stopped',
  edited: 'Edited',
  ended: 'Assignment ended',
  salary_changed: 'Salary changed',
  cancelled: 'Cancelled',
  changed: 'Changed',
};

/** The label of a history event in the language the HR screen is showing; an unknown event shows as it is. */
export function eventLabel(event: string): string {
  return tValueNow('option.company.event', event) ?? EVENT_LABELS[event] ?? event;
}
