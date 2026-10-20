/**
 * Pure helpers of the Company Employee Management screen: month handling, money by currency, and the client-side checks that save a
 * round trip. The server validates everything again and is the authority; nothing here decides who may do what.
 */

export type AssignmentTypeValue = 'permanent' | 'replacement';

export const ASSIGNMENT_TYPE_LABELS: Record<AssignmentTypeValue, string> = { permanent: 'Permanent', replacement: 'Replacement' };

export const ASSIGNMENT_TYPE_OPTIONS = [
  { value: 'permanent', label: 'Permanent' },
  { value: 'replacement', label: 'Replacement' },
];

export type ShiftValue = 'day' | 'night';

export const SHIFT_LABELS: Record<ShiftValue, string> = { day: 'Day shift', night: 'Night shift' };

export const SHIFT_OPTIONS = [
  { value: 'day', label: 'Day shift' },
  { value: 'night', label: 'Night shift' },
];

/** What the screens show for the shift of an assignment. An assignment that never had one is "Not recorded" - it is never assumed. */
export function assignmentShiftLabel(shift: string | null | undefined): string {
  return shift === 'day' || shift === 'night' ? SHIFT_LABELS[shift] : 'Not recorded';
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
  return new Date(year, month - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
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
  if (f.salary_amount.trim() === '' || Number.isNaN(amount) || amount <= 0) out.salary_amount = 'Enter a salary greater than zero.';
  if (!/^[A-Za-z]{3}$/.test(f.salary_currency.trim())) out.salary_currency = 'Use a 3-letter currency code, for example USD.';
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
  if (f.employee_id === '') errors.employee_id = 'Search and choose a registered employee.';
  if (f.assignment_type === '') errors.assignment_type = 'Choose Permanent or Replacement.';
  if (f.position_id === '') errors.position_id = 'Choose a position.';
  if (f.start_date === '') errors.start_date = 'Enter the start date.';
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
  if (f.replacement_employee_id === '') errors.replacement_employee_id = 'Search and choose the replacement employee.';
  else if (f.replacement_employee_id === originalEmployeeId) errors.replacement_employee_id = 'An employee cannot replace themselves.';
  if (f.replacement_date === '') errors.replacement_date = 'Enter the replacement date.';
  if (f.assignment_type === '') errors.assignment_type = 'Say whether the replacement is Permanent or a Replacement.';
  const lastDay = f.original_last_day || (f.replacement_date ? dayBefore(f.replacement_date) : '');
  if (f.replacement_date !== '' && lastDay !== '') {
    if (lastDay >= f.replacement_date) errors.original_last_day = "The original employee's last day must be before the replacement date.";
    else if (lastDay < originalStart) errors.replacement_date = `The assignment started on ${originalStart}; choose a later replacement date.`;
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
  if (returnDate === '') errors.return_date = 'Enter the return date.';
  else if (returnDate <= replacementStart) errors.return_date = `The replacement started on ${replacementStart}; the return date must be after that day.`;
  const amount = Number(salary.amount);
  if (salary.amount.trim() === '' || Number.isNaN(amount) || amount <= 0) errors.salary_amount = 'Enter the salary of the returning employee (greater than zero).';
  if (!/^[A-Za-z]{3}$/.test(salary.currency.trim())) errors.salary_currency = 'Use a 3-letter currency code, for example USD.';
  if (!salary.confirmed) errors.salary_confirmed = 'Confirm that you checked this salary. It is not assumed to be unchanged.';
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
  if (endDate === '') errors.end_date = 'Enter the last day.';
  else if (endDate < start) errors.end_date = `The assignment started on ${start}; the last day cannot be before it.`;
  if (reason.trim().length < 3) errors.reason = 'Give a reason (at least 3 characters).';
  return errors;
}

/** A warning (not a refusal) shown while editing a start date: what the change touches. */
export function startDateChangeWarning(oldStart: string, newStart: string): string | null {
  if (oldStart === newStart || newStart === '') return null;
  const earlier = newStart < oldStart ? newStart : oldStart;
  return `Changing the start date changes the payable days of every month from ${earlier.slice(0, 7)} on. The original date (${oldStart}) is kept in the history. If an approved or finalized payroll month is really affected, only a Super Admin can make the change, with a reason: the closed month stays as it was and any overpayment is deducted from later salaries.`;
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
