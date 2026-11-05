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
  status: 'open' | 'recovered' | 'written_off' | 'settled' | 'superseded';
  /** Set when the company's payroll for that month was reopened and rebuilt: the correction no longer applies (kept for the history). */
  superseded_at?: string | null;
  supersede_reason?: string | null;
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
  /** The company section this line belongs to; null for a line of a month created before company-level finalization. */
  section_id?: number | null;
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

/**
 * One company's part of a payroll month, with its own PAYROLL status (draft -> reviewed -> finalized), its own stamps and totals, and what has been paid /
 * is outstanding for it. Approval is payment (owner decision 2026-11-04): `payment_status` is unpaid / partially_paid / approved, and a company is approved
 * only when it is finalized and fully paid. Amounts are USD strings from the server, the cents next to them.
 */
export type CompanyPaymentStatus = 'unpaid' | 'partially_paid' | 'approved' | 'overpaid';

/** The month's payment / approval picture, derived by the server from the payments (it is not a status of the run). */
export interface PayrollMonthApproval {
  total_companies: number;
  approved_companies: number;
  /** Finalized and nothing paid yet. */
  unpaid_companies: number;
  partially_paid_companies: number;
  /** Finalized and paid MORE than the corrected payroll says: needs a reconciliation before it can be approved. */
  overpaid_companies?: number;
  unreconciled_overpaid_cents?: number;
  /** Not finalized yet: cannot be paid, so cannot be approved. */
  not_finalized_companies: number;
  total_cents: number;
  paid_cents: number;
  outstanding_cents: number;
  total: string;
  paid: string;
  outstanding: string;
  /** True only when there are companies and every one of them is approved (fully paid). */
  fully_approved: boolean;
}

export interface PayrollRunSection {
  id: number;
  client_company_id: number | null;
  /** Null for the lines whose assignment names no company. */
  company_name: string | null;
  category: 'company' | 'guri' | null;
  status: PayrollRunStatus;
  employee_count: number;
  item_count: number;
  gross: string;
  net: string;
  /** What there is to pay (the lines with a positive net). */
  payable: string;
  payable_cents: number;
  paid: string;
  paid_cents: number;
  outstanding: string;
  outstanding_cents: number;
  /** Derived from the payments: Approved only when finalized and fully paid. Never changes the payroll status. */
  payment_status: CompanyPaymentStatus;
  approved: boolean;
  /** Finalized and something is still owed: "Approve Paid" / "Approve Partial" are offered. */
  can_pay: boolean;
  /** Paid more than the corrected payroll says and not yet settled by a reconciliation (cents / dollars). */
  overpaid_cents?: number;
  unreconciled_overpaid_cents?: number;
  unreconciled_overpaid?: string;
  /** Every reopening of this company's FINALIZED payroll: who, in which role, when, why and what it held. */
  reopenings?: SectionReopening[];
  reviewed_at: string | null;
  reviewed_by: string | null;
  approved_at: string | null;
  approved_by: string | null;
  finalized_at: string | null;
  finalized_by: string | null;
  /** When and by whom THIS company was generated (company-by-company generation; there is no month-wide Generate). */
  generated_at?: string | null;
  generated_by?: string | null;
  /** Non-blocking warnings found at generation (Temporary Replacement conditions A-D). */
  generation_warnings?: GenerationWarning[];
  /** Generated, but nobody in this company has anything payable. */
  nothing_payable?: boolean;
  /** Payments are recorded for this company: only a Super Admin may reopen it after finalization. */
  has_payments?: boolean;
}

