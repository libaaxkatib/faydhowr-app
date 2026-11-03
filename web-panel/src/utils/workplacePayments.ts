/**
 * Pure helpers of the "Payments by workplace" panel. The server calculates every total, paid amount, balance and status; these helpers only decide
 * what the panel offers (which buttons, what a typed amount means) and what it sends. Visible wording comes from the language catalogue.
 */

import { tNow } from '../i18n/active.ts';
import type { MessageKey } from '../i18n/messages/index.ts';
import type { RecordWorkplacePaymentPayload, WorkplacePaymentStatus, WorkplacePayrollSummary } from '../types/payroll.ts';

export const WORKPLACE_STATUS: Record<WorkplacePaymentStatus, { labelKey: MessageKey; tone: 'danger' | 'warning' | 'success' }> = {
  unpaid: { labelKey: 'status.payroll.wpUnpaid', tone: 'danger' },
  partial: { labelKey: 'status.payroll.wpPartial', tone: 'warning' },
  paid: { labelKey: 'status.payroll.wpPaid', tone: 'success' },
};

/** The workplace as a person names it: the company or guri, then its work location when it has one. */
export function workplaceName(workplace: Pick<WorkplacePayrollSummary, 'company_name' | 'workplace_name'>): string {
  const company = workplace.company_name?.trim() ?? '';
  const location = workplace.workplace_name?.trim() ?? '';
  if (company && location) return `${company} · ${location}`;

  return company || location || '—';
}

/** Payments can be recorded for a finalized month, by someone allowed to, for a workplace that still has an outstanding balance. */
export function canRecordPayment(workplace: Pick<WorkplacePayrollSummary, 'payable' | 'outstanding_cents'>, monthCanRecord: boolean, userCanRecord: boolean): boolean {
  return monthCanRecord && userCanRecord && workplace.payable && workplace.outstanding_cents > 0;
}

/** Whole cents of an amount typed in dollars with at most two decimals, or null when it is not such an amount. */
export function amountToCents(typed: string): number | null {
  const text = typed.trim();
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(text)) return null;
  const [dollars, cents = ''] = text.split('.');

  return Number(dollars) * 100 + Number(cents.padEnd(2, '0'));
}

/** The message under the amount field for a partial payment, or null when the amount is acceptable (the server checks it again). */
export function validatePartialAmount(typed: string, outstandingCents: number): string | null {
  const cents = amountToCents(typed);
  if (cents === null || cents <= 0) return tNow('validation.payroll.amountPositive');
  if (cents > outstandingCents) return tNow('validation.payroll.amountTooMuch', { amount: (outstandingCents / 100).toFixed(2) });

  return null;
}

/** The request for a payment: a full payment sends no amount (the server pays exactly the outstanding balance at that moment). */
export function paymentPayload(
  workplace: Pick<WorkplacePayrollSummary, 'client_company_id' | 'work_location_id'>,
  mode: 'full' | 'partial',
  typedAmount: string,
  paymentDate: string,
  note: string,
  idempotencyKey: string,
): RecordWorkplacePaymentPayload {
  return {
    client_company_id: workplace.client_company_id,
    work_location_id: workplace.work_location_id,
    mode,
    ...(mode === 'partial' ? { amount: typedAmount.trim() } : {}),
    payment_date: paymentDate,
    note: note.trim() === '' ? null : note.trim(),
    idempotency_key: idempotencyKey,
  };
}

/** A void needs a real reason (the server requires at least 5 characters too). */
export function validateVoidReason(reason: string): string | null {
  return reason.trim().length >= 5 ? null : tNow('validation.payroll.wpVoidReason');
}
