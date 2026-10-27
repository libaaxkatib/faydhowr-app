/**
 * Pure helpers of the Temporary cover form (docs/HR_PAYROLL_RULES.md U1 and U12). The server decides: it refuses a cover that overlaps another
 * Temporary cover of the same position or of the same person, or the person's own assignment, and the very same check is offered as a preview so the
 * form can warn before anything is saved. These helpers only decide when to ask and how to present the answer; no rule is repeated here.
 */

import { tNow } from '../i18n/active.ts';

export type CoverConflictCode =
  | 'TEMPORARY_COVER_OVERLAPS_POSITION'
  | 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE'
  | 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT';

export interface CoverConflictRecord {
  kind: 'cover' | 'assignment';
  id: number;
  start_date: string;
  end_date: string | null;
  shift: 'day' | 'night' | null;
  company: string | null;
}

export interface CoverConflict {
  code: CoverConflictCode;
  /** The server's wording: Somali for the employee's own assignment (U1), English for the overlapping covers (U12). */
  message: string;
  conflicts: CoverConflictRecord[];
}

const CODES: readonly string[] = ['TEMPORARY_COVER_OVERLAPS_POSITION', 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE', 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT'];

export function isCoverConflictCode(code: unknown): code is CoverConflictCode {
  return typeof code === 'string' && CODES.includes(code);
}

/** The preview is only worth asking for once the position, the person and a start date are chosen (and an end date, if given, is not before the start). */
export function canCheckConflict(fields: { workAssignmentId: string; replacementEmployeeId: string; startDate: string; endDate: string }): boolean {
  if (!fields.workAssignmentId || !fields.replacementEmployeeId || !fields.startDate) return false;

  return fields.endDate === '' || fields.endDate >= fields.startDate;
}

/** A conflict from a refused save (the error carries the code, the message and the conflicting record(s)); null for any other error. */
export function conflictFromError(error: unknown): CoverConflict | null {
  const e = error as { code?: unknown; message?: unknown; errors?: unknown; details?: unknown; fieldErrors?: unknown } | null | undefined;
  if (!isCoverConflictCode(e?.code) || typeof e?.message !== 'string') return null;

  const bag = (e.errors ?? e.details ?? e.fieldErrors) as { conflicts?: unknown } | null | undefined;
  const conflicts = Array.isArray(bag?.conflicts) ? (bag.conflicts as CoverConflictRecord[]) : [];

  return { code: e.code, message: e.message, conflicts };
}

/** The short heading above the message. */
export function conflictHeading(code: CoverConflictCode): string {
  switch (code) {
    case 'TEMPORARY_COVER_OVERLAPS_POSITION':
      return tNow('warning.pipeline.conflictHeadingPosition');
    case 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE':
      return tNow('warning.pipeline.conflictHeadingEmployee');
    case 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT':
      return tNow('warning.pipeline.conflictHeadingOwnAssignment');
  }
}

function until(endDate: string | null): string {
  return endDate ?? tNow('warning.pipeline.conflictNoEndDate');
}

function describe(record: CoverConflictRecord): string {
  const where = record.company ?? tNow('warning.pipeline.conflictOffice');
  const key = record.kind === 'cover' ? 'warning.pipeline.conflictRecordCover' : 'warning.pipeline.conflictRecordAssignment';

  return tNow(key, { id: record.id, where, start: record.start_date, end: until(record.end_date) });
}

/**
 * The message shown for a conflict, in the language the user chose. It is built from the catalogue and the conflicting record the server named, so
 * the English and the Somali wording are both ours. For the employee's own assignment the opening paragraph is the owner-approved text
 * (`warning.pipeline.coverOwnAssignmentOverlap`). When the server named no record, its own message is shown as received.
 */
export function conflictMessage(conflict: CoverConflict): string {
  const record = conflict.conflicts[0];
  if (record === undefined) return conflict.message;

  const hint = tNow('warning.pipeline.shiftRuleHint');

  switch (conflict.code) {
    case 'TEMPORARY_COVER_OVERLAPS_POSITION':
      return `${tNow('warning.pipeline.conflictPosition', { record: describe(record) })} ${tNow('warning.pipeline.conflictPositionAdvice')}`;
    case 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE':
      return `${tNow('warning.pipeline.conflictEmployee', { record: describe(record) })} ${hint}`;
    case 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT':
      return `${tNow('warning.pipeline.coverOwnAssignmentOverlap')} ${tNow('warning.pipeline.conflictEarlier', { record: describe(record) })} ${hint}`;
  }
}
