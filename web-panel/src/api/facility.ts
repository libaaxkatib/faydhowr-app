import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  CompanyEmployeeOption,
  FacilityComplaintRow,
  FacilityComplaintStatus,
  FacilityComplaintType,
  FacilityDueState,
  FacilityInspectionDetail,
  FacilityInspectionRow,
  FacilityReport,
  FacilitySatisfaction,
  FacilityStatusMeta,
  FacilityStatusRow,
  StoreFacilityComplaintPayload,
  StoreFacilityInspectionPayload,
} from '@/types/facility';

const base = 'admin/hr/facility-inspections';

/** Facility Inspections, complaints and their report. Reading needs hr.inspections.view, writing hr.inspections.manage (the server enforces both). */
export const facilityApi = {
  status: async (filters: { state?: FacilityDueState | ''; search?: string; category?: string; client_company_id?: number }) => {
    const { data, meta } = await apiRequestWithMeta<FacilityStatusRow[]>(`${base}/status`, { query: filters });
    return { rows: data, meta: meta as FacilityStatusMeta };
  },

  inspections: {
    list: async (filters: { client_company_id?: number; from?: string; to?: string; has_issues?: boolean; include_voided?: boolean; page?: number; per_page?: number }) => {
      const { data, meta } = await apiRequestWithMeta<FacilityInspectionRow[]>(base, { query: filters });
      return { data, meta: meta as PageMeta };
    },
    get: (id: number) => apiRequest<FacilityInspectionDetail>(`${base}/${id}`),
    create: (payload: StoreFacilityInspectionPayload) => apiRequest<FacilityInspectionDetail>(base, { method: 'POST', body: payload }),
    update: (id: number, payload: Partial<Omit<StoreFacilityInspectionPayload, 'client_company_id' | 'complaints' | 'staff_complaints_reported' | 'customer_complaints_reported'>>) =>
      apiRequest<FacilityInspectionDetail>(`${base}/${id}`, { method: 'PUT', body: payload }),
    void: (id: number, reason: string) => apiRequest<FacilityInspectionDetail>(`${base}/${id}/void`, { method: 'POST', body: { reason } }),
    companyEmployees: (companyId: number, on?: string) => apiRequest<CompanyEmployeeOption[]>(`${base}/company-employees/${companyId}`, { query: { on } }),
  },

  setSchedule: (companyId: number, payload: { frequency_days: number; due_soon_days?: number | null; notes?: string | null }) =>
    apiRequest<{ client_company_id: number; frequency_days: number; due_soon_days: number | null; notes: string | null }>(`admin/hr/facility-inspection-schedules/${companyId}`, {
      method: 'PUT',
      body: payload,
    }),

  complaints: {
    list: async (filters: {
      client_company_id?: number;
      type?: FacilityComplaintType | '';
      status?: FacilityComplaintStatus | 'unresolved' | '';
      satisfaction?: FacilitySatisfaction | 'not_recorded' | '';
      from?: string;
      to?: string;
      page?: number;
      per_page?: number;
    }) => {
      const { data, meta } = await apiRequestWithMeta<FacilityComplaintRow[]>('admin/hr/facility-complaints', { query: filters });
      return { data, meta: meta as PageMeta };
    },
    get: (id: number) => apiRequest<FacilityComplaintRow>(`admin/hr/facility-complaints/${id}`),
    create: (payload: StoreFacilityComplaintPayload) => apiRequest<FacilityComplaintRow>('admin/hr/facility-complaints', { method: 'POST', body: payload }),
    update: (id: number, payload: Partial<Pick<StoreFacilityComplaintPayload, 'details' | 'reporter_name' | 'reporter_type' | 'reporter_phone' | 'responsible_person' | 'response' | 'corrective_action' | 'follow_up_on' | 'reported_on' | 'responded_on'>>) =>
      apiRequest<FacilityComplaintRow>(`admin/hr/facility-complaints/${id}`, { method: 'PUT', body: payload }),
    transition: (id: number, payload: { status: FacilityComplaintStatus; note?: string | null; resolved_on?: string | null }) =>
      apiRequest<FacilityComplaintRow>(`admin/hr/facility-complaints/${id}/status`, { method: 'POST', body: payload }),
    satisfaction: (id: number, payload: { satisfaction: FacilitySatisfaction | null; evidence: string; recorded_on?: string | null }) =>
      apiRequest<FacilityComplaintRow>(`admin/hr/facility-complaints/${id}/satisfaction`, { method: 'POST', body: payload }),
    void: (id: number, reason: string) => apiRequest<FacilityComplaintRow>(`admin/hr/facility-complaints/${id}/void`, { method: 'POST', body: { reason } }),
  },

  report: (filters: { from?: string; to?: string; client_company_id?: number; type?: FacilityComplaintType | '' }) =>
    apiRequest<FacilityReport>('admin/hr/reports/facility', { query: filters }),
};
