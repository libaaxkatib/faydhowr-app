import { apiRequest } from '@/api/client';
import type {
  DraftPayrollPreview,
  MutatingWorkflowId,
  RecoveryAssignmentHit,
  RecoveryCorrection,
  RecoveryErrorEntry,
  RecoveryOverview,
  RecoveryParams,
  RecoveryPreview,
  RecoveryWorkflow,
} from '@/types/recovery';

/** Super Admin Recovery Center. Every call is Super Admin only on the server; the panel only hides the page from everyone else. */
export const recoveryApi = {
  workflows: () => apiRequest<RecoveryWorkflow[]>('admin/recovery/workflows'),

  searchAssignments: (search: string) => apiRequest<RecoveryAssignmentHit[]>('admin/recovery/assignments', { query: { search } }),

  overview: (assignmentId: number) => apiRequest<RecoveryOverview>(`admin/recovery/assignments/${assignmentId}`),

  /** Nothing is saved: the server applies the change inside a transaction it always rolls back, then reports the verified effect. */
  preview: (workflow: MutatingWorkflowId, assignmentId: number, params: RecoveryParams) =>
    apiRequest<RecoveryPreview>('admin/recovery/preview', { method: 'POST', body: { workflow, assignment_id: assignmentId, params } }),

  /** The only call that writes. `token` comes from the preview of exactly the same request; `idempotencyKey` makes a repeat harmless. */
  apply: (input: { workflow: MutatingWorkflowId; assignmentId: number; params: RecoveryParams; reason: string; token: string; idempotencyKey: string }) =>
    apiRequest<RecoveryCorrection>('admin/recovery/apply', {
      method: 'POST',
      body: {
        workflow: input.workflow,
        assignment_id: input.assignmentId,
        params: input.params,
        reason: input.reason,
        confirm: true,
        idempotency_key: input.idempotencyKey,
        token: input.token,
      },
    }),

  payrollPreview: (month: string, employeeId?: number) =>
    apiRequest<DraftPayrollPreview>('admin/recovery/payroll-preview', { query: { month, employee_id: employeeId } }),

  corrections: (assignmentId?: number) => apiRequest<RecoveryCorrection[]>('admin/recovery/corrections', { query: { assignment_id: assignmentId } }),

  errors: (query: string) => apiRequest<{ entries: RecoveryErrorEntry[]; match: RecoveryErrorEntry | null }>('admin/recovery/errors', { query: { q: query || undefined } }),
};
