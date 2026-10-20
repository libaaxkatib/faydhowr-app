export type AssignmentType = 'permanent' | 'replacement';
export type CompanyAssignmentStatus = 'active' | 'ended';

export interface CompanyEmployeeSalary {
  amount: string;
  currency: string;
  frequency: string;
}

export interface CompanyEmployeePayroll {
  calculated: {
    currency: string;
    gross_cents: number;
    deductions_cents: number;
    adjustment_cents: number;
    net_cents: number;
    eligible_days: number;
    source: string;
    is_estimate: boolean;
    run_status: string | null;
  } | null;
  payment?: { status: string; paid_at: string | null } | null;
  unavailable_reason: string | null;
}

export interface CompanyEmployeeRow {
  assignment_id: number;
  employee_id: number;
  employee_number: string | null;
  full_name: string | null;
  employee_status: string | null;
  position_id: number | null;
  position_name: string | null;
  work_location_name: string | null;
  assignment_type: AssignmentType;
  /** Day or night shift of THIS assignment; null = not recorded (never assumed). */
  shift: 'day' | 'night' | null;
  replaces_assignment_id: number | null;
  /** A Temporary worker confirmed as a Replacement: no payroll line is calculated for these days until the classification is corrected. */
  needs_correction: boolean;
  needs_correction_reason: string | null;
  /** For a Replacement: the Permanent employee whose position it holds and that employee's salary (the salary basis, and what Return proposes). */
  replaces: { assignment_id: number; employee_id: number; employee_number: string | null; full_name: string | null; salary: CompanyEmployeeSalary } | null;
  start_date: string;
  end_date: string | null;
  status: CompanyAssignmentStatus;
  /** The same employee's assignments at OTHER companies in this month (only with multiple-company assignments on). */
  also_assigned_at: { assignment_id: number; company_id: number | null; company_name: string | null }[];
  salary: CompanyEmployeeSalary;
  salary_changed_in_period: boolean;
  payable_days: number;
  days_in_month: number;
  attendance: { recorded: number; present: number; late: number; absent: number };
  /** Present only when the caller holds hr.payroll.view. */
  payroll?: CompanyEmployeePayroll;
}

export interface CompanyEmployeesSummary {
  employee_count: number;
  active_count: number;
  ended_count: number;
  permanent_count: number;
  replacement_count: number;
  days_in_month: number;
  payable_days_total: number;
  attendance_recorded_total: number;
  absent_marks_total: number;
  contracted_monthly_salary_cents_by_currency: Record<string, number>;
  contracted_non_monthly_count: number;
  calculated_pay_by_currency?: Record<string, { gross_cents: number; net_cents: number }>;
  calculated_pay_unavailable_count?: number;
  calculated_pay_source?: string | null;
  calculated_pay_is_estimate?: boolean;
  paid_net_cents_by_currency?: Record<string, number>;
  payroll_warnings?: string[];
}

export interface CompanyEmployeesResponse {
  period: string;
  company: { id: number; name: string };
  employees: CompanyEmployeeRow[];
  summary: CompanyEmployeesSummary;
}

export interface CompanyEmployeeCandidate {
  id: number;
  employee_number: string;
  full_name: string;
  status: string;
  phone: string | null;
  category: string | null;
  /** 'temporary' workers are paid per verified day of Temporary cover, not as a Replacement who takes over a position. */
  employment_type?: string | null;
  current_companies: string[];
  already_here: boolean;
  eligible: boolean;
  ineligible_reason: string | null;
}

export interface CompanyEmployeeFilters {
  period: string;
  status?: CompanyAssignmentStatus | '';
  position_id?: number | '';
  assignment_type?: AssignmentType | '';
  search?: string;
}

export interface CompanyHistoryEvent {
  at: string | null;
  by: string | null;
  event: string;
  description: string;
  assignment_id: number;
  employee_id: number | null;
  employee_number: string | null;
  employee_name: string | null;
  details: Record<string, unknown>;
}

export interface CompanyChainEntry {
  assignment_id: number;
  employee_id: number;
  employee_number: string | null;
  employee_name: string | null;
  position_name: string | null;
  assignment_type: AssignmentType;
  shift: 'day' | 'night' | null;
  status: string;
  start_date: string;
  end_date: string | null;
  replaces_assignment_id: number | null;
}

export interface CompanyHistory {
  events: CompanyHistoryEvent[];
  chain: CompanyChainEntry[];
}

export interface AssignmentPayload {
  employee_id: number;
  assignment_type: AssignmentType;
  position_id: number;
  start_date: string;
  salary_amount: number;
  salary_currency: string;
  salary_frequency: string;
  notes?: string | null;
  /** Optional: day or night. Left out = not recorded. */
  shift?: 'day' | 'night' | null;
  correction_reason?: string | null;
}

export interface ReplacePayload {
  replacement_employee_id: number;
  replacement_date: string;
  original_last_day?: string | null;
  assignment_type: AssignmentType;
  position_id?: number | null;
  /** Sent only for a Permanent successor. A Replacement has no salary of its own: the server uses the replaced Permanent's. */
  salary_amount?: number;
  salary_currency?: string;
  salary_frequency?: string;
  /** HR's decision to proceed when the replacement is a Temporary worker. */
  acknowledge_temporary_as_replacement?: boolean;
  reason?: string | null;
  notes?: string | null;
  /** Left out = the replacement takes over the shift of the position it replaces. */
  shift?: 'day' | 'night' | null;
  correction_reason?: string | null;
}

export interface ReturnPayload {
  return_date: string;
  position_id?: number | null;
  /** Always sent: HR confirms or edits the salary of the returning employee, it is never assumed unchanged. */
  salary_amount: number;
  salary_currency: string;
  salary_frequency: string;
  notes?: string | null;
  correction_reason?: string | null;
}

export interface EditPayload {
  position_id?: number | null;
  assignment_type?: AssignmentType;
  start_date?: string;
  salary_amount?: number;
  salary_currency?: string;
  salary_frequency?: string;
  salary_effective_from?: string;
  salary_change_reason?: string | null;
  notes?: string | null;
  /** Sent only when HR changed it; null clears it (not recorded). Never affects pay. */
  shift?: 'day' | 'night' | null;
  /** Needed (and only accepted from a Super Admin) when the change alters an approved or finalized payroll month. */
  correction_reason?: string | null;
}
