// Shapes of the HR compliance and accountability report (backend: App\Support\Hr\Compliance, routes v1/admin/hr/compliance/*).

export type ComplianceCategory = 'profile' | 'job_requirements' | 'workflow';
export type ComplianceSeverity = 'high' | 'medium' | 'low';
export type ComplianceConfidence = 'confirmed' | 'needs_review';
export type ComplianceStatus = 'open' | 'in_progress' | 'resolved';
export type ComplianceEmploymentType = 'Permanent' | 'Temporary' | 'Replacement';

export interface ComplianceResponsible {
  id: number;
  name: string;
  role: string | null;
  /** What the record says this person did, for example "Created the assignment". */
  action: string | null;
  at: string | null;
}

export interface ComplianceFinding {
  id: number;
  employee_id: number;
  employee_name: string;
  employee_number: string;
  client_company_id: number | null;
  company: string | null;
  position: string | null;
  shift: string | null;
  employment_type: ComplianceEmploymentType;
  employment_status: string;
  assignment_id: number | null;
  assignment_status: string | null;
  category: ComplianceCategory;
  code: string;
  severity: ComplianceSeverity;
  confidence: ComplianceConfidence;
  /** The exact missing requirement or incomplete workflow (English, from the server). */
  requirement: string;
  /** Why the record was flagged. */
  explanation: string;
  recommended_action: string;
  details: Record<string, unknown> | null;
  /** null when no record names anybody: the screen says "Responsible manager not recorded". */
  responsible: ComplianceResponsible | null;
  appointed_on: string | null;
  detected_on: string | null;
  last_detected_at: string | null;
  status: ComplianceStatus;
  resolution_source: 'manual' | 'system_cleared' | null;
  resolved_at: string | null;
  note: string | null;
  history?: ComplianceHistoryEntry[];
}

export interface ComplianceHistoryEntry {
  at: string | null;
  by: string | null;
  description: string;
  from_status: string | null;
  to_status: string | null;
  note: string | null;
}

export interface ComplianceSummary {
  active_employees_reviewed: number;
  incomplete_profiles: number;
  unmet_job_requirements: number;
  incomplete_workflows: number;
  needs_hr_review: number;
  confirmed_open: number;
  open: number;
  in_progress: number;
  resolved: number;
  total_findings: number;
}

export interface ComplianceReportData {
  summary: ComplianceSummary;
  findings: ComplianceFinding[];
  filter_options: { companies: { id: number; name: string }[]; responsible: { id: number; name: string }[] };
}

export interface ComplianceFilters {
  search: string;
  client_company_id: string;
  responsible_admin_id: string;
  employment_type: string;
  category: string;
  severity: string;
  status: string;
  confidence: string;
}
