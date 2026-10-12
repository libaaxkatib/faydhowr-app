// Pure helpers for recording a Practical decision: no React, no network and no `@/` alias imports,
// so they can be unit-tested directly by Node's native test runner (practicalDecision.test.ts).
//
// The decision the HR user clicked is always passed in explicitly. It must never be read back from
// component state, because a state setter is asynchronous and the first click would then submit the
// previous (default: Approved) value even though Rejected was chosen.

import type { PracticalDecision } from '../types/employee.ts';

export const PRACTICAL_DECISIONS: readonly PracticalDecision[] = ['approved', 'rejected', 'ku_celis_practical'];

export interface PracticalDecisionContext {
  /** YYYY-MM-DD */
  assessmentDate: string;
  /** Raw <select> value; '' means no batch. */
  practicalBatchId: string;
  notes: string;
}

export interface PracticalDecisionPayload {
  assessment_date: string;
  result: PracticalDecision;
  practical_batch_id: number | null;
  notes: string | null;
}

export function isPracticalDecision(value: unknown): value is PracticalDecision {
  return typeof value === 'string' && (PRACTICAL_DECISIONS as readonly string[]).includes(value);
}

/** Builds the request body for exactly the decision that was clicked. Throws on an unknown decision. */
export function buildPracticalDecisionPayload(result: PracticalDecision, context: PracticalDecisionContext): PracticalDecisionPayload {
  if (!isPracticalDecision(result)) {
    throw new Error(`Unknown practical decision: ${String(result)}`);
  }

  return {
    assessment_date: context.assessmentDate,
    result,
    practical_batch_id: context.practicalBatchId ? Number(context.practicalBatchId) : null,
    notes: context.notes || null,
  };
}

/**
 * Sends exactly the decision that was clicked. A failure (for example a 422 validation error) is
 * re-thrown untouched and nothing is consumed or remembered here, so the caller keeps the user's
 * notes/batch and the next click submits whatever that click selected.
 */
export function submitPracticalDecision<T>(
  send: (payload: PracticalDecisionPayload) => Promise<T>,
  result: PracticalDecision,
  context: PracticalDecisionContext,
): Promise<T> {
  return send(buildPracticalDecisionPayload(result, context));
}
