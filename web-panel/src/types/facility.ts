// Shapes of the Facility Inspections API (see FacilityPresenter / FacilityStatusBuilder / FacilityReportBuilder on the server).

export type FacilityDueState = 'never_inspected' | 'overdue' | 'due' | 'due_soon' | 'ok' | 'no_schedule';

export type FacilityItemResult = 'satisfactory' | 'unsatisfactory';

export type FacilityComplaintType = 'staff' | 'customer';

export type FacilityComplaintStatus = 'open' | 'in_progress' | 'answered' | 'resolved';

export type FacilitySatisfaction = 'satisfied' | 'not_satisfied';

export interface FacilityStatusRow {
  client_company_id: number;
  company: string;
  category: 'company' | 'guri';
  frequency_days: number | null;
  due_soon_days: number | null;
  last_inspected_on: string | null;
  last_inspection_id: number | null;
  next_due_on: string | null;
  days_until_due: number | null;
  days_since_last: number | null;
  inspection_count: number;
  state: FacilityDueState;
  state_label: string;
}

export interface FacilityStatusMeta {
  counts: Record<FacilityDueState | 'total', number>;
  today: string;
  reminders: string;
}

export interface FacilityInspectionRow {
  id: number;
  client_company_id: number;
  company: string | null;
  work_location_id: number | null;
  workplace: string | null;
  inspector_name: string;
  inspected_on: string;
  next_follow_up_on: string | null;
  general_notes: string | null;
  /** The inspector's own answer: true = a complaint of that kind was recorded, false = "no complaint", null = not recorded (older inspections). */
  staff_complaints_reported: boolean | null;
  customer_complaints_reported: boolean | null;
  staff_complaint_count: number;
  customer_complaint_count: number;
  recorded_by: string | null;
  unsatisfactory_count: number;
  uniform_issue_count: number;
  voided: boolean;
  voided_at: string | null;
  void_reason: string | null;
  created_at: string | null;
  /** The completion date this inspection recorded for Deep Cleaning, or null when it recorded none (or it was withdrawn). */
  deep_cleaning_completed_on: string | null;
}

export interface FacilityInspectionItemDetail {
  item_key: string;
  label: string;
  applicable: boolean;
  result: FacilityItemResult | null;
  issue_description: string | null;
  corrective_action: string | null;
}

export interface FacilityUniformIssueDetail {
  id: number;
  employee_id: number;
  employee_number: string | null;
  employee_name: string | null;
  issue: string;
  warning_given: boolean;
  reason_not_worn: string | null;
  corrective_action: string | null;
  follow_up_on: string | null;
}

export interface FacilityInspectionDetail extends FacilityInspectionRow {
  items: FacilityInspectionItemDetail[];
  uniform_issues: FacilityUniformIssueDetail[];
  complaints: FacilityComplaintRow[];
  deep_cleaning: FacilityDeepCleaningDetail | null;
}

export interface FacilityInspectionItemInput {
  item_key: string;
  applicable: boolean;
  result: FacilityItemResult | null;
  issue_description: string | null;
  corrective_action: string | null;
}

export interface FacilityUniformIssueInput {
  employee_id: number;
  issue: string;
  warning_given: boolean;
  reason_not_worn: string | null;
  corrective_action: string | null;
  follow_up_on: string | null;
}

export interface StoreFacilityInspectionPayload {
  client_company_id: number;
  work_location_id?: number | null;
  inspector_name: string;
  inspected_on: string;
  general_notes: string | null;
  next_follow_up_on: string | null;
  items: FacilityInspectionItemInput[];
  uniform_issues: FacilityUniformIssueInput[];
  staff_complaints_reported: boolean;
  customer_complaints_reported: boolean;
  complaints: InspectionComplaintInput[];
  /** Omitted = the inspection says nothing about Deep Cleaning (and an existing record is left untouched). */
  deep_cleaning?: DeepCleaningInput;
}

/** A complaint entered inside the inspection form (the server runs it through the same rules as the Complaints screen). */
export interface InspectionComplaintInput {
  type: FacilityComplaintType;
  details: string;
  reporter_employee_id?: number | null;
  reporter_name?: string | null;
  reporter_type?: string | null;
  reporter_phone?: string | null;
  responsible_person: string;
  response?: string | null;
  reported_on?: string | null;
  responded_on?: string | null;
  corrective_action?: string | null;
  follow_up_on?: string | null;
  status?: FacilityComplaintStatus;
  status_note?: string | null;
  satisfaction?: FacilitySatisfaction | null;
  satisfaction_evidence?: string | null;
}

export interface FacilityComplaintEvent {
  id: number;
  event: string;
  from_status: string | null;
  to_status: string | null;
  note: string | null;
  by: string | null;
  at: string | null;
}

