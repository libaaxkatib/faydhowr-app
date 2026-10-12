export type EmployeeStatus = 'applicant' | 'recruitment' | 'practical' | 'waiting' | 'approved' | 'active' | 'inactive';
export type EmployeeGender = 'male' | 'female';

/**
 * The fine-grained pre-Waiting pipeline (docs/HRM_MARKETING_SRS.md HR §4/§12),
 * tracked independently of EmployeeStatus — only meaningful while
 * status === 'applicant'. See HRM Phase 1 plan's architecture note.
 */
export type EmployeePipelineStage =
  | 'damiin_needed'
  | 'contract_pending'
  | 'uniform_pending'
  | 'need_training'
  | 'need_practical'
  | 'practical_repeat'
  | 'rejected';

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
  /** Set when a Super Admin changed the status outside the normal lifecycle; the reason is mandatory. */
  is_override?: boolean;
  override_reason?: string | null;
  source?: string | null;
  created_at: string;
}

/** 'pass'/'fail'/'pending' are legacy values kept for old data — new decisions only ever use the three canonical outcomes. */
export type PracticalAssessmentResult = 'pass' | 'fail' | 'pending' | 'approved' | 'rejected' | 'ku_celis_practical';
/** The three canonical outcomes a new Practical Decision may record (docs/HRM_MARKETING_SRS.md HR §10-11). */
export type PracticalDecision = 'approved' | 'rejected' | 'ku_celis_practical';

export interface EmployeePracticalAssessment {
  id: number;
  employee_id: number;
  employee_name?: string | null;
  practical_batch_id: number | null;
  attempt_number: number | null;
  assessed_by: string | null;
  assessment_date: string;
  result: PracticalAssessmentResult;
  notes: string | null;
  created_at: string;
}

export type EmployeeDocumentVerificationStatus = 'pending' | 'verified' | 'rejected';

export interface EmployeeDocumentCategory {
  id: number;
  name: string;
  description: string | null;
}

export interface EmployeeDocument {
  id: number;
  employee_id: number;
  employee_document_category_id: number | null;
  category_name: string | null;
  file_name: string;
  file_type: string;
  file_size: number;
  document_number: string | null;
  verification_status: EmployeeDocumentVerificationStatus;
  verified_at: string | null;
  verified_by: string | null;
  expiry_date: string | null;
  is_current: boolean;
  superseded_by_document_id: number | null;
  uploaded_by: string | null;
  created_at: string;
}

export type EmployeeContractStatus = 'draft' | 'issued' | 'signed' | 'verified' | 'expired' | 'cancelled';

export interface EmployeeContract {
  id: number;
  employee_id: number;
  contract_type: string;
  contract_number: string | null;
  date_issued: string | null;
  start_date: string | null;
  end_date: string | null;
  status: EmployeeContractStatus;
  signed_date: string | null;
  signed_document_id: number | null;
  signed_document_name: string | null;
  notes: string | null;
  created_by: string | null;
  created_at: string;
}

export type EmployeeUniformStatus = 'pending' | 'purchased' | 'received' | 'confirmed';

export interface EmployeeUniform {
  id: number;
  employee_id: number;
  status: EmployeeUniformStatus;
  purchased_at: string | null;
  received_at: string | null;
  confirmed_at: string | null;
  confirmed_by: string | null;
  notes: string | null;
}

export interface EmployeeGuarantor {
  id: number;
  employee_id: number;
  guarantor_name: string | null;
  guarantor_phone: string | null;
  relationship: string | null;
  other_info: string | null;
  collected_date: string | null;
  verified_at: string | null;
  verified_by: string | null;
  created_by: string | null;
  created_at: string;
}

export type TrainingBatchStatus = 'scheduled' | 'completed' | 'cancelled';
export type TrainingParticipantResult = 'pending' | 'completed' | 'absent';

export interface TrainingBatchParticipant {
  id: number;
  employee_id: number;
  employee_name: string | null;
  result: TrainingParticipantResult;
  notes: string | null;
}

/** Read-only Employee Profile view of one training participation - built from the existing TrainingBatch/TrainingBatchParticipant data, no new entity. */
export interface EmployeeTrainingHistoryEntry {
  id: number;
  training_batch_id: number;
  batch_number: string | null;
  batch_date: string | null;
  start_time: string | null;
  end_time: string | null;
  team_or_group: string | null;
  trainer_name: string | null;
  location: string | null;
  result: TrainingParticipantResult;
  notes: string | null;
}

