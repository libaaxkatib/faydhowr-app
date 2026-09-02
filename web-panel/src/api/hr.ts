import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  ClientCompany,
  CreateEmployeePayload,
  CreateWorkAssignmentPayload,
  Department,
  Employee,
  EmployeeCategory,
  EmployeeDocument,
  EmployeePracticalAssessment,
  EmployeeStatus,
  EndWorkAssignmentPayload,
  HrDashboardData,
  HrReportsSummary,
  HrReportsSummaryParams,
  ListEmployeesParams,
  Position,
  UpdateEmployeePayload,
  UpdateWorkAssignmentPayload,
  WorkAssignment,
  WorkLocation,
} from '@/types/employee';

export const hrApi = {
  dashboard: () => apiRequest<HrDashboardData>('admin/hr/dashboard'),

  reportsSummary: (params?: HrReportsSummaryParams) =>
    apiRequest<HrReportsSummary>('admin/hr/reports/summary', { query: params as Record<string, string | number | undefined> }),

  clientCompanies: {
    list: () => apiRequest<ClientCompany[]>('admin/hr/client-companies'),
    create: (payload: { name: string; contact_person?: string | null; phone?: string | null; location?: string | null; notes?: string | null }) =>
      apiRequest<ClientCompany>('admin/hr/client-companies', { method: 'POST', body: payload }),
    update: (
      id: number,
      payload: { name?: string; contact_person?: string | null; phone?: string | null; location?: string | null; status?: string; notes?: string | null },
    ) => apiRequest<ClientCompany>(`admin/hr/client-companies/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/hr/client-companies/${id}`, { method: 'DELETE' }),
  },

  workLocations: {
    list: (clientCompanyId?: number) =>
      apiRequest<WorkLocation[]>('admin/hr/work-locations', { query: clientCompanyId ? { client_company_id: clientCompanyId } : undefined }),
    create: (payload: {
      location_type: 'client' | 'office';
      client_company_id?: number | null;
      name: string;
      location?: string | null;
      contact_person?: string | null;
      phone?: string | null;
      capacity?: number | null;
      notes?: string | null;
    }) => apiRequest<WorkLocation>('admin/hr/work-locations', { method: 'POST', body: payload }),
    update: (
      id: number,
      payload: { name?: string; location?: string | null; contact_person?: string | null; phone?: string | null; capacity?: number | null; status?: string; notes?: string | null },
    ) => apiRequest<WorkLocation>(`admin/hr/work-locations/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/hr/work-locations/${id}`, { method: 'DELETE' }),
  },

  departments: {
    list: () => apiRequest<Department[]>('admin/hr/departments'),
    create: (payload: { name: string; description?: string | null }) =>
      apiRequest<Department>('admin/hr/departments', { method: 'POST', body: payload }),
    update: (id: number, payload: { name?: string; description?: string | null }) =>
      apiRequest<Department>(`admin/hr/departments/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/hr/departments/${id}`, { method: 'DELETE' }),
  },

  positions: {
    list: () => apiRequest<Position[]>('admin/hr/positions'),
    create: (payload: { department_id?: number | null; name: string; description?: string | null }) =>
      apiRequest<Position>('admin/hr/positions', { method: 'POST', body: payload }),
    update: (id: number, payload: { department_id?: number | null; name?: string; description?: string | null }) =>
      apiRequest<Position>(`admin/hr/positions/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/hr/positions/${id}`, { method: 'DELETE' }),
  },

  employeeCategories: {
    list: () => apiRequest<EmployeeCategory[]>('admin/hr/employee-categories'),
  },

  employees: {
    async list(params: ListEmployeesParams) {
      const { data, meta } = await apiRequestWithMeta<Employee[]>('admin/hr/employees', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    get: (id: number) => apiRequest<Employee>(`admin/hr/employees/${id}`),
    create: (payload: CreateEmployeePayload) =>
      apiRequest<Employee>('admin/hr/employees', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateEmployeePayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}`, { method: 'PUT', body: payload }),
    updateStatus: (id: number, status: EmployeeStatus, note?: string) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/status`, { method: 'PATCH', body: { status, note } }),
    confirmGuarantor: (id: number) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/guarantor-confirm`, { method: 'PATCH' }),
    addPracticalAssessment: (
      id: number,
      payload: { assessment_date: string; result: 'pass' | 'fail' | 'pending'; notes?: string | null },
    ) =>
      apiRequest<EmployeePracticalAssessment>(`admin/hr/employees/${id}/practical-assessments`, {
        method: 'POST',
        body: payload,
      }),
    uploadDocument: (id: number, file: File) => {
      const formData = new FormData();
      formData.append('file', file);
      return apiRequest<EmployeeDocument>(`admin/hr/employees/${id}/documents`, {
        method: 'POST',
        body: formData,
        isFormData: true,
      });
    },
    deleteDocument: (employeeId: number, documentId: number) =>
      apiRequest<null>(`admin/hr/employees/${employeeId}/documents/${documentId}`, { method: 'DELETE' }),
    workAssignments: {
      create: (employeeId: number, payload: CreateWorkAssignmentPayload) =>
        apiRequest<WorkAssignment>(`admin/hr/employees/${employeeId}/work-assignments`, { method: 'POST', body: payload }),
      update: (assignmentId: number, payload: UpdateWorkAssignmentPayload) =>
        apiRequest<WorkAssignment>(`admin/hr/work-assignments/${assignmentId}`, { method: 'PUT', body: payload }),
      end: (assignmentId: number, payload: EndWorkAssignmentPayload) =>
        apiRequest<WorkAssignment>(`admin/hr/work-assignments/${assignmentId}/end`, { method: 'PATCH', body: payload }),
    },
  },
};
