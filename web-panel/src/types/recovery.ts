// Shapes of the Super Admin Recovery Center API (backend: App\Support\Hr\Recovery\AssignmentRecovery, routes v1/admin/recovery/*).

export type RecoveryWorkflowId =
  | 'reopen_assignment'
  | 'restore_cancelled_assignment'
  | 'correct_assignment_dates'
  | 'correct_absence'
  | 'draft_payroll_preview';

export type MutatingWorkflowId = Exclude<RecoveryWorkflowId, 'draft_payroll_preview'>;

export interface RecoveryWorkflow {
  id: RecoveryWorkflowId;
  mutating: boolean;
  needs_month: boolean;
  title: string;
  summary: string;
}

export interface RecoveryAssignmentHit {
  id: number;
  status: string;
  type: string | null;
  start_date: string;
  end_date: string | null;
  employee_number: string | null;
  employee_name: string | null;
  company: string | null;
}

export interface RecoveryBlocker {
  code: string;
  message: string;
  link?: string;
}

export interface RecoveryOverview {
  assignment: {
    id: number;
    type: string;
    status: string;
    start_date: string;
    end_date: string | null;
    replaces_assignment_id: number | null;
    salary_amount: string;
    salary_currency: string;
    position: string | null;
    shift: string | null;
    company: string | null;
    company_id: number | null;
    workplace: string | null;
    employee: { id: number | null; number: string | null; name: string | null; status: string | null; type: string | null };
  };
  successors: { id: number; type: string | null; status: string; start_date: string; end_date: string | null }[];
  absences: { id: number; last_working_day: string; absent_from: string; returned_on: string | null; terminated_on: string | null }[];
  cycle_events: { absence_id: number; event_type: string; event_date: string; replacement_assignment_id: number | null }[];
  salary_history: { id: number; effective_from: string; amount: string; currency: string }[];
  payroll_lines: { period: string; run_status: string | null; days: number; gross_cents: number; net_cents: number; payment_status: string }[];
  workflows: { id: MutatingWorkflowId; available: boolean; blockers: RecoveryBlocker[] }[];
}

export interface RecoveryParams {
  month?: string;
  start_date?: string;
  end_date?: string;
  absence_id?: string;
  last_working_day?: string;
  returned_on?: string;
}

export interface RecoveryChange {
  entity: 'assignment' | 'salary_history' | 'absence';
  id: number;
  employee_id: number | null;
  label: string;
  before: Record<string, string | null>;
  after: Record<string, string | null>;
}

export interface RecoveryImpactLine {
  assignment_id: number;
  employee_number: string | null;
  employee_name: string | null;
  before_days: number | null;
  before_net_cents: number | null;
  after_days: number | null;
  after_net_cents: number | null;
  difference_cents: number;
  change: 'same' | 'changed' | 'new line' | 'line removed';
}

export interface RecoveryComparison {
  lines: RecoveryImpactLine[];
  changed_employees: { employee_id: number; employee_name: string | null; before_net_cents: number; after_net_cents: number; difference_cents: number }[];
  before_total_cents: number;
  after_total_cents: number;
  difference_cents: number;
  rounding: string;
}

export interface RecoveryPreview {
  workflow: MutatingWorkflowId;
  assignment_id: number;
  month: string | null;
  periods: string[];
  blockers: RecoveryBlocker[];
  warnings: string[];
  changes: RecoveryChange[];
  impact: { periods: Record<string, RecoveryComparison>; currency: string; hash: string } | null;
  verification: { ok: boolean; checks: Record<string, boolean>; problems: string[] };
  can_apply: boolean;
  token: string | null;
  token_valid_minutes: number;
}

export interface RecoveryCorrection {
  id: number;
  uuid: string;
  workflow: MutatingWorkflowId;
  month: string | null;
  assignment_id: number;
  reason: string;
  applied_by: string | null;
  applied_at: string | null;
  status: string;
  changes: RecoveryChange[] | null;
  impact: RecoveryPreview['impact'];
  replayed: boolean;
}

export interface DraftPayrollPreview {
  month: string;
  run_status: string | null;
  generated_at: string | null;
  locked: boolean;
  comparison: RecoveryComparison;
  guidance: string;
}

export interface RecoveryErrorEntry {
  code: string;
  title: string;
  why: string;
  what_to_do: string;
  link: string | null;
  recovery_workflow: RecoveryWorkflowId | null;
  needs_super_admin: boolean;
}
