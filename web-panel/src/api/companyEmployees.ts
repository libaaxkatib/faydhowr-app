import { apiRequest } from '@/api/client';
import type { SuccessorPayload } from '@/types/employee';
import type {
  AssignmentPayload,
  CompanyEmployeeCandidate,
  CompanyEmployeeFilters,
  CompanyEmployeesResponse,
  CompanyHistory,
  EditPayload,
  ReplacePayload,
  ReturnPayload,
} from '@/types/companyEmployees';

const base = (companyId: number) => `admin/hr/client-companies/${companyId}`;

/** The Company Employee Management endpoints. The server decides who may read pay or change an assignment; the panel only mirrors it. */
export const companyEmployeesApi = {
  list: (companyId: number, filters: CompanyEmployeeFilters) =>
    apiRequest<CompanyEmployeesResponse>(`${base(companyId)}/employees`, {
      query: {
        period: filters.period,
        status: filters.status || undefined,
        position_id: filters.position_id || undefined,
        assignment_type: filters.assignment_type || undefined,
        search: filters.search || undefined,
      },
    }),
  candidates: (companyId: number, q: string) =>
    apiRequest<CompanyEmployeeCandidate[]>(`${base(companyId)}/employee-candidates`, { query: { q } }),
  history: (companyId: number, employeeId?: number) =>
    apiRequest<CompanyHistory>(`${base(companyId)}/employee-history`, { query: { employee_id: employeeId } }),
  assign: (companyId: number, payload: AssignmentPayload) =>
    apiRequest<unknown>(`${base(companyId)}/employees`, { method: 'POST', body: payload }),
  edit: (companyId: number, assignmentId: number, payload: EditPayload) =>
    apiRequest<unknown>(`${base(companyId)}/employees/${assignmentId}`, { method: 'PUT', body: payload }),
  replace: (companyId: number, assignmentId: number, payload: ReplacePayload) =>
    apiRequest<unknown>(`${base(companyId)}/employees/${assignmentId}/replace`, { method: 'POST', body: payload }),
  returnOriginal: (companyId: number, assignmentId: number, payload: ReturnPayload) =>
    apiRequest<unknown>(`${base(companyId)}/employees/${assignmentId}/return`, { method: 'POST', body: payload }),
  stop: (companyId: number, assignmentId: number, payload: { end_date: string; reason: string; correction_reason?: string | null; successor?: SuccessorPayload }) =>
    apiRequest<unknown>(`${base(companyId)}/employees/${assignmentId}/stop`, { method: 'POST', body: payload }),
};
