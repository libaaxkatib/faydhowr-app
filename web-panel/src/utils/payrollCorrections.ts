/**
 * Pure helpers of the corrections ledger. The server is the authority on who may do what; these only decide which buttons to offer.
 */

export type CorrectionKind = 'overpayment' | 'underpayment';

/** Only a Super Admin pays an underpayment. */
export function canPayUnderpayment(role: string | null | undefined): boolean {
  return role === 'super_admin';
}

/** Only a Super Admin writes a debt off, and only a Super Admin voids a payment made to an employee. */
export function canWriteOff(role: string | null | undefined): boolean {
  return role === 'super_admin';
}

export function canVoidPayment(role: string | null | undefined): boolean {
  return role === 'super_admin';
}

/** A payroll payment marked Paid can be voided by a Super Admin or an HR Manager (the server checks the role again). */
export function canVoidPayrollPayment(role: string | null | undefined): boolean {
  return role === 'super_admin' || role === 'hr_manager';
}

/** A write-off needs a real reason (the server requires at least 5 characters too). */
export function validateWriteOffReason(reason: string): string | null {
  return reason.trim().length >= 5 ? null : 'Give the reason for writing this debt off (at least 5 characters).';
}

/** A Super Admin or an HR Manager manages the debt of an employee (record a repayment, void a mistaken one). */
export function canManageDebt(role: string | null | undefined): boolean {
  return role === 'super_admin' || role === 'hr_manager';
}

/** One key per dialog opening: a retry or a double click of the same submission is recognised by the server and recorded once. */
export function newIdempotencyKey(): string {
  const c = globalThis.crypto as Crypto | undefined;
  if (c && typeof c.randomUUID === 'function') return c.randomUUID();
  return `k${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
}

/** Validates the amount typed for a payment/repayment against what is still open (both in cents). Returns an error message or null. */
export function validateSettlementAmount(amount: string, availableCents: number): string | null {
  const value = Number(amount);
  if (amount.trim() === '' || Number.isNaN(value) || value <= 0) return 'Enter an amount greater than zero.';
  if (Math.round(value * 100) > availableCents) return `The amount is more than what is still open (${(availableCents / 100).toFixed(2)}).`;
  return null;
}

export const CORRECTION_STATUS_LABEL: Record<string, string> = {
  open: 'Still open',
  recovered: 'Fully recovered',
  written_off: 'Written off',
  settled: 'Paid in full',
};