export interface TrainingBatch {
  id: number;
  batch_number: string;
  batch_date: string;
  start_time: string | null;
  end_time: string | null;
  team_or_group: string | null;
  trainer_admin_id: number | null;
  trainer_name: string | null;
  location: string | null;
  status: TrainingBatchStatus;
  notes: string | null;
  created_by: string | null;
  participants: TrainingBatchParticipant[];
  created_at: string;
}

export type PracticalBatchStatus = 'scheduled' | 'completed' | 'cancelled';

export interface PracticalBatch {
  id: number;
  batch_number: string;
  batch_date: string;
  team_or_group: string | null;
  trainer_admin_id: number | null;
  trainer_name: string | null;
  location: string | null;
  status: PracticalBatchStatus;
  notes: string | null;
  created_by: string | null;
  assessments: EmployeePracticalAssessment[];
  created_at: string;
}

export type LocationType = 'client' | 'office';
export type ClientStatus = 'active' | 'inactive';
export type WorkAssignmentStatus = 'active' | 'ended' | 'cancelled';
export type SalaryFrequency = 'monthly' | 'weekly' | 'daily';

export type ClientCompanyCategory = 'company' | 'guri';

export interface ClientCompanyAlias {
  alias: string;
  source: string | null;
  source_reference: string | null;
}

/** One uploaded agreement file for a client company. The newest upload is the current one; older ones are kept as history. */
export interface ClientCompanyAgreement {
  id: number;
  client_company_id: number;
  file_name: string;
  file_type: string;
  file_size: number;
  notes: string | null;
  uploaded_by: string | null;
  uploaded_at: string;
}

export interface ClientCompany {
  id: number;
  name: string;
  category: ClientCompanyCategory;
  category_label: string;
  aliases?: ClientCompanyAlias[];
  agreements_count?: number;
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
  client_company_id: number | null;
  client_company_name: string | null;
  /** Null until HR sets the workplace - the workplace is optional and can be added later. */
  work_location_id: number | null;
  work_location_name?: string | null;
  /** 'client' whenever there is no location (the assignment is to a company). */
  location_type: LocationType;
  /** The Permanent assignment this one takes over from (null when it replaces nobody). */
  replaces_assignment_id?: number | null;
  /** Calendar days nobody was paid between the previous employee's last day and this start (0 = back to back). */
  succession_gap_days?: number;
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
  /** Required unless work_location_id is given (the Fayadhowr Office has no company). */
  client_company_id?: number | null;
  /** Optional - the workplace can be added later. */
  work_location_id?: number | null;
  /** Optional: the ended Permanent assignment this one takes over from. Overlapping days are refused. */
  replaces_assignment_id?: number | null;
  position_id?: number | null;
  start_date: string;
  end_date?: string | null;
  salary_amount: number;
  salary_currency: string;
  salary_frequency: SalaryFrequency;
  notes?: string | null;
  override_capacity?: boolean;
}

export type UpdateWorkAssignmentPayload = Partial<Omit<CreateWorkAssignmentPayload, 'client_company_id' | 'work_location_id'>> & {
  /** Sets the workplace once, only when none is set yet. */
  work_location_id?: number;
  /** Date a new salary starts to apply (defaults to today on the server). Earlier salaries are kept as history. */
  salary_effective_from?: string;
  salary_change_reason?: string | null;
};

export interface EndWorkAssignmentPayload {
  end_date: string;
  note?: string | null;
}

export type WorkforceRequestStatus = 'open' | 'partially_filled' | 'fulfilled' | 'cancelled';

export interface WorkforceRequestMatch {
  id: number;
  employee_id: number;
  employee_name: string | null;
  employee_number: string | null;
  confirmed_by: string | null;
  notes: string | null;
  created_at: string;
}

export interface WorkforceRequest {
  id: number;
  work_location_id: number | null;
  client_company_id: number | null;
  work_location_name: string | null;
  client_company_name: string | null;
  employee_category_id: number | null;
  employee_category_name: string | null;
  position_id: number | null;
  position_name: string | null;
  gender_requirement: EmployeeGender | null;
  quantity_needed: number;
  matched_count: number;
  status: WorkforceRequestStatus;
  requested_date: string;
  notes: string | null;
  created_by: string | null;
  matches: WorkforceRequestMatch[];
  created_at: string;
}

