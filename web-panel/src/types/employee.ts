export type EmployeeStatus = 'applicant' | 'recruitment' | 'practical' | 'waiting' | 'approved' | 'active' | 'inactive';

export interface Department {
  id: number;
  name: string;
  description: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface Position {
  id: number;
  department_id: number | null;
  department_name: string | null;
  name: string;
  description: string | null;
}

export interface EmployeeCategory {
  id: number;
  name: string;
  description: string | null;
}

export interface EmployeeStatusHistoryEntry {
  id: number;
  from_status: EmployeeStatus | null;
  to_status: EmployeeStatus;
  changed_by: string | null;
  note: string | null;
  created_at: string;
}

export interface EmployeePracticalAssessment {
  id: number;
  assessed_by: string | null;
  assessment_date: string;
  result: 'pass' | 'fail' | 'pending';
  notes: string | null;
  created_at: string;
}

export interface EmployeeDocument {
  id: number;
  file_name: string;
  file_type: string;
  file_size: number;
  uploaded_by: string | null;
  created_at: string;
}

export type LocationType = 'client' | 'office';
export type ClientStatus = 'active' | 'inactive';
export type WorkAssignmentStatus = 'active' | 'ended' | 'cancelled';
export type SalaryFrequency = 'monthly' | 'weekly' | 'daily';

export interface ClientCompany {
  id: number;
  name: string;
  contact_person: string | null;
  phone: string | null;
  location: string | null;
  status: ClientStatus;
  notes: string | null;
  work_locations?: WorkLocation[];
  created_at?: string;
  updated_at?: string;
}

export interface WorkLocation {
  id: number;
  client_company_id: number | null;
  client_company_name: string | null;
  location_type: LocationType;
  name: string;
  location: string | null;
  contact_person: string | null;
  phone: string | null;
  capacity: number | null;
  active_assignments_count: number;
  available_slots: number | null;
  status: ClientStatus;
  notes: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface WorkAssignment {
  id: number;
  employee_id: number;
  work_location_id: number;
  work_location_name: string;
  location_type: LocationType;
  client_company_name: string | null;
  position_id: number | null;
  position_name: string | null;
  start_date: string;
  end_date: string | null;
  salary_amount: string;
  salary_currency: string;
  salary_frequency: SalaryFrequency;
  status: WorkAssignmentStatus;
  notes: string | null;
  created_at: string;
}

export interface CreateWorkAssignmentPayload {
  work_location_id: number;
  position_id?: number | null;
  start_date: string;
  end_date?: string | null;
  salary_amount: number;
  salary_currency: string;
  salary_frequency: SalaryFrequency;
  notes?: string | null;
  override_capacity?: boolean;
}

export type UpdateWorkAssignmentPayload = Partial<Omit<CreateWorkAssignmentPayload, 'work_location_id' | 'override_capacity'>>;

export interface EndWorkAssignmentPayload {
  end_date: string;
  note?: string | null;
}

export interface Employee {
  id: number;
  employee_number: string;
  full_name: string;
  phone: string;
  alternate_phone: string | null;
  location: string | null;
  age: number | null;
  marital_status: string | null;
  lives_with: string | null;
  reference_name: string | null;
  employee_category_id: number;
  employee_category_name: string | null;
  department_id: number | null;
  department_name: string | null;
  position_id: number | null;
  position_name: string | null;
  status: EmployeeStatus;
  guarantor_confirmed_at: string | null;
  application_date: string;
  joining_date: string | null;
  experience: string | null;
  training_fee_amount: string | null;
  training_fee_status: string | null;
  source: string | null;
  notes: string | null;
  created_at: string;
  status_histories?: EmployeeStatusHistoryEntry[];
  practical_assessments?: EmployeePracticalAssessment[];
  documents?: EmployeeDocument[];
  active_work_assignments?: WorkAssignment[];
  work_assignments?: WorkAssignment[];
}

export interface ListEmployeesParams {
  search?: string;
  status?: EmployeeStatus;
  employee_category_id?: number;
  department_id?: number;
  position_id?: number;
  page?: number;
  per_page?: number;
}

export interface CreateEmployeePayload {
  full_name: string;
  phone: string;
  alternate_phone?: string | null;
  location?: string | null;
  age?: number | null;
  marital_status?: string | null;
  lives_with?: string | null;
  reference_name?: string | null;
  employee_category_id: number;
  department_id?: number | null;
  position_id?: number | null;
  application_date: string;
  experience?: string | null;
  training_fee_amount?: number | null;
  training_fee_status?: string | null;
  source?: string | null;
  notes?: string | null;
}

export type UpdateEmployeePayload = Partial<Omit<CreateEmployeePayload, 'application_date'>>;

export interface HrDashboardData {
  total_employees: number;
  active_employees: number;
  inactive_employees: number;
  new_applicants: number;
  practical: number;
  waiting: number;
  approved: number;
  category_breakdown: { id: number; name: string; total: number }[];
}

export interface HrReportsSummary {
  range: { from: string; to: string };
  registrations_in_range: number;
  status_breakdown: Partial<Record<EmployeeStatus, number>>;
  category_breakdown: { id: number; name: string; total: number }[];
  department_breakdown: { id: number; name: string; total: number }[];
  workplace_breakdown: {
    id: number;
    name: string;
    location_type: LocationType;
    client_company_name: string | null;
    capacity: number | null;
    total: number;
  }[];
  company_salary_totals: { id: number; name: string; total_salary: string; total_assignments: number }[];
}

export interface HrReportsSummaryParams {
  from?: string;
  to?: string;
  client_company_id?: number;
  work_location_id?: number;
  assignment_status?: WorkAssignmentStatus;
}
