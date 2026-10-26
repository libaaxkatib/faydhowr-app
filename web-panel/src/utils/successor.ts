/**
 * Pure helpers for the replacement choice when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1-N3, U9). The server is the authority:
 * it refuses a removal without a valid choice. These only decide what the form asks for and build the request payload.
 */

import type { SuccessorPayload, SuccessorType } from '../types/employee.ts';

export interface SuccessorDraft {
  type: SuccessorType | '';
  employeeId: number | null;
  /** First day of the replacement's Permanent assignment and salary, INCLUDED (YYYY-MM-DD). Empty = the day after the last paid day. */
  effectiveDate: string;
  /** Waiting List only: the salary HR agrees (a Temporary inherits the departing salary automatically). */
  salaryAmount: string;
  salaryCurrency: string;
}

export const EMPTY_SUCCESSOR: SuccessorDraft = { type: '', employeeId: null, effectiveDate: '', salaryAmount: '', salaryCurrency: 'USD' };

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Only a Permanent assignment needs a choice; a Replacement-type assignment does not. A missing type means Permanent (the system default). */
export function needsSuccessor(assignmentType: string | null | undefined): boolean {
  return (assignmentType ?? 'permanent') === 'permanent';
}

/** The day after a calendar date, as YYYY-MM-DD (the default effective date: the system convention is that the end date is the last paid day). */
export function successorStartDate(lastDay: string): string {
  const [y, m, d] = lastDay.split('-').map(Number);
  const next = new Date(Date.UTC(y, (m ?? 1) - 1, (d ?? 1) + 1));
  return next.toISOString().slice(0, 10);
}

/** The first day of the replacement for a draft: the date HR chose, otherwise the day after the last paid day. Never shifted. */
export function effectiveStart(lastDay: string, draft: SuccessorDraft): string {
  if (draft.effectiveDate !== '') return draft.effectiveDate;
  return ISO_DATE.test(lastDay) ? successorStartDate(lastDay) : '';
}

/**
 * Null when the choice is complete, otherwise the message to show. When `lastDay` is known, an effective date on or before it is refused (both
 * dates are inclusive, so the same day cannot be paid to two people); the server enforces the same rule.
 */
export function validateSuccessor(draft: SuccessorDraft, lastDay?: string): string | null {
  if (draft.type === '' || draft.employeeId === null) {
    return 'Choose who takes over this Permanent position: a Temporary employee who has been covering it, or a person from the HR Waiting List.';
  }

  if (draft.effectiveDate !== '') {
    if (!ISO_DATE.test(draft.effectiveDate)) return 'Enter the effective date of the replacement as a valid date.';
    if (lastDay !== undefined && ISO_DATE.test(lastDay) && draft.effectiveDate <= lastDay) {
      return `The effective date (${draft.effectiveDate}) must be after the last paid day (${lastDay}). Both days are included, so one day cannot be paid to two people.`;
    }
  }

  if (draft.type === 'waiting_list') {
    const amount = Number(draft.salaryAmount);
    if (draft.salaryAmount.trim() === '' || !Number.isFinite(amount) || amount <= 0) return 'Enter the salary agreed for the person from the Waiting List.';
    if (!/^[A-Za-z]{3}$/.test(draft.salaryCurrency.trim())) return 'Enter the salary currency (for example USD).';
  }

  return null;
}

/** The request body. A Temporary sends no salary: it is inherited from the departing Permanent. Null when the choice is incomplete. */
export function toSuccessorPayload(draft: SuccessorDraft, lastDay?: string): SuccessorPayload | null {
  if (validateSuccessor(draft, lastDay) !== null || draft.type === '' || draft.employeeId === null) return null;

  const effective = draft.effectiveDate !== '' ? { effective_date: draft.effectiveDate } : {};

  if (draft.type === 'temporary') return { type: 'temporary', employee_id: draft.employeeId, ...effective };

  return { type: 'waiting_list', employee_id: draft.employeeId, ...effective, salary_amount: Number(draft.salaryAmount), salary_currency: draft.salaryCurrency.trim().toUpperCase() };
}

/** Separation: one choice per open Permanent assignment; returns the assignment ids that still have none (or an invalid one). */
export function missingSuccessors(permanentAssignmentIds: number[], drafts: Record<number, SuccessorDraft>, lastDay?: string): number[] {
  return permanentAssignmentIds.filter((id) => validateSuccessor(drafts[id] ?? EMPTY_SUCCESSOR, lastDay) !== null);
}
