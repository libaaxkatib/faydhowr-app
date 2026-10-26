/** Month-end payroll runs (HR payroll). Currency is always USD and money values arrive as strings such as '300.00'. */

export type PayrollRunStatus = 'draft' | 'reviewed' | 'approved' | 'finalized';
export type PayrollPaymentStatus = 'unpaid' | 'paid';
export type PayrollDeductionKind = 'replacement' | 'penalty' | 'advance' | 'absence' | 'adjustment' | 'unpaid_leave' | 'overpayment_recovery';

/** A Super Admin correction of an approved/finalized month, and the ledger that recovers an overpayment from later salaries. Amounts are USD cents. */
export interface PayrollCorrection {
  id: number;
  employee_id: number;
  employee_number: string | null;
  employee_name: string | null;
  period: string;
  kind: 'overpayment' | 'underpayment';
  action: string;
  status: 'open' | 'recovered' | 'written_off' | 'settled';
  currency: string;
  employee_status: string | null;
  has_open_assignment: boolean;
  /** A debt that payroll can no longer collect (nobody is being paid): somebody has to follow it up. */
  needs_follow_up: boolean;
  recorded_net_cents: number;
  corrected_net_cents: number;
  difference_cents: number;
  recovered_cents: number;
  scheduled_cents: number;
  repaid_cents: number;
  written_off_cents: number;
  paid_cents: number;
  outstanding_cents: number;
  available_to_settle_cents: number;
  reason: string;
  corrected_by: string | null;
  corrected_at: string | null;
  recoveries: { period: string; amount_cents: number; status: 'scheduled' | 'applied' }[];
  transactions: PayrollCorrectionTransaction[];
}

export interface PayrollCorrectionTransaction {
  id: number;
  type: 'underpayment_payment' | 'underpayment_payment_reversal' | 'debt_repayment' | 'debt_write_off';
  amount_cents: number;
  transaction_date: string | null;
  note: string | null;
  reason: string | null;
  balance_before_cents: number | null;
  balance_after_cents: number | null;
  reverses_transaction_id: number | null;
  recorded_by: string | null;
  recorded_at: string | null;
  voided: boolean;
  void_reason: string | null;
  voided_by: string | null;
}

/** A payroll payment marked Paid that was later voided: the original payment stays, a linked reversal was added. */
export interface PayrollPaymentVoidRow {
  id: number;
  employee_id: number;
  employee_number: string | null;
  employee_name: string | null;
  period: string | null;
  payroll_item_id: number;
  original_payment_id: number;
  reversal_payment_id: number;
  amount_cents: number;
  reason: string;
  voided_by: string | null;
  voided_at: string | null;
}

export interface PayrollCorrectionsResponse {
  corrections: PayrollCorrection[];
  payroll_payment_voids: PayrollPaymentVoidRow[];
  totals: { currency: string; overpaid_cents: number; recovered_cents: number; written_off_cents: number; payroll_payments_voided_cents: number; outstanding_cents: number; underpaid_cents: number; underpayments_paid_cents: number; underpayments_outstanding_cents: number };
}

export interface PayrollRunTotals {
  gross: string | null;
  replacement_deductions: string | null;
  other_deductions: string | null;
  adjustments: string | null;
  net: string | null;
}

/** For a 'replacement' deduction: who covered, for how long and what it cost. Other kinds carry smaller, kind-specific details. */
export interface PayrollDeductionDetail {
  replacement_id?: number;
  temporary_employee_id?: number;
  temporary_employee_number?: string | null;
  temporary_employee_name?: string | null;
  start_date?: string;
  end_date?: string | null;
  /** Days charged: the cover's actual coverage period, both ends included. */
  covered_days?: number;
  /** Attendance days verified in the ledger; information only. */
  verified_days?: number;
  daily_rate?: string;
  days_in_month?: number;
  charged_dates?: string[];
  amount_cents?: number;
  penalty_date?: string | null;
  advance_date?: string | null;
  absent_dates?: string[];
}

export interface PayrollItemDeduction {
  kind: PayrollDeductionKind;
  description: string;
  amount: string;
  detail: PayrollDeductionDetail | null;
}

/** Only the part of the stored calculation the page needs: replacements that were calculated but not deducted yet. */
export interface PayrollCalcSnapshot {
  pending_replacements_not_deducted?: PayrollDeductionDetail[];
}

export interface PayrollItem {
  id: number;
  payroll_run_id: number;
  period: string;
  employee_id: number;
  employee_number: string | null;
  employee_name: string | null;
  work_assignment_id?: number | null;
  company_name: string | null;
  workplace_name: string | null;
  eligible_days: number;
  days_in_month: number;
  gross: string;
  replacement_deduction: string;
  other_deductions: string;
  adjustment: string;
  net: string;
  payment_status: PayrollPaymentStatus;
  paid_at: string | null;
  /** Earlier payments of this line that were voided (each kept in the ledger with a linked reversal). */
  payment_voids?: { id: number; amount: string; reason: string; voided_at: string | null }[];
  deductions?: PayrollItemDeduction[];
  calc_snapshot?: PayrollCalcSnapshot | null;
}

export interface PayrollRun {
  id: number;
  period: string;
  status: PayrollRunStatus;
  currency: string;
  item_count: number;
  totals: PayrollRunTotals;
  warnings: string[];
  generated_at: string | null;
  reviewed_at: string | null;
  approved_at: string | null;
  finalized_at: string | null;
  generated_by?: string | null;
  reviewed_by?: string | null;
  approved_by?: string | null;
  finalized_by?: string | null;
  items?: PayrollItem[];
}

export type PayrollAdjustmentKind = 'correction' | 'reversal';

export interface CreatePayrollAdjustmentPayload {
  kind: PayrollAdjustmentKind;
  /** Required for a correction: signed dollars (positive pays more, negative recovers). */
  amount?: number;
  reason: string;
  /** Payroll month (YYYY-MM) that will carry the adjustment - must be later than the corrected month. */
  applies_in_period: string;
}

export interface PayrollAdjustmentResult {
  id: number;
  kind: PayrollAdjustmentKind;
  amount_cents: number;
  applies_in_period: string;
  status: string;
}
