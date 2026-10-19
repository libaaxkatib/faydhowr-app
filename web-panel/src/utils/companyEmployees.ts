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
  const errors: FieldErrors<ReplaceForm> = { ...salaryErrors(f) };
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

export function validateReturnForm(returnDate: string, replacementStart: string): { return_date?: string } {
  if (returnDate === '') return { return_date: 'Enter the return date.' };
  if (returnDate <= replacementStart) return { return_date: `The replacement started on ${replacementStart}; the return date must be after that day.` };
  return {};
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
  return `Changing the start date changes the payable days of every month from ${earlier.slice(0, 7)} on. The original date (${oldStart}) is kept in the history. Months whose payroll is approved or finalized cannot be changed; the server will refuse the edit and a payroll adjustment is needed.`;
}

export const EVENT_LABELS: Record<string, string> = {
  assigned: 'Added to company',
  replaced: 'Replaced',
  returned: 'Returned',
  stopped: 'Stopped',
  edited: 'Edited',
  cancelled: 'Cancelled',
  changed: 'Changed',
};