export interface CreateWorkforceRequestPayload {
  /** Required unless work_location_id is given (the Fayadhowr Office has no company). */
  client_company_id?: number | null;
  work_location_id?: number | null;
  employee_category_id?: number | null;
  position_id?: number | null;
  gender_requirement?: EmployeeGender | null;
  quantity_needed?: number;
  requested_date: string;
  notes?: string | null;
}

export type UpdateWorkforceRequestPayload = Partial<Omit<CreateWorkforceRequestPayload, 'client_company_id' | 'work_location_id'>> & {
  /** Only to complete a missing workplace; an existing one is never swapped. */
  work_location_id?: number;
};

/** A Waiting employee annotated with advisory match flags against one workforce request - filter+sort, not a numeric score. */
export interface WaitingCandidate {
  id: number;
  employee_number: string;
  full_name: string;
  phone: string;
  gender: EmployeeGender | null;
  location: string | null;
  employee_category_id: number | null;
  employee_category_name: string | null;
  position_id: number | null;
  position_name: string | null;
  waiting_since: string | null;
  gender_match: boolean;
  category_match: boolean;
  position_match: boolean;
}

export type EmployeeSeparationReason = 'resigned' | 'terminated' | 'contract_ended' | 'other';

export interface EmployeeSeparation {
  id: number;
  reason: EmployeeSeparationReason;
  reason_label: string;
  separation_date: string;
  rehire_eligible: boolean;
  notes: string | null;
  separated_by: string | null;
  created_at: string;
}

export interface MarkEmployeeSeparatedPayload {
  reason: EmployeeSeparationReason;
  separation_date: string;
  rehire_eligible?: boolean;
  notes?: string | null;
}

export type EmployeeHistoricalCompletionStage = 'training' | 'practical' | 'uniform';

/** Issue #12 — historical evidence/context only, never live operational workflow state. */
export interface EmployeeHistoricalCompletion {
  id: number;
  stage: EmployeeHistoricalCompletionStage;
  source: string;
  source_reference: string | null;
  source_notes: string | null;
  completed_at: string | null;
}

export type TemporaryReplacementStatus = 'active' | 'ended';

export interface TemporaryReplacementPayment {
  id: number;
  payment_date: string;
  amount: string;
  notes: string | null;
  paid_by: string | null;
  created_at: string;
}

export interface TemporaryReplacement {
  id: number;
  work_assignment_id: number;
  replaced_employee_id: number | null;
  replaced_employee_name: string | null;
  work_location_name: string | null;
  client_company_name: string | null;
  replacement_employee_id: number;
  replacement_employee_name: string | null;
  start_date: string;
  end_date: string | null;
  /** Legacy: the rate is now derived from the covered employee's salary, so this may be null. */
  daily_rate: string | null;
  currency: string | null;
  reason: string | null;
  status: TemporaryReplacementStatus;
  approved: boolean;
  approved_at: string | null;
  notes: string | null;
  created_by: string | null;
  payments: TemporaryReplacementPayment[];
  total_paid: string;
  created_at: string;
}

export interface CreateTemporaryReplacementPayload {
  work_assignment_id: number;
  replacement_employee_id: number;
  start_date: string;
  end_date?: string | null;
  reason?: string | null;
  notes?: string | null;
}

export type TemporaryReplacementWorkdayStatus = 'recorded' | 'verified' | 'void';

export interface TemporaryReplacementWorkday {
  id: number;
  temporary_replacement_id: number;
  work_date: string;
  status: TemporaryReplacementWorkdayStatus;
  notes: string | null;
  recorded_by: string | null;
  verified_at: string | null;
  verified_by: string | null;
  voided_at: string | null;
  void_reason: string | null;
}

export interface RecordReplacementWorkdaysPayload {
  dates: string[];
  verify?: boolean;
  notes?: string | null;
}

/** Month cost preview for one replacement. Only verified days are charged. */
export interface TemporaryReplacementCost {
  verified_days: number;
  /** A number like '10.0000', or a plain-text note such as 'varies...' when the covered salary changed during the month. */
  daily_rate: string;
  amount: string;
  currency: string;
  charged_dates: string[];
  ignored: {
    unverified: number;
    outside_window_or_month: number;
    duplicates: number;
  };
  approved: boolean;
}

