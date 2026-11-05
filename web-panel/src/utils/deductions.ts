/**
 * Pure helpers of penalties and one-month salary advances (HR Release 1B): who is offered which action, what the amount box accepts, the payload of a
 * registration (with its idempotency key and the "record it again anyway" confirmation), the readable server errors, the figures of one row and of the
 * Outstanding balances list, and the note shown on a payroll line that carried a deduction forward. The server is the authority on every rule; these only
 * decide what to offer and how to say it. No React and no `@/` imports, so Node's test runner can run them.
 */

import { getActiveLanguage, tNow } from '../i18n/active.ts';
import type { MessageKey } from '../i18n/messages/index.ts';
import type { EmployeeAdvance, EmployeePenalty, RecordEmployeeAdvancePayload, RecordEmployeePenaltyPayload } from '../types/employee.ts';
import type { DeductionBalanceFilters, DeductionBalanceRow, DeductionBalances, DeductionPath, PayrollDeductionDetail, PayrollDeductionKind } from '../types/payroll.ts';
import { formatCurrency } from './formatters.ts';
import { errorCodeOf, friendlyErrorMessage } from './hrHelpers.ts';

export { newIdempotencyKey } from './payrollCorrections.ts';

export type DeductionKind = 'penalty' | 'advance';

/** The path word the server uses for a kind. */
export const DEDUCTION_PATH: Record<DeductionKind, DeductionPath> = { penalty: 'penalties', advance: 'advances' };

/** The server asks for a reason of at least this many characters on a registration, a void, a correction and a waiver. */
export const MIN_REASON_LENGTH = 3;

/** The earliest payroll month the server accepts. */
export const EARLIEST_PERIOD = '2020-01';

/** A date more than this many days ahead is refused by the server. */
export const MAX_DAYS_AHEAD = 31;

// ---------------------------------------------------------------- who is offered what (the server checks the role again)

/** A Super Admin or an HR Manager voids or corrects a penalty / advance. */
export function canChangeDeduction(role: string | null | undefined): boolean {
  return role === 'super_admin' || role === 'hr_manager';
}

/** Only a Super Admin waives what is still owed. */
export function canWaiveDeduction(role: string | null | undefined): boolean {
  return role === 'super_admin';
}

// ---------------------------------------------------------------- money

/** Cents as dollars, "$12.50". */
export function centsText(cents: number | null | undefined): string {
  return formatCurrency((Number.isFinite(cents) ? (cents as number) : 0) / 100);
}

/** A money string such as "25.50" to whole cents (no floating-point drift); NaN when it is not an amount. */
export function toCents(value: string | number | null | undefined): number {
  if (value === null || value === undefined || value === '') return Number.NaN;
  const number = Number(value);

  return Number.isFinite(number) ? Math.round(number * 100) : Number.NaN;
}

/**
 * What the amount box lets through while typing: digits and one decimal point, at most two decimals. Letters, minus signs, exponents and a second point
 * are dropped, so the value can never be negative or have more than cents.
 */
export function sanitizeAmountInput(text: string): string {
  const cleaned = text.replace(/[^0-9.]/g, '');
  const dot = cleaned.indexOf('.');
  if (dot === -1) return cleaned;

  return `${cleaned.slice(0, dot)}.${cleaned.slice(dot + 1).replace(/\./g, '').slice(0, 2)}`;
}

const AMOUNT_PATTERN = /^\d+(\.\d{1,2})?$/;

/** The message key explaining why the typed amount cannot be sent, or null when it can. An empty box is not an error here (the button stays off). */
export function amountProblem(text: string): MessageKey | null {
  const value = text.trim();
  if (value === '') return null;
  if (!AMOUNT_PATTERN.test(value)) return /^\d+\.\d{3,}$/.test(value) ? 'validation.deduction.amountDecimals' : 'validation.deduction.amountPositive';

  return Number(value) > 0 ? null : 'validation.deduction.amountPositive';
}

/** True when the amount box holds a valid positive amount of at most two decimals. */
export function amountReady(text: string): boolean {
  return text.trim() !== '' && amountProblem(text) === null;
}

/** The amount to waive: an empty box means everything still owed; otherwise it must be a valid amount that is not more than what is owed. */
export function waiveAmountProblem(text: string, owedCents: number): MessageKey | null {
  if (text.trim() === '') return null;
  const problem = amountProblem(text);
  if (problem) return problem;

  return toCents(text) > owedCents ? 'validation.deduction.amountTooMuch' : null;
}

/** A reason (or correction reason) of at least three characters once trimmed. */
export function reasonReady(text: string): boolean {
  return text.trim().length >= MIN_REASON_LENGTH;
}

