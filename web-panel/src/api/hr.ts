import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  CreateEmployeePayload,
  Department,
  Employee,
  EmployeeCategory,
  EmployeeDocument,
  EmployeePracticalAssessment,
  EmployeeStatus,
  HrDashboardData,
  HrReportsSummary,
  ListEmployeesParams,
  Position,
  UpdateEmployeePayload,
} from '@/types/employee';

export const hrApi = {
  dashboard: () => apiRequest<HrDashboardData>('admin/hr/dashboard'),

  reportsSummary: (params?: { from?: string; to?: string }) =>
    apiRequest<HrReportsSummary>('admin/hr/reports/summary', { query: params }),

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
  },
};
