import type { WorkAssignment } from '@/types/employee';

export type EmbeddedWorkflowKind = 'replace' | 'return' | 'reopen';

export interface EmbeddedWorkflow {
  kind: EmbeddedWorkflowKind;
  assignment: WorkAssignment;
}

/** Replace, Return and Reopen are workflows of a COMPANY assignment (the office has no company list), and a Return needs the Permanent assignment the Replacement covers. */
export function canStartEmbeddedWorkflow(kind: EmbeddedWorkflowKind, assignment: WorkAssignment): boolean {
  if (assignment.client_company_id === null) return false;
  if (kind === 'reopen') return assignment.status === 'ended';
  if (assignment.status !== 'active') return false;
  if (kind === 'return') return assignment.assignment_type === 'replacement' && (assignment.replaces_assignment_id ?? null) !== null;

  return true;
}