export interface EndTemporaryReplacementPayload {
  end_date: string;
  notes?: string | null;
}

export interface RecordTemporaryReplacementPaymentPayload {
  payment_date: string;
  amount: number;
  notes?: string | null;
}

export type AttendanceStatus = 'present' | 'absent' | 'late';

export interface EmployeeAttendance {
  id: number;
  date: string;
  status: AttendanceStatus;
  notes: string | null;
  recorded_by: string | null;
  created_at: string;
}

export interface MarkEmployeeAttendancePayload {
  date: string;
  status: AttendanceStatus;
  notes?: string | null;
}

/** A daily roster row - docs/HRM_MARKETING_SRS.md HR Phase 4. attendance_status is null when not yet marked for the requested date. */
export interface AttendanceRosterEntry {
  id: number;
  employee_number: string;
  full_name: string;
  employee_category_name: string | null;
  position_name: string | null;
  attendance_status: AttendanceStatus | null;
  attendance_notes: string | null;
}

export type LeaveType = 'annual' | 'sick' | 'unpaid' | 'other';

export type LeaveApprovalStatus = 'pending' | 'approved' | 'rejected';

export interface EmployeeLeave {
  id: number;
  employee_id?: number;
  employee_name?: string | null;
  department_name?: string | null;
  leave_type: LeaveType;
  leave_type_label: string;
  start_date: string;
  end_date: string;
  notes: string | null;
  /** Unpaid leave starts pending and reduces pay only once approved. */
  approval_status: LeaveApprovalStatus;
  decided_by?: string | null;
  decided_at?: string | null;
  decision_note?: string | null;
  recorded_by: string | null;
  created_at: string;
}

export interface RecordEmployeeLeavePayload {
  leave_type: LeaveType;
  start_date: string;
  end_date: string;
  notes?: string | null;
}

export type PerformanceRating = 'excellent' | 'good' | 'needs_improvement' | 'poor';

export interface EmployeePerformanceReview {
  id: number;
  employee_id?: number;
  employee_name?: string | null;
  department_name?: string | null;
  review_date: string;
  rating: PerformanceRating;
  rating_label: string;
  notes: string | null;
  reviewed_by: string | null;
  created_at: string;
}

export interface RecordEmployeePerformanceReviewPayload {
  review_date: string;
  rating: PerformanceRating;
  notes?: string | null;
}

export interface EmployeePayment {
  id: number;
  payment_date: string;
  amount: string;
  currency: string;
  notes: string | null;
  paid_by: string | null;
  created_at: string;
}

export interface RecordEmployeePaymentPayload {
  payment_date: string;
  amount: number;
  currency: string;
  notes?: string | null;
}

export interface CurrentSalary {
  amount: string;
  currency: string;
  frequency: SalaryFrequency;
}

export interface EmployeePenalty {
  id: number;
  penalty_date: string;
  reason: string;
  deduction_amount: string;
  currency: string;
  payroll_period: string;
  notes: string | null;
  recorded_by: string | null;
  created_at: string;
}

export interface RecordEmployeePenaltyPayload {
  penalty_date: string;
  reason: string;
  deduction_amount: number;
  currency: string;
  payroll_period: string;
  notes?: string | null;
}

export interface EmployeeAdvance {
  id: number;
  advance_date: string;
  amount: string;
  currency: string;
  payroll_period: string;
  reason: string;
  notes: string | null;
  recorded_by: string | null;
  created_at: string;
}

export interface RecordEmployeeAdvancePayload {
  advance_date: string;
  amount: number;
  currency: string;
  payroll_period: string;
  reason: string;
  notes?: string | null;
}

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5: a pure computed report, never
 * persisted. `overtime` is deliberately absent from the backend payload for
 * every assignment - the frontend renders a static "pending" note only for
 * office-location assignments, never a fabricated number, and no line at
 * all for client-location assignments.
 */
