import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  AttendanceRosterEntry,
  ClientCompany,
  EmployeeAttendance,
  CreateEmployeePayload,
  CreateTemporaryReplacementPayload,
  CreateWorkAssignmentPayload,
  CreateWorkforceRequestPayload,
  Department,
  EndTemporaryReplacementPayload,
  Employee,
  EmployeeCategory,
  EmployeeContract,
  EmployeeDocument,
  EmployeeDocumentCategory,
  EmployeeGuarantor,
  EmployeeLeave,
  EmployeePerformanceReview,
  EmployeeStatus,
  EmployeeUniform,
  EndWorkAssignmentPayload,
  FinancialLedgerRow,
  GetPayrollRollupParams,
  HrDashboardData,
  HrReportsSummary,
  HrReportsSummaryParams,
  ListEmployeeLeavesParams,
  ListEmployeePerformanceReviewsParams,
  ListEmployeesParams,
  ListFinancialLedgerParams,
  ListTemporaryReplacementHistoryParams,
  ListWaitingRosterParams,
  ListWorkforceRequestHistoryParams,
  MarkEmployeeAttendancePayload,
  MarkEmployeeSeparatedPayload,
  PayrollRollupRow,
  PayrollSummary,
  PracticalBatch,
  PracticalDecision,
  Position,
  RecordEmployeeAdvancePayload,
  RecordEmployeeLeavePayload,
  RecordEmployeePaymentPayload,
  RecordEmployeePenaltyPayload,
  RecordEmployeePerformanceReviewPayload,
  RecordTemporaryReplacementPaymentPayload,
  TemporaryReplacement,
  TrainingBatch,
  UpdateEmployeePayload,
  UpdateWorkAssignmentPayload,
  UpdateWorkforceRequestPayload,
  WaitingCandidate,
  WorkAssignment,
  WorkforceRequest,
  WorkLocation,
} from '@/types/employee';

