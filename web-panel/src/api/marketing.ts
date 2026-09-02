import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  CommissionRate,
  CommissionRateType,
  CommissionRecord,
  CreateProjectPayload,
  CreateXarunPayload,
  FollowUp,
  FollowUpFilter,
  ListMarketingRecordsParams,
  MarketingDashboardData,
  MarketingQuotation,
  MarketingQuotationStatus,
  MarketingRecord,
  MarketingRecordStatus,
  MarketingReportsSummary,
  MarketingReportsSummaryParams,
  MarketingTeam,
  UpdateProjectPayload,
  UpdateXarunPayload,
} from '@/types/marketing';

export const marketingApi = {
  dashboard: () => apiRequest<MarketingDashboardData>('admin/marketing/dashboard'),

  reportsSummary: (params?: MarketingReportsSummaryParams) =>
    apiRequest<MarketingReportsSummary>('admin/marketing/reports/summary', {
      query: params as Record<string, string | number | undefined>,
    }),

  teams: {
    list: () => apiRequest<MarketingTeam[]>('admin/marketing/teams'),
    create: (payload: { name: string; description?: string | null }) =>
      apiRequest<MarketingTeam>('admin/marketing/teams', { method: 'POST', body: payload }),
    update: (id: number, payload: { name?: string; description?: string | null }) =>
      apiRequest<MarketingTeam>(`admin/marketing/teams/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/marketing/teams/${id}`, { method: 'DELETE' }),
    addMember: (teamId: number, adminId: number) =>
      apiRequest<MarketingTeam>(`admin/marketing/teams/${teamId}/members`, { method: 'POST', body: { admin_id: adminId } }),
    removeMember: (teamId: number, adminId: number) =>
      apiRequest<MarketingTeam>(`admin/marketing/teams/${teamId}/members/${adminId}`, { method: 'DELETE' }),
  },

  records: {
    async list(params: ListMarketingRecordsParams) {
      const { data, meta } = await apiRequestWithMeta<MarketingRecord[]>('admin/marketing/records', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    get: (id: number) => apiRequest<MarketingRecord>(`admin/marketing/records/${id}`),
    updateStatus: (id: number, status: MarketingRecordStatus) =>
      apiRequest<MarketingRecord>(`admin/marketing/records/${id}/status`, { method: 'PATCH', body: { status } }),
    assign: (id: number, payload: { assigned_team_id?: number | null; assigned_admin_id?: number | null }) =>
      apiRequest<MarketingRecord>(`admin/marketing/records/${id}/assign`, { method: 'PATCH', body: payload }),
  },

  xarun: {
    create: (payload: CreateXarunPayload) => apiRequest<MarketingRecord>('admin/marketing/xarun', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateXarunPayload) =>
      apiRequest<MarketingRecord>(`admin/marketing/xarun/${id}`, { method: 'PUT', body: payload }),
  },

  project: {
    create: (payload: CreateProjectPayload) => apiRequest<MarketingRecord>('admin/marketing/project', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateProjectPayload) =>
      apiRequest<MarketingRecord>(`admin/marketing/project/${id}`, { method: 'PUT', body: payload }),
  },

  followUps: {
    list: (params?: { filter?: FollowUpFilter; assigned_admin_id?: number }) =>
      apiRequest<FollowUp[]>('admin/marketing/follow-ups', { query: params }),
    create: (recordId: number, payload: { follow_up_date: string; assigned_admin_id?: number | null }) =>
      apiRequest<FollowUp>(`admin/marketing/records/${recordId}/follow-ups`, { method: 'POST', body: payload }),
    complete: (id: number, note?: string) =>
      apiRequest<FollowUp>(`admin/marketing/follow-ups/${id}/complete`, { method: 'PATCH', body: { note } }),
    reschedule: (id: number, followUpDate: string, note?: string) =>
      apiRequest<FollowUp>(`admin/marketing/follow-ups/${id}/reschedule`, {
        method: 'PATCH',
        body: { follow_up_date: followUpDate, note },
      }),
  },

  quotations: {
    create: (recordId: number, payload: { amount?: number | null; sent_at?: string | null; notes?: string | null }) =>
      apiRequest<MarketingQuotation>(`admin/marketing/records/${recordId}/quotations`, { method: 'POST', body: payload }),
    updateStatus: (id: number, status: MarketingQuotationStatus) =>
      apiRequest<MarketingQuotation>(`admin/marketing/quotations/${id}/status`, { method: 'PATCH', body: { status } }),
  },

  commission: {
    rates: () => apiRequest<CommissionRate[]>('admin/marketing/commission/rates'),
    createRate: (payload: { admin_id?: number | null; rate_type: CommissionRateType; rate_value: number; effective_from: string }) =>
      apiRequest<CommissionRate>('admin/marketing/commission/rates', { method: 'POST', body: payload }),
    async records(params: { admin_id?: number; month?: string; page?: number; per_page?: number }) {
      const { data, meta } = await apiRequestWithMeta<CommissionRecord[]>('admin/marketing/commission/records', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    createRecord: (recordId: number, payload: { admin_id: number; reference_date: string; notes?: string | null }) =>
      apiRequest<CommissionRecord>(`admin/marketing/records/${recordId}/commission`, { method: 'POST', body: payload }),
  },
};
