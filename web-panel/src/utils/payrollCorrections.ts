/**
 * Pure helpers of the corrections ledger. The server is the authority on who may do what; these only decide which buttons to offer.
 */

export type CorrectionKind = 'overpayment' | 'underpayment';

/** Only a Super Admin pays an underpayment. */
export function canPayUnderpayment(role: string | null | undefined): boolean {
  return role === 'super_admin';
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
  settled: 'Paid in full',
};