export const hrApi = {
  dashboard: () => apiRequest<HrDashboardData>('admin/hr/dashboard'),

  reportsSummary: (params?: HrReportsSummaryParams) =>
    apiRequest<HrReportsSummary>('admin/hr/reports/summary', { query: params as Record<string, string | number | undefined> }),

  reports: {
    async waitingRoster(params?: ListWaitingRosterParams) {
      const { data, meta } = await apiRequestWithMeta<Employee[]>('admin/hr/reports/waiting-roster', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    async workforceRequestHistory(params?: ListWorkforceRequestHistoryParams) {
      const { data, meta } = await apiRequestWithMeta<WorkforceRequest[]>('admin/hr/reports/workforce-requests', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    async temporaryReplacementHistory(params?: ListTemporaryReplacementHistoryParams) {
      const { data, meta } = await apiRequestWithMeta<TemporaryReplacement[]>('admin/hr/reports/temporary-replacements', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    async leaves(params?: ListEmployeeLeavesParams) {
      const { data, meta } = await apiRequestWithMeta<EmployeeLeave[]>('admin/hr/reports/leaves', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    async performanceReviews(params?: ListEmployeePerformanceReviewsParams) {
      const { data, meta } = await apiRequestWithMeta<EmployeePerformanceReview[]>('admin/hr/reports/performance-reviews', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    async financialLedger(params?: ListFinancialLedgerParams) {
      const { data, meta } = await apiRequestWithMeta<FinancialLedgerRow[]>('admin/hr/reports/financial-ledger', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    payrollRollup: (params: GetPayrollRollupParams) =>
      apiRequest<PayrollRollupRow[]>('admin/hr/reports/payroll-rollup', { query: params as unknown as Record<string, string | number | undefined> }),
  },

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

  documentCategories: {
    list: () => apiRequest<EmployeeDocumentCategory[]>('admin/hr/employee-document-categories'),
    create: (payload: { name: string; description?: string | null }) =>
      apiRequest<EmployeeDocumentCategory>('admin/hr/employee-document-categories', { method: 'POST', body: payload }),
  },

  trainingBatches: {
    list: () => apiRequest<TrainingBatch[]>('admin/hr/training-batches'),
    create: (payload: {
      batch_date: string;
      start_time?: string | null;
      end_time?: string | null;
      team_or_group?: string | null;
      trainer_admin_id?: number | null;
      location?: string | null;
      notes?: string | null;
    }) => apiRequest<TrainingBatch>('admin/hr/training-batches', { method: 'POST', body: payload }),
    update: (
      id: number,
      payload: Partial<{
        batch_date: string;
        start_time: string | null;
        end_time: string | null;
        team_or_group: string | null;
        trainer_admin_id: number | null;
        location: string | null;
        notes: string | null;
      }>,
    ) => apiRequest<TrainingBatch>(`admin/hr/training-batches/${id}`, { method: 'PUT', body: payload }),
    addParticipant: (batchId: number, employeeId: number) =>
      apiRequest<TrainingBatch>(`admin/hr/training-batches/${batchId}/participants`, { method: 'POST', body: { employee_id: employeeId } }),
    removeParticipant: (batchId: number, participantId: number) =>
      apiRequest<TrainingBatch>(`admin/hr/training-batches/${batchId}/participants/${participantId}`, { method: 'DELETE' }),
    complete: (batchId: number, absentEmployeeIds: number[] = []) =>
      apiRequest<TrainingBatch>(`admin/hr/training-batches/${batchId}/complete`, {
        method: 'PATCH',
        body: { absent_employee_ids: absentEmployeeIds },
      }),
  },

  practicalBatches: {
    list: () => apiRequest<PracticalBatch[]>('admin/hr/practical-batches'),
    create: (payload: {
      batch_date: string;
      team_or_group?: string | null;
      trainer_admin_id?: number | null;
      location?: string | null;
      notes?: string | null;
    }) => apiRequest<PracticalBatch>('admin/hr/practical-batches', { method: 'POST', body: payload }),
    update: (
      id: number,
      payload: Partial<{ batch_date: string; team_or_group: string | null; trainer_admin_id: number | null; location: string | null; notes: string | null }>,
    ) => apiRequest<PracticalBatch>(`admin/hr/practical-batches/${id}`, { method: 'PUT', body: payload }),
  },

  workforceRequests: {
    list: () => apiRequest<WorkforceRequest[]>('admin/hr/workforce-requests'),
    create: (payload: CreateWorkforceRequestPayload) =>
      apiRequest<WorkforceRequest>('admin/hr/workforce-requests', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateWorkforceRequestPayload) =>
      apiRequest<WorkforceRequest>(`admin/hr/workforce-requests/${id}`, { method: 'PUT', body: payload }),
    cancel: (id: number) => apiRequest<WorkforceRequest>(`admin/hr/workforce-requests/${id}/cancel`, { method: 'PATCH' }),
    candidates: (id: number) => apiRequest<WaitingCandidate[]>(`admin/hr/workforce-requests/${id}/candidates`),
    confirm: (id: number, payload: { employee_id: number; notes?: string | null }) =>
      apiRequest<WorkforceRequest>(`admin/hr/workforce-requests/${id}/confirm`, { method: 'POST', body: payload }),
  },

  temporaryReplacements: {
    list: () => apiRequest<TemporaryReplacement[]>('admin/hr/temporary-replacements'),
    create: (payload: CreateTemporaryReplacementPayload) =>
      apiRequest<TemporaryReplacement>('admin/hr/temporary-replacements', { method: 'POST', body: payload }),
    end: (id: number, payload: EndTemporaryReplacementPayload) =>
      apiRequest<TemporaryReplacement>(`admin/hr/temporary-replacements/${id}/end`, { method: 'PATCH', body: payload }),
    addPayment: (id: number, payload: RecordTemporaryReplacementPaymentPayload) =>
      apiRequest<TemporaryReplacement>(`admin/hr/temporary-replacements/${id}/payments`, { method: 'POST', body: payload }),
  },

  attendance: {
    forDate: (date: string) => apiRequest<AttendanceRosterEntry[]>('admin/hr/attendance', { query: { date } }),
  },

  payroll: {
    summary: (employeeId: number, workAssignmentId: number, period: string) =>
      apiRequest<PayrollSummary>(`admin/hr/employees/${employeeId}/payroll-summary`, { query: { work_assignment_id: workAssignmentId, period } }),
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
    separate: (id: number, payload: MarkEmployeeSeparatedPayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/separate`, { method: 'PATCH', body: payload }),
    rehire: (id: number, note?: string | null) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/rehire`, { method: 'PATCH', body: { note } }),
    toggleSupervisor: (id: number, isSupervisor: boolean) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/supervisor`, { method: 'PATCH', body: { is_supervisor: isSupervisor } }),
    markAttendance: (id: number, payload: MarkEmployeeAttendancePayload) =>
      apiRequest<EmployeeAttendance>(`admin/hr/employees/${id}/attendance`, { method: 'POST', body: payload }),
    addLeave: (id: number, payload: RecordEmployeeLeavePayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/leaves`, { method: 'POST', body: payload }),
    addPerformanceReview: (id: number, payload: RecordEmployeePerformanceReviewPayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/performance-reviews`, { method: 'POST', body: payload }),
    addPayment: (id: number, payload: RecordEmployeePaymentPayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/payments`, { method: 'POST', body: payload }),
    addPenalty: (id: number, payload: RecordEmployeePenaltyPayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/penalties`, { method: 'POST', body: payload }),
    addAdvance: (id: number, payload: RecordEmployeeAdvancePayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/advances`, { method: 'POST', body: payload }),
    confirmGuarantor: (id: number) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/guarantor-confirm`, { method: 'PATCH' }),
    addPracticalAssessment: (
      id: number,
      payload: { assessment_date: string; result: PracticalDecision; practical_batch_id?: number | null; notes?: string | null },
    ) => apiRequest<Employee>(`admin/hr/employees/${id}/practical-assessments`, { method: 'POST', body: payload }),
    uploadDocument: (id: number, file: File, meta?: { employee_document_category_id?: number | null; document_number?: string | null; expiry_date?: string | null }) => {
      const formData = new FormData();
      formData.append('file', file);
      if (meta?.employee_document_category_id) formData.append('employee_document_category_id', String(meta.employee_document_category_id));
      if (meta?.document_number) formData.append('document_number', meta.document_number);
      if (meta?.expiry_date) formData.append('expiry_date', meta.expiry_date);
      return apiRequest<EmployeeDocument>(`admin/hr/employees/${id}/documents`, {
        method: 'POST',
        body: formData,
        isFormData: true,
      });
    },
    deleteDocument: (employeeId: number, documentId: number) =>
      apiRequest<null>(`admin/hr/employees/${employeeId}/documents/${documentId}`, { method: 'DELETE' }),
    verifyDocument: (employeeId: number, documentId: number) =>
      apiRequest<EmployeeDocument>(`admin/hr/employees/${employeeId}/documents/${documentId}/verify`, { method: 'PATCH' }),
    uploadProfilePicture: (id: number, file: File) => {
      const formData = new FormData();
      formData.append('file', file);
      return apiRequest<Employee>(`admin/hr/employees/${id}/profile-picture`, { method: 'POST', body: formData, isFormData: true });
    },
    guarantor: {
      get: (employeeId: number) => apiRequest<EmployeeGuarantor>(`admin/hr/employees/${employeeId}/guarantor`),
      save: (employeeId: number, payload: { guarantor_name: string; guarantor_phone: string; relationship?: string | null; other_info?: string | null; collected_date?: string | null }) =>
        apiRequest<EmployeeGuarantor>(`admin/hr/employees/${employeeId}/guarantor`, { method: 'POST', body: payload }),
      verify: (employeeId: number) => apiRequest<Employee>(`admin/hr/employees/${employeeId}/guarantor/verify`, { method: 'PATCH' }),
    },
    contracts: {
      list: (employeeId: number) => apiRequest<EmployeeContract[]>(`admin/hr/employees/${employeeId}/contracts`),
      create: (employeeId: number, payload: { contract_type?: string; contract_number?: string | null; date_issued?: string | null; start_date?: string | null; end_date?: string | null; notes?: string | null }) =>
        apiRequest<EmployeeContract>(`admin/hr/employees/${employeeId}/contracts`, { method: 'POST', body: payload }),
      sign: (employeeId: number, contractId: number, payload: { signed_date: string; signed_document_id?: number | null }) =>
        apiRequest<Employee>(`admin/hr/employees/${employeeId}/contracts/${contractId}/sign`, { method: 'PATCH', body: payload }),
    },
    uniform: {
      get: (employeeId: number) => apiRequest<EmployeeUniform>(`admin/hr/employees/${employeeId}/uniform`),
      update: (employeeId: number, payload: { status?: string; purchased_at?: string | null; received_at?: string | null; notes?: string | null }) =>
        apiRequest<EmployeeUniform>(`admin/hr/employees/${employeeId}/uniform`, { method: 'PUT', body: payload }),
      confirm: (employeeId: number) => apiRequest<Employee>(`admin/hr/employees/${employeeId}/uniform/confirm`, { method: 'PATCH' }),
    },
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
