export type MarketingRecordType = 'xarun' | 'project';
export type MarketingRecordStatus = 'pending' | 'quotation' | 'done' | 'cancelled';
export type ResponsiblePartyType = 'company' | 'engineer' | 'owner' | 'other';
export type FollowUpStatus = 'scheduled' | 'completed' | 'rescheduled';
export type FollowUpFilter = 'today' | 'upcoming' | 'overdue' | 'all';
export type MarketingQuotationStatus = 'draft' | 'sent' | 'accepted' | 'declined';
export type CommissionRateType = 'percentage' | 'fixed';
export type CommissionStatus = 'pending_calculation' | 'calculated' | 'approved' | 'paid';

export interface MarketingTeam {
  id: number;
  name: string;
  description: string | null;
  members?: { id: number; full_name: string }[];
}

export type MarketingEmployeeRole = 'marketing_manager' | 'marketing_employee';

export interface MarketingEmployee {
  id: number;
  full_name: string;
  email: string;
  role: MarketingEmployeeRole;
  teams?: { id: number; name: string }[];
}

export interface FollowUpHistoryEntry {
  id: number;
  action: 'created' | 'rescheduled' | 'completed' | 'feedback_updated' | 'status_updated';
  note: string | null;
  performed_by: string | null;
  created_at: string;
}

export interface FollowUp {
  id: number;
  marketing_record_id: number;
  record_number?: string;
  record_type?: MarketingRecordType;
  record_team_name?: string | null;
  phone?: string | null;
  location?: string | null;
  follow_up_date: string;
  status: FollowUpStatus;
  assigned_admin: string | null;
  created_at: string;
  histories?: FollowUpHistoryEntry[];
}

export interface MarketingQuotation {
  id: number;
  amount: string | null;
  sent_at: string | null;
  status: MarketingQuotationStatus;
  notes: string | null;
  created_by: string | null;
  created_at: string;
}

export interface XarunDetail {
  facility_name: string;
  manager_name: string | null;
  manager_title: string | null;
  phone: string | null;
  location: string | null;
  needs: string[] | null;
}

export interface ProjectDetail {
  responsible_party_type: ResponsiblePartyType;
  company_name: string | null;
  responsible_person_name: string | null;
  phone: string | null;
  location: string | null;
  project_type: string | null;
  project_size: string | null;
  construction_completion_date: string | null;
  fayadhowr_work_date: string | null;
}

export interface MarketingRecord {
  id: number;
  record_number: string;
  type: MarketingRecordType;
  assigned_team_id: number | null;
  assigned_team_name: string | null;
  assigned_admin_id: number | null;
  assigned_admin_name: string | null;
  status: MarketingRecordStatus;
  description: string | null;
  feedback: string | null;
  brought_by_admin_id: number | null;
  brought_by_admin_name: string | null;
  created_at: string;
  xarun: XarunDetail | null;
  project: ProjectDetail | null;
  follow_ups?: FollowUp[];
  quotations?: MarketingQuotation[];
}

export interface ListMarketingRecordsParams {
  search?: string;
  type?: MarketingRecordType;
  status?: MarketingRecordStatus;
  assigned_team_id?: number;
  assigned_admin_id?: number;
  page?: number;
  per_page?: number;
}

export interface CreateXarunPayload {
  facility_name: string;
  manager_name?: string | null;
  manager_title?: string | null;
  phone?: string | null;
  location?: string | null;
  needs?: string[] | null;
  description?: string | null;
  assigned_team_id?: number | null;
  assigned_admin_id?: number | null;
}
export type UpdateXarunPayload = Partial<CreateXarunPayload> & { feedback?: string | null };

export interface CreateProjectPayload {
  responsible_party_type: ResponsiblePartyType;
  company_name?: string | null;
  responsible_person_name?: string | null;
  phone?: string | null;
  location?: string | null;
  project_type?: string | null;
  project_size?: string | null;
  construction_completion_date?: string | null;
  fayadhowr_work_date?: string | null;
  description?: string | null;
  assigned_team_id?: number | null;
  assigned_admin_id?: number | null;
}
export type UpdateProjectPayload = Partial<CreateProjectPayload> & { feedback?: string | null };

export interface CommissionRate {
  id: number;
  admin_id: number | null;
  admin_name: string | null;
  rate_type: CommissionRateType;
  rate_value: string;
  effective_from: string;
  created_at: string;
}

export interface CommissionRecord {
  id: number;
  admin_id: number;
  admin_name: string | null;
  marketing_record_id: number;
  record_number: string | null;
  type: MarketingRecordType;
  reference_date: string;
  commission_rate_id: number | null;
  amount: string | null;
  status: CommissionStatus;
  notes: string | null;
  created_at: string;
}

export interface MarketingDashboardData {
  total_records: number;
  total_xarun: number;
  total_project: number;
  pending: number;
  quotation: number;
  done: number;
  cancelled: number;
  new_leads_today: number;
  todays_follow_ups: number;
  overdue_follow_ups: number;
}

export interface MarketingReportsSummaryParams {
  from?: string;
  to?: string;
  assigned_team_id?: number;
  assigned_admin_id?: number;
  status?: MarketingRecordStatus;
  type?: MarketingRecordType;
}

export interface MarketingReportsSummary {
  range: { from: string; to: string };
  total_records: number;
  status_breakdown: Partial<Record<MarketingRecordStatus, number>>;
  type_breakdown: Partial<Record<MarketingRecordType, number>>;
  team_breakdown: { id: number; name: string; total: number }[];
  employee_breakdown: { admin_id: number; admin_name: string | null; total: number }[];
}
