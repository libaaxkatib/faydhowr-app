/**
 * Pure helpers for the replacement choice when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1-N3). The server is the authority:
 * it refuses a removal without a valid choice. These only decide what the form asks for and build the request payload.
 */

import type { SuccessorPayload, SuccessorType } from '../types/employee.ts';

export interface SuccessorDraft {
  type: SuccessorType | '';
  employeeId: number | null;
  /** Waiting List only: the salary HR agrees (a Temporary inherits the departing salary automatically). */
  salaryAmount: string;
  salaryCurrency: string;
}

export const EMPTY_SUCCESSOR: SuccessorDraft = { type: '', employeeId: null, salaryAmount: '', salaryCurrency: 'USD' };

/** Only a Permanent assignment needs a choice; a Replacement-type assignment does not. A missing type means Permanent (the system default). */
export function needsSuccessor(assignmentType: string | null | undefined): boolean {
  return (assignmentType ?? 'permanent') === 'permanent';
}

/** Null when the choice is complete, otherwise the message to show. */
export function validateSuccessor(draft: SuccessorDraft): string | null {
  if (draft.type === '' || draft.employeeId === null) {
    return 'Choose who takes over this Permanent position: a Temporary employee who has been covering it, or a person from the HR Waiting List.';
  }

  if (draft.type === 'waiting_list') {
    const amount = Number(draft.salaryAmount);
    if (draft.salaryAmount.trim() === '' || !Number.isFinite(amount) || amount <= 0) return 'Enter the salary agreed for the person from the Waiting List.';
    if (!/^[A-Za-z]{3}$/.test(draft.salaryCurrency.trim())) return 'Enter the salary currency (for example USD).';
  }

  return null;
}

/** The request body. A Temporary sends no salary: it is inherited from the departing Permanent. Null when the choice is incomplete. */
export function toSuccessorPayload(draft: SuccessorDraft): SuccessorPayload | null {
  if (validateSuccessor(draft) !== null || draft.type === '' || draft.employeeId === null) return null;

  if (draft.type === 'temporary') return { type: 'temporary', employee_id: draft.employeeId };

  return { type: 'waiting_list', employee_id: draft.employeeId, salary_amount: Number(draft.salaryAmount), salary_currency: draft.salaryCurrency.trim().toUpperCase() };
}

/** The server's wording for the new Permanent assignment's start: the day AFTER the last paid day. */
export function successorStartDate(lastDay: string): string {
  const [y, m, d] = lastDay.split('-').map(Number);
  const next = new Date(Date.UTC(y, (m ?? 1) - 1, (d ?? 1) + 1));
  return next.toISOString().slice(0, 10);
}

/** Separation: one choice per open Permanent assignment; returns the assignment ids that still have none. */
export function missingSuccessors(permanentAssignmentIds: number[], drafts: Record<number, SuccessorDraft>): number[] {
  return permanentAssignmentIds.filter((id) => validateSuccessor(drafts[id] ?? EMPTY_SUCCESSOR) !== null);
}
