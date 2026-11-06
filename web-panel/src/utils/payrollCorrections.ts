import { tNow, tValueNow } from '../i18n/active.ts';

/**
 * Pure helpers of the corrections ledger. The server is the authority on who may do what; these only decide which buttons to offer.
 */

import type { PayrollCorrection } from '../types/payroll.ts';
import { facilityToday } from './facility.ts';

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

/**
 * Money can be recorded against a correction only while it is OPEN and something can still be settled. A superseded correction (its company's month was
 * reopened and rebuilt) is history only, and an overpayment whose whole balance is already scheduled in draft payroll months has nothing left to settle
 * by hand. The server refuses a superseded one too (CORRECTION_SUPERSEDED).
 */
export function canSettleCorrection(correction: Pick<PayrollCorrection, 'status' | 'kind' | 'outstanding_cents' | 'available_to_settle_cents'>, mayManage: boolean, role: string | null | undefined): boolean {
  if (!mayManage || correction.status !== 'open' || correction.outstanding_cents <= 0 || correction.available_to_settle_cents <= 0) return false;

  return correction.kind === 'underpayment' ? canPayUnderpayment(role) : canManageDebt(role);
}

/** A debt is written off (Super Admin only) only while its correction is OPEN and part of it can still be settled. */
export function canWriteOffCorrection(correction: Pick<PayrollCorrection, 'status' | 'kind' | 'available_to_settle_cents'>, mayManage: boolean, role: string | null | undefined): boolean {
  return mayManage && correction.status === 'open' && correction.kind === 'overpayment' && correction.available_to_settle_cents > 0 && canWriteOff(role);
}

/** A write-off needs a real reason (the server requires at least 5 characters too). */
export function validateWriteOffReason(reason: string): string | null {
  return reason.trim().length >= 5 ? null : tNow('validation.payroll.writeOffReasonMissing');
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
  if (amount.trim() === '' || Number.isNaN(value) || value <= 0) return tNow('validation.payroll.amountPositive');
  if (Math.round(value * 100) > availableCents) return tNow('validation.payroll.amountTooMuch', { amount: (availableCents / 100).toFixed(2) });
  return null;
}

export const CORRECTION_STATUS_LABEL: Record<string, string> = {
  open: 'Still open',
  recovered: 'Fully recovered',
  written_off: 'Written off',
  settled: 'Paid in full',
};

/**
 * The business day of a payroll settlement (a repayment, the payment of an underpayment, a write-off) is the Mogadishu calendar day, the same calendar
 * the server checks the date against - NOT the day of the browser's own clock. Using the browser's day made the dialog propose 26 October while the
 * server was still on 25 October (UTC) between 00:00 and 03:00 in Mogadishu, and the server refused it as "in the future".
 */
export function settlementToday(now: Date = new Date()): string {
  return facilityToday(now);
}

/** A settlement date is a plain calendar day (YYYY-MM-DD), never later than today in Mogadishu. Returns an error message or null. */
export function validateSettlementDate(date: string, now: Date = new Date()): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return tNow('validation.payroll.chooseDate');
  return date > settlementToday(now) ? tNow('validation.payroll.dateFuture') : null;
}

export function correctionStatusLabel(status: string): string {
  return tValueNow('status.payroll.correction', status) ?? CORRECTION_STATUS_LABEL[status] ?? status;
}