/** The payroll month as the server accepts it: YYYY-MM, not before 2020-01 and not after next month. `now` is the current moment (a parameter for tests). */
export function periodProblem(period: string, now: Date): MessageKey | null {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period)) return 'validation.deduction.periodRange';
  const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  const latest = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;

  return period < EARLIEST_PERIOD || period > latest ? 'validation.deduction.periodRange' : null;
}

/** The date of a penalty or an advance: not more than 31 days ahead. `date` is YYYY-MM-DD. */
export function dateProblem(date: string, now: Date): MessageKey | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return 'validation.deduction.dateInvalid';
  const [year, month, day] = date.split('-').map(Number);
  const limit = new Date(now.getFullYear(), now.getMonth(), now.getDate() + MAX_DAYS_AHEAD);
  const picked = new Date(year, month - 1, day);

  return picked.getTime() > limit.getTime() ? 'validation.deduction.dateFuture' : null;
}

// ---------------------------------------------------------------- registering

export interface RegisterForm {
  date: string;
  reason: string;
  amount: string;
  payrollPeriod: string;
  notes: string;
  /** The `client_company_id` part chosen in the dialog (empty when the employee has no choice to make). */
  company: { client_company_id?: number | null };
}

/**
 * The body of a registration. The currency is always USD. `key` is the dialog's idempotency key (the same one on every retry of this submission);
 * `confirmDuplicate` is true only after the person said "record it again anyway".
 */
export function registerPayload(kind: 'penalty', form: RegisterForm, key: string, confirmDuplicate: boolean): RecordEmployeePenaltyPayload;
export function registerPayload(kind: 'advance', form: RegisterForm, key: string, confirmDuplicate: boolean): RecordEmployeeAdvancePayload;
export function registerPayload(kind: DeductionKind, form: RegisterForm, key: string, confirmDuplicate: boolean): RecordEmployeePenaltyPayload | RecordEmployeeAdvancePayload;
export function registerPayload(kind: DeductionKind, form: RegisterForm, key: string, confirmDuplicate: boolean): RecordEmployeePenaltyPayload | RecordEmployeeAdvancePayload {
  const common = {
    currency: 'USD',
    payroll_period: form.payrollPeriod,
    reason: form.reason.trim(),
    notes: form.notes.trim() === '' ? null : form.notes.trim(),
    idempotency_key: key,
    ...(confirmDuplicate ? { confirm_duplicate: true } : {}),
    ...form.company,
  };

  return kind === 'penalty'
    ? { ...common, penalty_date: form.date, deduction_amount: Number(form.amount) }
    : { ...common, advance_date: form.date, amount: Number(form.amount) };
}

/** The server found an identical record: the person is asked whether to record it again anyway. */
export function isDuplicateRecord(error: unknown): boolean {
  return errorCodeOf(error) === 'DUPLICATE_RECORD';
}

/**
 * A readable message for a failed registration, void, correction or waiver: our wording for the known codes, the server's own sentence otherwise,
 * never raw JSON. EMPLOYEE_NOT_ACTIVE is worded for this context (the shared sentence is about replacements).
 */
export function deductionErrorMessage(error: unknown, fallback: string): string {
  if (errorCodeOf(error) === 'EMPLOYEE_NOT_ACTIVE') {
    const message = (error as { message?: unknown } | null)?.message;

    return getActiveLanguage() === 'so' || typeof message !== 'string' || message === '' ? tNow('notify.deduction.employeeNotActive') : message;
  }

  return friendlyErrorMessage(error, fallback);
}

// ---------------------------------------------------------------- one row of the employee's penalty / advance card

export type DeductionRecord = EmployeePenalty | EmployeeAdvance;

export interface DeductionRowView {
  id: number;
  version: number | null;
  voided: boolean;
  amountText: string;
  deductedCents: number;
  owedCents: number | null;
  waivedCents: number;
  /** A finalized payroll took part of it: it cannot be voided or corrected until that payroll is reopened. */
  applied: boolean;
  replacesId: number | null;
  replacedById: number | null;
  canVoid: boolean;
  canCorrect: boolean;
  canWaive: boolean;
}

/** What one record shows and which actions it offers to this role. A voided row offers none; an already-deducted row offers only the Super Admin's waiver. */
export function deductionRowView(record: DeductionRecord, all: readonly DeductionRecord[], role: string | null | undefined): DeductionRowView {
  const voided = record.status === 'voided';
  const deductedCents = record.deducted_cents ?? 0;
  const owedCents = typeof record.owed_cents === 'number' ? record.owed_cents : null;
  const known = typeof record.version === 'number';
  const applied = deductedCents > 0;
  const replacement = all.find((other) => other.replaces_id === record.id);
  const amount = 'deduction_amount' in record ? record.deduction_amount : record.amount;

  return {
    id: record.id,
    version: known ? (record.version as number) : null,
    voided,
    amountText: `${amount} ${record.currency}`,
    deductedCents,
    owedCents,
    waivedCents: record.waived_cents ?? 0,
    applied,
    replacesId: record.replaces_id ?? null,
    replacedById: replacement?.id ?? null,
    canVoid: !voided && known && !applied && canChangeDeduction(role),
    canCorrect: !voided && known && !applied && canChangeDeduction(role),
    canWaive: !voided && known && applied && (owedCents ?? 0) > 0 && canWaiveDeduction(role),
  };
}