export interface FacilityComplaintRow {
  id: number;
  client_company_id: number;
  company: string | null;
  facility_inspection_id: number | null;
  type: FacilityComplaintType;
  type_label: string;
  status: FacilityComplaintStatus;
  status_label: string;
  details: string;
  reporter_name: string | null;
  reporter_type: string | null;
  reporter_phone: string | null;
  reporter_employee_id: number | null;
  reporter_employee: string | null;
  responsible_person: string | null;
  response: string | null;
  corrective_action: string | null;
  follow_up_on: string | null;
  reported_on: string;
  responded_on: string | null;
  resolved_on: string | null;
  satisfaction: FacilitySatisfaction | null;
  satisfaction_label: string;
  satisfaction_evidence: string | null;
  satisfaction_recorded_on: string | null;
  voided: boolean;
  void_reason: string | null;
  created_at: string | null;
  events?: FacilityComplaintEvent[];
}

export interface StoreFacilityComplaintPayload {
  client_company_id: number;
  facility_inspection_id?: number | null;
  type: FacilityComplaintType;
  details: string;
  reporter_name?: string | null;
  reporter_type?: string | null;
  reporter_phone?: string | null;
  reporter_employee_id?: number | null;
  responsible_person?: string | null;
  response?: string | null;
  corrective_action?: string | null;
  follow_up_on?: string | null;
  reported_on: string;
  responded_on?: string | null;
}

export interface FacilityReport {
  period: { from: string; to: string; days: number };
  inspections: {
    count: number;
    companies_inspected: number;
    companies_in_scope: number;
    never_inspected: number;
    overdue: number;
    due: number;
    due_soon: number;
  };
  items: {
    by_item: { item_key: string; label: string; checked: number; unsatisfactory: number }[];
    unsatisfactory_total: number;
    uniform_issues: number;
  };
  complaints: {
    total: number;
    by_status: Record<FacilityComplaintStatus, number>;
    by_type: Record<FacilityComplaintType, { total: number; unresolved: number }>;
    answered: number;
    resolved: number;
    unresolved: number;
    average_days_to_response: number | null;
    average_days_to_resolution: number | null;
  };
  satisfaction: { satisfied: number; not_satisfied: number; not_recorded: number };
  trends: { month: string; inspections: number; complaints_reported: number; complaints_resolved: number }[];
  schedule_status: {
    counts: Record<FacilityDueState | 'total', number>;
    overdue: FacilityDueListRow[];
    due: FacilityDueListRow[];
    never_inspected: FacilityDueListRow[];
  };
  infrequent: { client_company_id: number; company: string; frequency_days: number; expected_inspections: number; actual_inspections: number; last_inspected_on: string | null }[];
  by_company: { client_company_id: number; company: string; inspections: number; complaints: number; unresolved_complaints: number; satisfied: number; not_satisfied: number; state: FacilityDueState }[];
}

export interface FacilityDueListRow {
  client_company_id: number;
  company: string;
  last_inspected_on: string | null;
  next_due_on: string | null;
  days_until_due: number | null;
}

export interface CompanyEmployeeOption {
  id: number;
  employee_number: string;
  full_name: string;
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Monthly Deep Cleaning (inside Facility Inspections). See FacilityDeepCleaningBuilder / RecordFacilityDeepCleaningAction on the server.

/** completed: done in the month. not_completed: not yet this month (last one the month before). overdue_multiple: several consecutive months
 *  without one. no_history: none ever recorded. A recorded completion is the only evidence; a missing record is never read as "done". */
export type FacilityDeepCleaningState = 'completed' | 'not_completed' | 'overdue_multiple' | 'no_history';

export interface FacilityDeepCleaningRow {
  client_company_id: number;
  company: string;
  category: 'company' | 'guri';
  facility_status: 'active' | 'inactive';
  state: FacilityDeepCleaningState;
  state_label: string;
  /** Consecutive calendar months, the selected one included, with no completion (0 when completed or when there is no history). */
  months_without: number;
  completed_in_month_on: string | null;
  last_completed_on: string | null;
  history: { month: string; completed: boolean; completed_on: string | null }[];
}

export interface FacilityDeepCleaningMeta {
  counts: Record<FacilityDeepCleaningState | 'total', number>;
  month: { value: string; from: string; to: string; is_current: boolean };
  current_month: string;
  /** The calendar the server reads every month and date in (Africa/Mogadishu), and today's date in it. */
  time_zone: string;
  today: string;
  inactive_hidden: number;
  overdue_after_months: number;
  basis: string;
}

/** What a saved inspection says about its Deep Cleaning. `withdrawn` rows are kept as history but no longer count. */
export interface FacilityDeepCleaningDetail {
  id: number;
  completed_on: string;
  notes: string | null;
  recorded_by: string | null;
  recorded_at: string | null;
  changed_by: string | null;
  change_reason: string | null;
  withdrawn: boolean;
  withdrawn_at: string | null;
  withdrawn_reason: string | null;
}

export interface DeepCleaningInput {
  completed: boolean;
  completed_on?: string | null;
  notes?: string | null;
  change_reason?: string | null;
}
