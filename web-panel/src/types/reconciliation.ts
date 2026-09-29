export type DataIssueStatus = 'open' | 'investigating' | 'resolved' | 'accepted_difference' | 'cannot_resolve';

export type DataIssueSeverity = 'critical' | 'high' | 'medium' | 'low';

export type DataIssueType =
  | 'missing_data'
  | 'missing_record'
  | 'duplicate'
  | 'conflict'
  | 'source_mismatch'
  | 'count_discrepancy'
  | 'invalid_record'
  | 'system_error'
  | 'migration_issue'
  | 'other';

export type DataIssueModule = 'hr' | 'marketing' | 'finance' | 'bookings' | 'customers' | 'quotations' | 'store' | 'system' | 'other';

export interface DataIssueAffectedEmployee {
  id: number;
  employee_number: string;
  full_name: string;
  category_name: string | null;
  status: string | null;
  phone: string | null;
  deleted_at: string | null;
}

export interface DataIssueAffectedRecord {
  id: number;
  recordable_type: string;
  recordable_id: number;
  context_note: string | null;
  created_at: string | null;
  employee: DataIssueAffectedEmployee | null;
}

export interface DataIssueAuditLogEntry {
  id: number;
  admin_name: string | null;
  field_changed: string;
  old_value: string | null;
  new_value: string | null;
  created_at: string | null;
}

export interface DataIssue {
  id: number;
  issue_number: string;
  title: string;
  description: string | null;
  module: DataIssueModule;
  module_label: string;
  issue_type: DataIssueType;
  issue_type_label: string;
  severity: DataIssueSeverity;
  severity_label: string;
  status: DataIssueStatus;
  status_label: string;
  expected_value: string | null;
  actual_value: string | null;
  difference_value: string | null;
  root_cause: string | null;
  resolution: string | null;
  source_reference: string | null;
  notes: string | null;
  affected_records_count?: number;
  affected_records?: DataIssueAffectedRecord[];
  audit_logs?: DataIssueAuditLogEntry[];
  created_by: number | null;
  created_by_name: string | null;
  resolved_by: number | null;
  resolved_by_name: string | null;
  created_at: string;
  updated_at: string;
  resolved_at: string | null;
}

export interface DataIssueSummary {
  total: number;
  open: number;
  investigating: number;
  resolved: number;
  accepted_difference: number;
  cannot_resolve: number;
  critical: number;
  high: number;
}

export interface ListDataIssuesParams {
  search?: string;
  status?: DataIssueStatus | '';
  severity?: DataIssueSeverity | '';
  module?: DataIssueModule | '';
  issue_type?: DataIssueType | '';
  created_by?: number;
  resolved_by?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface AffectedEmployeeInput {
  employee_id: number;
  context_note?: string | null;
}

export interface CreateDataIssuePayload {
  title: string;
  description?: string | null;
  module: DataIssueModule;
  issue_type: DataIssueType;
  severity: DataIssueSeverity;
  status?: 'open' | 'investigating';
  expected_value?: string | null;
  actual_value?: string | null;
  difference_value?: string | null;
  root_cause?: string | null;
  resolution?: string | null;
  source_reference?: string | null;
  notes?: string | null;
  affected_employees?: AffectedEmployeeInput[];
}

export interface UpdateDataIssuePayload {
  title?: string;
  description?: string | null;
  module?: DataIssueModule;
  issue_type?: DataIssueType;
  severity?: DataIssueSeverity;
  status?: 'open' | 'investigating';
  expected_value?: string | null;
  actual_value?: string | null;
  difference_value?: string | null;
  root_cause?: string | null;
  resolution?: string | null;
  source_reference?: string | null;
  notes?: string | null;
  add_affected_employees?: AffectedEmployeeInput[];
  remove_affected_record_ids?: number[];
}

export interface ResolveDataIssuePayload {
  status: 'resolved' | 'accepted_difference' | 'cannot_resolve';
  resolution: string;
  root_cause?: string | null;
  notes?: string | null;
}