export interface PayrollSummary {
  employee_id: number;
  work_assignment_id: number;
  payroll_period: string;
  /** False when the assignment has no payroll line this month (Temporary employee, cancelled, outside the month). */
  payable: boolean;
  not_payable_reason: string | null;
  location_type: LocationType | null;
  monthly_salary: string;
  currency: string;
  days_in_month: number;
  /** Calendar days paid this month (proration; weekends are paid days). */
  eligible_days: number;
  daily_rate: string;
  /** Salary for the eligible calendar days, before deductions. */
  gross_pay: string;
  absent_days: number;
  absence_deduction: string;
  penalties: { id: number; penalty_date: string | null; reason: string; deduction_amount: string }[];
  penalty_deduction: string;
  advances: { id: number; advance_date: string | null; reason: string; amount: string }[];
  advance_deduction: string;
  replacement_deduction: string;
  replacements: { replacement_id: number; temporary_employee_name: string | null; verified_days: number; amount: string }[];
  unpaid_leave_deduction: string;
  unpaid_leaves: { leave_id: number; days: number; amount: string }[];
  adjustment: string;
  net_payable: string;
  /** Present when the assignment ended inside this month (permanent separation final pay). */
  final_pay: { last_paid_day: string; paid_calendar_days: number } | null;
  /** 'stored_run' = the locked payroll run; 'live_estimate' = calculated now with the payroll engine. */
  source: 'stored_run' | 'live_estimate';
  is_estimate: boolean;
  estimate_note: string | null;
  run_id: number | null;
  run_status: 'draft' | 'reviewed' | 'approved' | 'finalized' | null;
  /** A draft/reviewed run exists for the month but no longer matches the live figures - regenerate it. */
  differs_from_run: boolean;
  warnings: string[];
}

export type EmploymentType = 'permanent' | 'temporary';

export interface Employee {
  id: number;
  employee_number: string;
  full_name: string;
  employment_type: EmploymentType;
  /** Legitimately null for 242 employees migrated from the Excel HR source - never fabricate a value. */
  phone: string | null;
  alternate_phone: string | null;
  location: string | null;
  age: number | null;
  marital_status: string | null;
  lives_with: string | null;
  reference_name: string | null;
  secondary_contact_name: string | null;
  secondary_contact_phone: string | null;
  gender: EmployeeGender | null;
  profile_picture_document_id: number | null;
  employee_category_id: number;
  employee_category_name: string | null;
  /** Home Team: Work Type ("Full Time - Jiif" / "Part Time - Maalin"). Cooking: Specialization ("Cook" / "Cunto & Nadaafad"). Null for every other category. */
  category_specialization: string | null;
  department_id: number | null;
  department_name: string | null;
  position_id: number | null;
  position_name: string | null;
  status: EmployeeStatus;
  pipeline_stage: EmployeePipelineStage | null;
  guarantor_confirmed_at: string | null;
  /** Historical migration data - the population that originally needed Damiin. Never rewritten when Damiin is completed; see damiin_completed for the active-work-queue state. */
  guarantor_needed: boolean;
  /** Computed, never stored: true only when the guarantor is verified AND at least one verified "Guarantor Documents" upload exists. */
  damiin_completed: boolean;
  /** Computed, never stored: true for the 598 people migrated from the CANCELED sheet / RED REGISTRATION rows. Never the same as pipeline_stage === 'rejected' (the live workflow's own outcome) — see Issue #7. */
  is_historical_rejected: boolean;
  waiting_since: string | null;
  is_supervisor: boolean;
  supervisor_since: string | null;
  /** Legitimately null for 234 employees migrated from the Excel HR source - never fabricate a value. */
  application_date: string | null;
  joining_date: string | null;
  experience: string | null;
  training_fee_amount: string | null;
  training_fee_status: string | null;
  source: string | null;
  notes: string | null;
  /** A stored flag, not auto-derived - see the Employee Profile "Profile Completeness" section for the presentation-only missing-fields breakdown. */
  profile_complete: boolean;
  created_at: string;
  status_histories?: EmployeeStatusHistoryEntry[];
  practical_assessments?: EmployeePracticalAssessment[];
  documents?: EmployeeDocument[];
  active_work_assignments?: WorkAssignment[];
  work_assignments?: WorkAssignment[];
  guarantor?: EmployeeGuarantor | null;
  current_contract?: EmployeeContract | null;
  contracts?: EmployeeContract[];
  uniform?: EmployeeUniform | null;
  workforce_request_matches_count?: number;
  separations?: EmployeeSeparation[];
  latest_separation?: EmployeeSeparation | null;
  /** Issue #12 — historical evidence/context only, never live operational workflow state. Empty for anyone without a Green/Waiting List migration source. */
  historical_completions?: EmployeeHistoricalCompletion[];
  current_salary?: CurrentSalary | null;
  attendances?: EmployeeAttendance[];
  leaves?: EmployeeLeave[];
  performance_reviews?: EmployeePerformanceReview[];
  payments?: EmployeePayment[];
  penalties?: EmployeePenalty[];
  advances?: EmployeeAdvance[];
  training_history?: EmployeeTrainingHistoryEntry[];
}

