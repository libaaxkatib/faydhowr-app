import { apiRequest, apiRequestWithMeta, downloadAuthenticatedFile } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { OfficeStaffSummary } from '@/utils/officeStaff';
import type { CreatePayrollAdjustmentPayload, PayrollAdjustmentResult, PayrollCorrectionsResponse, PayrollItem, PayrollRun } from '@/types/payroll';
import type {
  AttendanceRosterEntry,
  ClientCompany,
  ClientCompanyAgreement,
  ClientCompanyCategory,
  EmployeeAttendance,
  CreateEmployeePayload,
  CreateTemporaryReplacementPayload,
  CreateWorkAssignmentPayload,
  CreateWorkforceRequestPayload,
  Department,
  DuplicateCheckParams,
  DuplicateEmployeeMatch,
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
  PayrollRollupMeta,
  PayrollRollupResult,
  PayrollRollupRow,
  PayrollSummary,
  PracticalBatch,
  PracticalDecision,
  Position,
  RecordReplacementWorkdaysPayload,
  RecordEmployeeAdvancePayload,
  RecordEmployeeLeavePayload,
  RecordEmployeePaymentPayload,
  RecordEmployeePenaltyPayload,
  RecordEmployeePerformanceReviewPayload,
  RecordTemporaryReplacementPaymentPayload,
  TemporaryReplacement,
  TemporaryReplacementCost,
  TemporaryReplacementWorkday,
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
    payrollRollup: async (params: GetPayrollRollupParams): Promise<PayrollRollupResult> => {
      const { data, meta } = await apiRequestWithMeta<PayrollRollupRow[]>('admin/hr/reports/payroll-rollup', {
        query: params as unknown as Record<string, string | number | undefined>,
      });
      return { rows: data, meta: meta as PayrollRollupMeta };
    },
  },

  clientCompanies: {
    list: () => apiRequest<ClientCompany[]>('admin/hr/client-companies'),
    create: (payload: {
      name: string;
      category?: ClientCompanyCategory;
      contact_person?: string | null;
      phone?: string | null;
      location?: string | null;
      notes?: string | null;
    }) => apiRequest<ClientCompany>('admin/hr/client-companies', { method: 'POST', body: payload }),
    update: (
      id: number,
      payload: {
        name?: string;
        category?: ClientCompanyCategory;
        contact_person?: string | null;
        phone?: string | null;
        location?: string | null;
        status?: string;
        notes?: string | null;
      },
    ) => apiRequest<ClientCompany>(`admin/hr/client-companies/${id}`, { method: 'PUT', body: payload }),
    remove: (id: number) => apiRequest<null>(`admin/hr/client-companies/${id}`, { method: 'DELETE' }),
    agreements: {
      list: (companyId: number) => apiRequest<ClientCompanyAgreement[]>(`admin/hr/client-companies/${companyId}/agreements`),
      upload: (companyId: number, file: File, notes?: string | null) => {
        const formData = new FormData();
        formData.append('file', file);
        if (notes) formData.append('notes', notes);
        return apiRequest<ClientCompanyAgreement>(`admin/hr/client-companies/${companyId}/agreements`, {
          method: 'POST',
          body: formData,
          isFormData: true,
        });
      },
      download: (companyId: number, agreement: ClientCompanyAgreement) =>
        downloadAuthenticatedFile(`admin/hr/client-companies/${companyId}/agreements/${agreement.id}/download`, agreement.file_name),
    },
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

  /** Office Staff workflow (needs hr.manage). Documents use the existing employee document endpoints. */
  officeStaff: {
    lookup: (search: string) => apiRequest<OfficeStaffSummary[]>('admin/hr/office-staff/lookup', { query: { search } }),
    get: (employeeId: number) => apiRequest<OfficeStaffSummary>(`admin/hr/office-staff/${employeeId}`),
    assignExisting: (payload: Record<string, unknown>) => apiRequest<OfficeStaffSummary>('admin/hr/office-staff/assign', { method: 'POST', body: payload }),
    registerNew: (payload: Record<string, unknown>) => apiRequest<OfficeStaffSummary>('admin/hr/office-staff', { method: 'POST', body: payload }),
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
    approve: (id: number) => apiRequest<TemporaryReplacement>(`admin/hr/temporary-replacements/${id}/approve`, { method: 'PATCH' }),
    cost: (id: number, period: string) =>
      apiRequest<TemporaryReplacementCost>(`admin/hr/temporary-replacements/${id}/cost`, { query: { period } }),
    workdays: {
      list: (id: number) => apiRequest<TemporaryReplacementWorkday[]>(`admin/hr/temporary-replacements/${id}/workdays`),
      record: (id: number, payload: RecordReplacementWorkdaysPayload) =>
        apiRequest<unknown>(`admin/hr/temporary-replacements/${id}/workdays`, { method: 'POST', body: payload }),
      verify: (id: number, workdayId: number) =>
        apiRequest<TemporaryReplacementWorkday>(`admin/hr/temporary-replacements/${id}/workdays/${workdayId}/verify`, { method: 'PATCH' }),
      void: (id: number, workdayId: number, reason: string) =>
        apiRequest<TemporaryReplacementWorkday>(`admin/hr/temporary-replacements/${id}/workdays/${workdayId}/void`, {
          method: 'PATCH',
          body: { reason },
        }),
    },
  },

  payrollRuns: {
    list: () => apiRequest<PayrollRun[]>('admin/hr/payroll-runs'),
    get: (id: number) => apiRequest<PayrollRun>(`admin/hr/payroll-runs/${id}`),
    /** Super Admin corrections of approved/finalized months and the overpayment-recovery ledger (read-only). */
    corrections: () => apiRequest<PayrollCorrectionsResponse>('admin/hr/payroll-corrections'),
    /** An underpayment paid (Super Admin), a debt repayment received (Super Admin / HR Manager) or a debt written off (Super Admin, reason required): always its own transaction, retried safely by the key. */
    recordCorrectionTransaction: (id: number, payload: { type: 'underpayment_payment' | 'debt_repayment' | 'debt_write_off'; amount: number; transaction_date: string; note?: string | null; reason?: string | null; idempotency_key: string }) =>
      apiRequest<{ transaction_id: number; replayed: boolean; outstanding_cents: number; status: string }>(`admin/hr/payroll-corrections/${id}/transactions`, { method: 'POST', body: payload }),
    voidCorrectionTransaction: (id: number, transactionId: number, reason: string) =>
      apiRequest<{ outstanding_cents: number; status: string }>(`admin/hr/payroll-corrections/${id}/transactions/${transactionId}/void`, { method: 'POST', body: { reason } }),
    /** Creates the draft for a month, or regenerates it if one already exists (refused once approved/finalized). */
    generate: (period: string) => apiRequest<PayrollRun>('admin/hr/payroll-runs', { method: 'POST', body: { period } }),
    review: (id: number) => apiRequest<PayrollRun>(`admin/hr/payroll-runs/${id}/review`, { method: 'PATCH' }),
    approve: (id: number) => apiRequest<PayrollRun>(`admin/hr/payroll-runs/${id}/approve`, { method: 'PATCH' }),
    reopen: (id: number, reason: string) =>
      apiRequest<PayrollRun>(`admin/hr/payroll-runs/${id}/reopen`, { method: 'PATCH', body: { reason } }),
    finalize: (id: number) => apiRequest<PayrollRun>(`admin/hr/payroll-runs/${id}/finalize`, { method: 'PATCH' }),
    exportExcel: (run: Pick<PayrollRun, 'id' | 'period'>) =>
      downloadAuthenticatedFile(`admin/hr/payroll-runs/${run.id}/export`, `payroll-${run.period}.xlsx`),
    /** Excel list of who to pay for a month (needs hr.payroll.view + hr.manage). Read-only: pays nothing, creates no run. */
    downloadPaymentList: (period: string) =>
      downloadAuthenticatedFile(`admin/hr/reports/monthly-payment-list?period=${encodeURIComponent(period)}`, `payment-list-${period}.xlsx`),
    addAdjustment: (itemId: number, payload: CreatePayrollAdjustmentPayload) =>
      apiRequest<PayrollAdjustmentResult>(`admin/hr/payroll-items/${itemId}/adjustments`, { method: 'POST', body: payload }),
    /** Voids a payment marked paid (Super Admin / HR Manager, reason required): a linked reversal is added, nothing is edited or deleted. */
    voidPayment: (itemId: number, reason: string) =>
      apiRequest<PayrollItem>(`admin/hr/payroll-items/${itemId}/void-payment`, { method: 'POST', body: { reason } }),
    markPaid: (itemId: number, paymentDate?: string) =>
      apiRequest<PayrollItem>(`admin/hr/payroll-items/${itemId}/pay`, { method: 'POST', body: paymentDate ? { payment_date: paymentDate } : {} }),
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
    duplicateCheck: (params: DuplicateCheckParams) =>
      apiRequest<DuplicateEmployeeMatch[]>('admin/hr/employees/duplicate-check', { query: { ...params } }),
    create: (payload: CreateEmployeePayload) =>
      apiRequest<Employee>('admin/hr/employees', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateEmployeePayload) =>
      apiRequest<Employee>(`admin/hr/employees/${id}`, { method: 'PUT', body: payload }),
    updateStatus: (id: number, status: EmployeeStatus, note?: string, overrideReason?: string) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/status`, {
        method: 'PATCH',
        body: { status, note, ...(overrideReason ? { override_reason: overrideReason } : {}) },
      }),
    /** Super Admin / HR Manager: moves ONE existing registered person to a pipeline stage or the Waiting List, with a reason. */
    moveStage: (id: number, stage: string, reason: string) =>
      apiRequest<Employee>(`admin/hr/employees/${id}/move-stage`, { method: 'POST', body: { stage, reason } }),
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
    approveLeave: (employeeId: number, leaveId: number, note?: string | null) =>
      apiRequest<EmployeeLeave>(`admin/hr/employees/${employeeId}/leaves/${leaveId}/approve`, { method: 'PATCH', body: { note: note || null } }),
    rejectLeave: (employeeId: number, leaveId: number, note: string) =>
      apiRequest<EmployeeLeave>(`admin/hr/employees/${employeeId}/leaves/${leaveId}/reject`, { method: 'PATCH', body: { note } }),
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
    /** Opens a stored document through the authenticated download endpoint (there is no public URL). */
    downloadDocument: (employeeId: number, documentId: number, fileName: string) =>
      downloadAuthenticatedFile(`admin/hr/employees/${employeeId}/documents/${documentId}/download`, fileName),
    deleteDocument: (employeeId: number, documentId: number) =>
      apiRequest<null>(`admin/hr/employees/${employeeId}/documents/${documentId}`, { method: 'DELETE' }),
    /** Corrects the category of an existing document (hr.manage); the file and its checksum are not touched. */
    updateDocumentCategory: (employeeId: number, documentId: number, categoryId: number) =>
      apiRequest<EmployeeDocument>(`admin/hr/employees/${employeeId}/documents/${documentId}/category`, {
        method: 'PATCH',
        body: { employee_document_category_id: categoryId },
      }),
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
