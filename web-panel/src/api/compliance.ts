import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { ComplianceFilters, ComplianceFinding, ComplianceReportData, ComplianceStatus } from '@/types/compliance';
import { complianceQuery } from '@/utils/compliance';

/** HR compliance and accountability report. HR Manager and Super Admin only (the server checks the role); reading scans the live records and changes nothing. */
export const complianceApi = {
  list: async (filters: ComplianceFilters, page: number) => {
    const { data, meta } = await apiRequestWithMeta<ComplianceReportData>('admin/hr/compliance/findings', { query: { ...complianceQuery(filters), page } });

    return { data, meta: meta as PageMeta };
  },

  /** Records the follow-up: the resolution status and a note. Audited on the server; changes no employee, assignment or payroll record. */
  update: (id: number, input: { status: ComplianceStatus; note: string }) =>
    apiRequest<ComplianceFinding>(`admin/hr/compliance/findings/${id}`, { method: 'PATCH', body: { status: input.status, note: input.note.trim() === '' ? undefined : input.note.trim() } }),

  show: (id: number) => apiRequest<ComplianceFinding>(`admin/hr/compliance/findings/${id}`),
};