export interface ListEmployeesParams {
  search?: string;
  status?: EmployeeStatus;
  pipeline_stage?: EmployeePipelineStage;
  employee_category_id?: number;
  department_id?: number;
  position_id?: number;
  location?: string;
  is_supervisor?: boolean;
  office_only?: boolean;
  profile_complete?: boolean;
  guarantor_needed?: boolean;
  /** The dedicated Damiin Needed active-work-queue filter - distinct from guarantor_needed. */
  damiin_active?: boolean;
  historical_rejected?: boolean;
  application_date_from?: string;
  application_date_to?: string;
  joining_date_from?: string;
  joining_date_to?: string;
  page?: number;
  per_page?: number;
}

export interface CreateEmployeePayload {
  full_name: string;
  phone: string;
  alternate_phone?: string | null;
  location: string;
  gender: EmployeeGender;
  age?: number | null;
  marital_status?: string | null;
  lives_with?: string | null;
  reference_name?: string | null;
  secondary_contact_name?: string | null;
  secondary_contact_phone?: string | null;
  employee_category_id: number;
  department_id?: number | null;
  position_id?: number | null;
  application_date: string;
  experience?: string | null;
  training_fee_amount?: number | null;
  training_fee_status?: string | null;
  source?: string | null;
  notes?: string | null;
  employment_type?: EmploymentType;
  /** Send true (with duplicate_reason) only after the server answered DUPLICATE_EMPLOYEE_PHONE and HR confirmed this is a different person. */
  confirm_duplicate?: boolean;
  duplicate_reason?: string | null;
}

export type DuplicateMatchReason = 'phone' | 'name';

/** An existing employee that shares a phone number (blocking) or a full name (warning only) with the one being entered. */
export interface DuplicateEmployeeMatch {
  id: number;
  employee_number: string;
  full_name: string;
  status: EmployeeStatus;
  archived: boolean;
  matched_on: DuplicateMatchReason[];
}

export interface DuplicateCheckParams {
  phone?: string;
  alternate_phone?: string;
  full_name?: string;
  ignore_employee_id?: number;
}

export type UpdateEmployeePayload = Partial<Omit<CreateEmployeePayload, 'phone' | 'location' | 'application_date'>> & {
  /** Nullable on update only - 242 real employees legitimately have none; never fabricated. */
  phone?: string | null;
  /** Nullable on update only - some migrated employees legitimately have none; never fabricated. */
  location?: string | null;
  /** Nullable on update only - 234 real employees legitimately have none; never fabricated. */
  application_date?: string | null;
  category_specialization?: string | null;
  joining_date?: string | null;
};

export interface HrDashboardData {
  total_employees: number;
  active_employees: number;
  inactive_employees: number;
  new_applicants: number;
  practical: number;
  waiting: number;
  approved: number;
  category_breakdown: { id: number; name: string; total: number }[];
  damiin_needed: number;
  contract_pending: number;
  uniform_pending: number;
  need_training: number;
  need_practical: number;
  practical_repeat: number;
  rejected: number;
  historical_rejected: number;
  historical_completion_employees: number;
  open_workforce_requests: number;
  supervisor_pool_count: number;
  active_temporary_replacements: number;
  office_staff_count: number;
  attendance_marked_today: number;
  present_today: number;
  absent_today: number;
  late_today: number;
  fulfilled_workforce_requests: number;
  client_company_active_employees: number;
  month_payments_total: string;
  month_penalties_total: string;
  month_advances_total: string;
}