/** Only the fields of a correction that really differ from the record, so an unchanged field is never sent. */
export function changedFields<T extends Record<string, string | number | null>>(before: T, after: T): Partial<T> {
  const changed: Partial<T> = {};
  for (const key of Object.keys(after) as (keyof T)[]) {
    if (after[key] !== before[key]) changed[key] = after[key];
  }

  return changed;
}

// ---------------------------------------------------------------- the Outstanding balances list

export const BALANCE_FILTER_KEYS = ['company', 'kind', 'leavers'] as const;

/** The list's filters from the address bar: a company id, a kind and "left the company only". Anything else is ignored. */
export function parseBalanceFilters(get: (key: string) => string): DeductionBalanceFilters {
  const company = Number(get('company'));
  const kind = get('kind');

  return {
    ...(get('company') !== '' && Number.isInteger(company) && company > 0 ? { client_company_id: company } : {}),
    ...(kind === 'penalty' || kind === 'advance' ? { kind } : {}),
    ...(get('leavers') === '1' ? { leavers_only: true } : {}),
  };
}

export interface BalanceRowView {
  key: string;
  employeeName: string;
  employeeNumber: string;
  companyName: string;
  kindKey: MessageKey;
  period: string;
  registered: string;
  deducted: string;
  waived: string;
  owed: string;
  stateKey: MessageKey;
  leaver: boolean;
  reason: string;
}

/** One row of the list as the screen shows it: money formatted, kind and state as message keys, a dash where the server sent no name. */
export function balanceRowView(row: DeductionBalanceRow): BalanceRowView {
  return {
    key: `${row.kind}-${row.id}`,
    employeeName: row.employee_name?.trim() || '—',
    employeeNumber: row.employee_number?.trim() || '',
    companyName: row.company_name?.trim() || '—',
    kindKey: row.kind === 'penalty' ? 'option.deduction.kindPenalty' : 'option.deduction.kindAdvance',
    period: row.registered_period,
    registered: centsText(row.registered_cents),
    deducted: centsText(row.deducted_cents),
    waived: centsText(row.waived_cents),
    owed: centsText(row.owed_cents),
    stateKey: row.state === 'partly_deducted' ? 'status.deduction.statePartly' : 'status.deduction.statePending',
    leaver: row.leaver === true,
    reason: row.reason?.trim() || '',
  };
}

export interface BalanceTotalsView {
  count: number;
  owed: string;
  leaverCount: number;
  leaverOwed: string;
}

export function balanceTotalsView(balances: Pick<DeductionBalances, 'totals'>): BalanceTotalsView {
  return {
    count: balances.totals.count,
    owed: centsText(balances.totals.owed_cents),
    leaverCount: balances.totals.leaver_count,
    leaverOwed: centsText(balances.totals.leaver_owed_cents),
  };
}

// ---------------------------------------------------------------- a payroll line that carried a deduction forward

export interface CarryNote {
  /** This line brings the deduction in from an earlier month. */
  carriedIn: boolean;
  /** The month it was registered in (known when carriedIn). */
  carriedFrom: string | null;
  owedCents: number;
  deductedCents: number;
  /** What this month's net could not cover: it stays owed and is taken from the next months. */
  notDeductedCents: number;
  previouslyDeductedCents: number;
}

const OWED_KINDS: readonly PayrollDeductionKind[] = ['penalty', 'advance'];

/** The note for a penalty / advance line: null for any other kind, and for a line that is a plain in-month deduction taken in full. */
export function carryNote(kind: PayrollDeductionKind, detail: PayrollDeductionDetail | null | undefined): CarryNote | null {
  if (!OWED_KINDS.includes(kind) || !detail) return null;
  const notDeductedCents = Math.max(0, detail.not_deducted_cents ?? 0);
  const carriedFrom = detail.carried_in === true ? (detail.registered_period ?? null) : null;
  if (detail.carried_in !== true && notDeductedCents === 0) return null;

  return {
    carriedIn: detail.carried_in === true,
    carriedFrom,
    owedCents: detail.owed_cents ?? 0,
    deductedCents: detail.deducted_cents ?? 0,
    notDeductedCents,
    previouslyDeductedCents: detail.previously_deducted_cents ?? 0,
  };
}