export interface PayrollRun {
  id: number;
  period: string;
  /** The month's own status: its least advanced company's (a month is finalized only when every company is). */
  status: PayrollRunStatus;
  /** True when the month has company sections (a month created before company-level finalization has none and moves as a whole). */
  sectioned?: boolean;
  sections_total?: number;
  sections_finalized?: number;
  /** Some, but not all, companies are finalized. */
  is_partially_finalized?: boolean;
  /** The companies of the month (only in the single-month view). */
  sections?: PayrollRunSection[];
  /** Only in the single-month view (null while the lines are not loaded). */
  approval?: PayrollMonthApproval | null;
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

/** One payment recorded for a whole workplace (never edited or deleted: a mistaken one is voided and stays listed). Amounts are USD. */
export interface WorkplacePaymentRecord {
  id: number;
  amount: string;
  amount_cents: number;
  payment_date: string;
  note: string | null;
  /** Bank / mobile-money transaction id or receipt number; optional, never required. */
  reference?: string | null;
  recorded_by: string | null;
  recorded_at: string | null;
  voided: boolean;
  voided_at: string | null;
  void_reason: string | null;
  voided_by: string | null;
}

export type WorkplacePaymentStatus = 'unpaid' | 'partial' | 'paid';
// (a workplace's `can_record` is true when ITS company's payroll is finalized)

/** What one workplace (a company or guri, or one of its work locations) is owed and has been paid for a payroll month. */
export interface WorkplacePayrollSummary {
  key: string;
  /** False for the lines that name no workplace: they can only be paid line by line. */
  payable: boolean;
  /** This workplace's company is finalized, so payments can be recorded for it. */
  can_record: boolean;
  client_company_id: number | null;
  work_location_id: number | null;
  company_name: string | null;
  workplace_name: string | null;
  category: 'company' | 'guri' | null;
  employee_count: number;
  total: string;
  total_cents: number;
  paid: string;
  paid_cents: number;
  outstanding: string;
  outstanding_cents: number;
  status: WorkplacePaymentStatus;
  payments: WorkplacePaymentRecord[];
}

export interface WorkplacePayments {
  payroll_run_id: number;
  period: string;
  run_status: PayrollRunStatus;
  /** Payments are recorded for a FINALIZED company only; true as soon as one company (or the whole month) is. Each workplace says so itself in `can_record`. */
  can_record: boolean;
  totals: { total: string; paid: string; outstanding: string };
  workplaces: WorkplacePayrollSummary[];
}

/** "Approve Paid" (mode full: the whole outstanding balance) or "Approve Partial" (an amount in dollars) for one finalized company. */
export interface RecordCompanyPaymentPayload {
  mode: 'full' | 'partial';
  /** Dollars, only for a partial payment. */
  amount?: string;
  payment_date: string;
  note?: string | null;
  /** Optional bank / mobile-money transaction id or receipt number (at most 100 characters); left out when empty. */
  reference?: string;
  idempotency_key: string;
}

export interface RecordCompanyPaymentResult {
  replayed: boolean;
  payment_ids: number[];
  company: { section_id: number; payment_status: CompanyPaymentStatus; approved: boolean; paid_cents: number; outstanding_cents: number } | null;
  month: Omit<PayrollMonthApproval, 'total' | 'paid' | 'outstanding'>;
}

export interface RecordWorkplacePaymentPayload {
  client_company_id: number | null;
  work_location_id: number | null;
  mode: 'full' | 'partial';
  /** Dollars, only for a partial payment. */
  amount?: string;
  payment_date: string;
  note?: string | null;
  idempotency_key: string;
}

/** One reopening of a finalized company payroll (owner decision 2026-11-04). Payments are never touched by it. */
export interface SectionReopening {
  id: number;
  reason: string;
  reopened_by: string | null;
  reopened_by_role: string | null;
  reopened_at: string | null;
  paid_cents_at_reopen: number;
  payment_count_at_reopen: number;
  net_before: string;
  refinalized_at: string | null;
  refinalized_by: string | null;
  net_after: string | null;
}

/** How the excess of an overpaid company was settled. */
export type ReconciliationMethod = 'returned' | 'carried_forward' | 'other';

export interface ReconcileCompanyPayload {
  mode: 'full' | 'partial';
  /** Dollars, only for a partial settlement. */
  amount?: string;
  method: ReconciliationMethod;
  reason: string;
  settled_on: string;
  idempotency_key: string;
}

export interface ReconcileCompanyResult {
  replayed: boolean;
  reconciliation_ids: number[];
  company: { section_id: number; payment_status: CompanyPaymentStatus; approved: boolean; paid_cents: number; outstanding_cents: number } | null;
}

/** A company closure and, when it was reopened, its reopening. */
export interface CompanyClosureView {
  closed: boolean;
  closure_id?: number;
  status?: 'closed' | 'reopened';
  effective_date?: string;
  reason?: string;
  closed_by?: string | null;
  closed_by_role?: string | null;
  closed_at?: string | null;
  summary?: { assignments_ended: number; assignments_cancelled: number; assignments_left_as_they_were: number; covers_to_review: { cover_id: number; position_assignment_id: number; replacement_employee_id: number | null; start_date: string | null; end_date: string | null; status: string }[] } | null;
  reopened_at?: string | null;
  reopened_by?: string | null;
  reopen_reason?: string | null;
  /** The open closure's assignments with whether each can be restored (only on the closure read). */
  assignments?: ClosureAssignmentRow[];
  history?: CompanyClosureView[];
}

export interface ClosureAssignmentRow {
  closure_assignment_id: number;
  assignment_id: number;
  employee_id: number;
  employee_name: string | null;
  employee_number: string | null;
  position: string | null;
  action: 'ended' | 'cancelled';
  ended_on: string | null;
  restores_to_end_date: string | null;
  restorable: boolean;
  conflict: string | null;
}

/**
 * A non-blocking Temporary Replacement warning: A = a Temporary worker registered as a Replacement (no payroll line is calculated for those days),
 * B = Temporary cover with unverified workdays, C = Temporary cover cost not approved, D = payroll-calculation Temporary warnings.
 * `detail` and `what_remains` are the server's own English texts and are shown as given.
 */
export type GenerationWarningCondition = 'A' | 'B' | 'C' | 'D';

export interface GenerationWarning {
  condition: GenerationWarningCondition;
  blocking: false;
  client_company_id: number | null;
  company_name: string | null;
  employee_number?: string | null;
  employee_name?: string | null;
  assignment_id?: number;
  replacement_id?: number;
  detail: string;
  what_remains: string;
}

export interface CompanyWarnings {
  period: string;
  client_company_id: number | null;
  company_name: string | null;
  blocking: false;
  generated: boolean;
  section_status: string | null;
  warnings: GenerationWarning[];
}

/** Where one company stands for a payroll month. `finalized` means finalized and nothing paid yet. */
export type CompanyPayrollState = 'not_generated' | 'draft' | 'reviewed' | 'finalized' | 'partially_paid' | 'paid' | 'overpaid';

export interface PayrollOverviewCompany {
  client_company_id: number | null;
  company_name: string | null;
  category: 'company' | 'guri' | null;
  state: CompanyPayrollState;
  generated: boolean;
  nothing_payable: boolean;
  /** Locked by an earlier version: shown as reviewed, and must be finalized before it can be paid. */
  legacy_approved: boolean;
  section_id: number | null;
  section_status: string | null;
  generated_at: string | null;
  generation_warning_count: number;
  /** The three amounts are null while the company is not generated: never 0, never an estimate. */
  payroll_total_cents: number | null;
  payments_received_cents: number | null;
  outstanding_cents: number | null;
  payroll_total: string | null;
  payments_received: string | null;
  outstanding: string | null;
}

export interface PayrollOverviewSummary {
  companies_assigned: number;
  companies_generated: number;
  not_generated: number;
  draft: number;
  reviewed: number;
  finalized_unpaid: number;
  partially_paid: number;
  paid: number;
  overpaid: number;
  payroll_total_cents: number;
  payments_received_cents: number;
  outstanding_cents: number;
  payroll_total: string;
  payments_received: string;
  outstanding: string;
  all_companies_paid: boolean;
}

export interface PayrollOverview {
  period: string;
  run_id: number | null;
  companies: PayrollOverviewCompany[];
  summary: PayrollOverviewSummary;
}

/** The section status the screen showed when Generate was pressed; a different status answers 409 PAYROLL_SECTION_CHANGED. */
export type GenerateExpectedStatus = 'draft' | 'reviewed';
