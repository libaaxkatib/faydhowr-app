/** Month-end payroll runs (HR payroll). Currency is always USD and money values arrive as strings such as '300.00'. */

export type PayrollRunStatus = 'draft' | 'reviewed' | 'approved' | 'finalized';
export type PayrollPaymentStatus = 'unpaid' | 'paid';
export type PayrollDeductionKind = 'replacement' | 'penalty' | 'advance' | 'absence' | 'adjustment' | 'unpaid_leave';

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
