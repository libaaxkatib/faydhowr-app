/**
 * Pure helpers of the company-level payroll screen. The server decides every status, total and permission (a company is reviewed and finalized on its
 * own, and APPROVED by being paid in full; the month's own status is its least advanced company's); these helpers only decide which buttons the screen
 * offers and how a month and its lines are grouped for display. Visible wording comes from the language catalogue.
 *
 * There is no Approve button for a company or for the month (owner decision 2026-11-04): Reviewed and Finalized describe the PAYROLL, Unpaid / Partially
 * paid / Approved describe the PAYMENT, and the screen shows the two side by side so they are never confused.
 */

import type { MessageKey } from '../i18n/messages/index.ts';
import type { CompanyPaymentStatus, PayrollItem, PayrollRun, PayrollRunSection, PayrollRunStatus, RecordCompanyPaymentPayload } from '../types/payroll.ts';

export type SectionAction = 'review' | 'finalize' | 'reopen' | 'reopen_finalized';

/**
 * The payroll buttons a company row offers: review needs hr.payroll.manage; finalize and reopen need hr.payroll.approve; a finalized company offers
 * "Reopen for correction" only. `reopenFinalizedBlocked` is true for a finalized company with recorded payments when the person is not a Super Admin: the
 * server refuses them (403 PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY), so the screen does not offer it and shows a hint instead.
 */
export function sectionActions(status: PayrollRunStatus, canManage: boolean, canApprove: boolean, reopenFinalizedBlocked = false): SectionAction[] {
  switch (status) {
    case 'draft':
      return canManage ? ['review'] : [];
    case 'reviewed':
    case 'approved':
      // "approved" can only be a company an earlier version left there: it is finalized exactly like a reviewed one.
      return canApprove ? ['finalize', 'reopen'] : [];
    case 'finalized':
      // Owner decision 2026-11-04: a finalized company can be reopened for correction (a reason; payments stay). The server decides who may.
      return canApprove && !reopenFinalizedBlocked ? ['reopen_finalized'] : [];
  }
}

export type PaymentAction = 'approve_paid' | 'approve_partial';

/**
 * The payment buttons: "Approve Paid" (the whole balance) and "Approve Partial" (an amount). Offered only to someone who may record payments, for a
 * company that is finalized and still owes something (the server says so in `can_pay`; it checks everything again).
 */
export function paymentActions(section: Pick<PayrollRunSection, 'can_pay' | 'outstanding_cents'>, canApprove: boolean): PaymentAction[] {
  return canApprove && section.can_pay && section.outstanding_cents > 0 ? ['approve_paid', 'approve_partial'] : [];
}

/** How a company's payment / approval state is shown: Partially paid is a warning, never the green of Approved. */
export const PAYMENT_STATUS_LABEL: Record<CompanyPaymentStatus, { labelKey: MessageKey; tone: 'danger' | 'warning' | 'success' }> = {
  unpaid: { labelKey: 'status.payroll.apUnpaid', tone: 'danger' },
  partially_paid: { labelKey: 'status.payroll.apPartial', tone: 'warning' },
  approved: { labelKey: 'status.payroll.apApproved', tone: 'success' },
  overpaid: { labelKey: 'status.payroll.apOverpaid', tone: 'danger' },
};

/** "Reconcile overpayment" is offered for a finalized company that was paid more than its corrected payroll says and has not been settled yet. */
export function canReconcile(section: Pick<PayrollRunSection, 'status' | 'unreconciled_overpaid_cents'>, canApprove: boolean): boolean {
  return canApprove && section.status === 'finalized' && (section.unreconciled_overpaid_cents ?? 0) > 0;
}

export const MIN_REOPEN_FINALIZED_REASON = 10;

/** The longest payment reference the server accepts. */
export const MAX_PAYMENT_REFERENCE = 100;

/**
 * The request of "Approve Paid" (no amount: the server pays exactly the outstanding balance at that moment) or "Approve Partial" (the typed amount, in
 * dollars with at most two decimals). The key is made once per dialog, so a retry or a double click is recorded once. The reference (a bank or mobile-money
 * transaction id, a receipt number) is optional: it is trimmed and left out of the request when empty, so a payment without it is always possible.
 */
export function companyPaymentPayload(mode: 'full' | 'partial', typedAmount: string, paymentDate: string, note: string, idempotencyKey: string, reference = ''): RecordCompanyPaymentPayload {
  return {
    mode,
    ...(mode === 'partial' ? { amount: typedAmount.trim() } : {}),
    payment_date: paymentDate,
    note: note.trim() === '' ? null : note.trim(),
    ...(reference.trim() === '' ? {} : { reference: reference.trim() }),
    idempotency_key: idempotencyKey,
  };
}

/** How the month is shown: "partially finalized" while some, but not all, of its companies are finalized; otherwise its own status. */
export function monthDisplayStatus(run: Pick<PayrollRun, 'status' | 'is_partially_finalized'>): PayrollRunStatus | 'partially_finalized' {
  return run.is_partially_finalized ? 'partially_finalized' : run.status;
}

export const MONTH_STATUS_LABEL: Record<PayrollRunStatus | 'partially_finalized', { labelKey: MessageKey; tone: 'neutral' | 'info' | 'warning' | 'success' }> = {
  draft: { labelKey: 'option.payrollStatus.draft', tone: 'neutral' },
  reviewed: { labelKey: 'option.payrollStatus.reviewed', tone: 'info' },
  approved: { labelKey: 'option.payrollStatus.approved', tone: 'warning' },
  finalized: { labelKey: 'option.payrollStatus.finalized', tone: 'success' },
  partially_finalized: { labelKey: 'status.payroll.partiallyFinalized', tone: 'warning' },
};

/** True when the company the line belongs to is finalized (a line of a month with no company sections follows the month). */
export function lineIsFinalized(run: Pick<PayrollRun, 'status' | 'sections'>, item: Pick<PayrollItem, 'section_id'>): boolean {
  if (run.status === 'finalized') return true;
  if (item.section_id === null || item.section_id === undefined) return false;

  return (run.sections ?? []).some((section) => section.id === item.section_id && section.status === 'finalized');
}

/** The lines of one company (null = every company). */
export function itemsOfSection(items: PayrollItem[], sectionId: number | null): PayrollItem[] {
  return sectionId === null ? items : items.filter((item) => item.section_id === sectionId);
}

/** The company the screen is filtered to, or null when it does not exist (any more) in this month. */
export function selectedSection(sections: PayrollRunSection[], sectionId: number | null): PayrollRunSection | null {
  return sectionId === null ? null : (sections.find((section) => section.id === sectionId) ?? null);
}

/** A company's name as the table shows it (the "no company" section has none). */
export function sectionName(section: Pick<PayrollRunSection, 'company_name'>, fallback: string): string {
  return section.company_name?.trim() || fallback;
}