export interface HrReportsSummary {
  range: { from: string; to: string };
  registrations_in_range: number;
  status_breakdown: Partial<Record<EmployeeStatus, number>>;
  pipeline_breakdown: Partial<Record<EmployeePipelineStage, number>>;
  category_breakdown: { id: number; name: string; total: number }[];
  department_breakdown: { id: number; name: string; total: number }[];
  /** Active assignments recorded against a company only (workplace not set yet). */
  assignments_without_workplace: number;
  workplace_breakdown: {
    id: number;
    name: string;
    location_type: LocationType;
    client_company_name: string | null;
    capacity: number | null;
    total: number;
  }[];
  company_salary_totals: { id: number; name: string; total_salary: string; total_assignments: number }[];
  workforce_request_breakdown: Partial<Record<WorkforceRequestStatus, number>>;
  payment_totals_by_department: { id: number; name: string; total_paid: string; total_payments: number }[];
  penalty_totals_by_department: { id: number; name: string; total_deducted: string; total_penalties: number }[];
  advance_totals_by_department: { id: number; name: string; total_advanced: string; total_advances: number }[];
  waiting_by_category: { id: number; name: string; total: number }[];
  waiting_by_gender: Partial<Record<EmployeeGender, number>>;
  waiting_by_location: { location: string; total: number }[];
  /** unavailable = waiting_since is null (pre-Phase-2 data) - never fabricated into a duration bucket. */
  waiting_duration_buckets: {
    under_7_days: number;
    seven_to_30_days: number;
    thirty_to_90_days: number;
    over_90_days: number;
    unavailable: number;
  };
  workforce_requests_by_company: { name: string; total: number }[];
  workforce_requests_by_gender_requirement: Partial<Record<'male' | 'female' | 'any', number>>;
  workforce_requests_progress: {
    id: number;
    work_location_name: string | null;
    status: WorkforceRequestStatus;
    quantity_needed: number;
    matched_count: number;
    unmatched: number;
  }[];
  active_by_gender: Partial<Record<EmployeeGender, number>>;
  active_by_location: { location: string; total: number }[];
  replacement_totals_by_company: { name: string; total_replacements: number; total_paid: string }[];
  separation_reason_breakdown: Partial<Record<EmployeeSeparationReason, number>>;
  rehire_count_in_range: number;
  supervisors_since_range: number;
  office_staff_by_department: { id: number; name: string; total: number }[];
  office_staff_by_position: { id: number; name: string; total: number }[];
  attendance_breakdown: Partial<Record<AttendanceStatus, number>>;
  attendance_by_department: { id: number; name: string; total: number }[];
  reviews_by_rating: Partial<Record<PerformanceRating, number>>;
  reviews_by_department: { id: number; name: string; total: number }[];
}

export interface HrReportsSummaryParams {
  from?: string;
  to?: string;
  client_company_id?: number;
  work_location_id?: number;
  assignment_status?: WorkAssignmentStatus;
}

export interface ListWaitingRosterParams {
  order_by?: 'oldest' | 'newest';
  page?: number;
  per_page?: number;
}

export interface ListWorkforceRequestHistoryParams {
  client_company_id?: number;
  status?: WorkforceRequestStatus;
  page?: number;
  per_page?: number;
}

export interface ListTemporaryReplacementHistoryParams {
  client_company_id?: number;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export interface ListEmployeeLeavesParams {
  department_id?: number;
  leave_type?: LeaveType;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export interface ListEmployeePerformanceReviewsParams {
  department_id?: number;
  rating?: PerformanceRating;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export interface ListFinancialLedgerParams {
  employee_id?: number;
  department_id?: number;
  from?: string;
  to?: string;
  type?: 'payment' | 'penalty' | 'advance';
  page?: number;
  per_page?: number;
}

export interface FinancialLedgerRow {
  type: 'payment' | 'penalty' | 'advance';
  id: number;
  date: string | null;
  employee_id: number;
  employee_name: string | null;
  department_name: string | null;
  amount: string;
  currency: string;
  reason: string | null;
  notes: string | null;
}

export interface GetPayrollRollupParams {
  period: string;
  client_company_id?: number;
  department_id?: number;
}

export interface PayrollRollupRow extends PayrollSummary {
  employee_name: string | null;
  department_name: string | null;
}

export interface PayrollRollupMeta {
  period: string;
  source: 'stored_run' | 'live_estimate';
  is_estimate: boolean;
  estimate_note: string | null;
  run_id: number | null;
  run_status: 'draft' | 'reviewed' | 'approved' | 'finalized' | null;
  differs_from_run: boolean;
  row_count: number;
  totals: { gross_pay: string; replacement_deduction: string; other_deductions: string; adjustment: string; net_payable: string };
  warnings: string[];
}

export interface PayrollRollupResult {
  rows: PayrollRollupRow[];
  meta: PayrollRollupMeta;
}
