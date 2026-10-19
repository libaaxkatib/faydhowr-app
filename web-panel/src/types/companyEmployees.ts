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
  replaces_assignment_id: number | null;
  start_date: string;
  end_date: string | null;
  status: CompanyAssignmentStatus;
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
}

export interface ReplacePayload {
  replacement_employee_id: number;
  replacement_date: string;
  original_last_day?: string | null;
  assignment_type: AssignmentType;
  position_id?: number | null;
  salary_amount: number;
  salary_currency: string;
  salary_frequency: string;
  reason?: string | null;
  notes?: string | null;
}

export interface ReturnPayload {
  return_date: string;
  position_id?: number | null;
  salary_amount?: number;
  salary_currency?: string;
  salary_frequency?: string;
  notes?: string | null;
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
}
