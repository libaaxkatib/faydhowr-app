import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { ComplianceFilters, ComplianceFinding, ComplianceReportData, ComplianceScanResult, ComplianceStatus } from '@/types/compliance';
import { complianceQuery } from '@/utils/compliance';

/**
 * HR compliance and accountability report. HR Manager and Super Admin only (the server checks the role). Owner decision UG-1: reading is strictly read-only - it
 * shows what the last scan stored. Scanning the live records happens only through scan(), the "Run scan" button.
 */
export const complianceApi = {
  /** Checks the live records and stores what it finds (hr.manage). The only call that scans; it changes no employee, assignment or payroll record. */
  scan: () => apiRequest<ComplianceScanResult>('admin/hr/compliance/scan', { method: 'POST' }),

  list: async (filters: ComplianceFilters, page: number) => {
    const { data, meta } = await apiRequestWithMeta<ComplianceReportData>('admin/hr/compliance/findings', { query: { ...complianceQuery(filters), page } });

    return { data, meta: meta as PageMeta };
  },

  /** Records the follow-up: the resolution status and a note. Audited on the server; changes no employee, assignment or payroll record. */
  update: (id: number, input: { status: ComplianceStatus; note: string }) =>
    apiRequest<ComplianceFinding>(`admin/hr/compliance/findings/${id}`, { method: 'PATCH', body: { status: input.status, note: input.note.trim() === '' ? undefined : input.note.trim() } }),

  show: (id: number) => apiRequest<ComplianceFinding>(`admin/hr/compliance/findings/${id}`),
};
