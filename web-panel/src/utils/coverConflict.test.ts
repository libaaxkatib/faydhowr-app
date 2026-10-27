import test from 'node:test';
import assert from 'node:assert/strict';

import { canCheckConflict, conflictFromError, conflictHeading, isCoverConflictCode } from './coverConflict.ts';

const fields = { workAssignmentId: '4', replacementEmployeeId: '9', startDate: '2026-09-10', endDate: '2026-09-20' };

test('the preview is asked for only when the position, the person and a sensible period are chosen', () => {
  assert.equal(canCheckConflict(fields), true);
  assert.equal(canCheckConflict({ ...fields, endDate: '' }), true, 'an open end is fine');
  assert.equal(canCheckConflict({ ...fields, endDate: '2026-09-10' }), true, 'a one-day cover');
  assert.equal(canCheckConflict({ ...fields, endDate: '2026-09-09' }), false, 'an end before the start is not asked');
  assert.equal(canCheckConflict({ ...fields, workAssignmentId: '' }), false);
  assert.equal(canCheckConflict({ ...fields, replacementEmployeeId: '' }), false);
  assert.equal(canCheckConflict({ ...fields, startDate: '' }), false);
});

test('only the three cover-overlap codes are conflicts', () => {
  assert.equal(isCoverConflictCode('TEMPORARY_COVER_OVERLAPS_POSITION'), true);
  assert.equal(isCoverConflictCode('TEMPORARY_COVER_OVERLAPS_EMPLOYEE'), true);
  assert.equal(isCoverConflictCode('TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT'), true);
  assert.equal(isCoverConflictCode('WORK_ASSIGNMENT_NOT_ACTIVE'), false);
  assert.equal(isCoverConflictCode(undefined), false);
});

test('a refused save becomes a conflict carrying the server wording and the conflicting record', () => {
  const error = {
    code: 'TEMPORARY_COVER_OVERLAPS_POSITION',
    message: 'This position already has a Temporary cover over some of these days: cover #12',
    fieldErrors: { conflicts: [{ kind: 'cover', id: 12, start_date: '2026-09-10', end_date: '2026-09-20', shift: null, company: 'Alpha' }] },
  };
  const conflict = conflictFromError(error);

  assert.equal(conflict?.code, 'TEMPORARY_COVER_OVERLAPS_POSITION');
  assert.match(conflict?.message ?? '', /cover #12/);
  assert.equal(conflict?.conflicts[0]?.id, 12);
  assert.equal(conflictFromError({ code: 'VALIDATION_ERROR', message: 'x' }), null);
  assert.equal(conflictFromError(new Error('network')), null);
  assert.equal(conflictFromError(null), null);
});

test('the Somali warning of the employee\'s own assignment is shown exactly as the server wrote it', () => {
  const somali = 'Shaqaalahaan wuxuu hore ugu jiraa shaqo Permanent ah oo waqtigeedu isku dhacayo shaqadan.';
  const conflict = conflictFromError({ code: 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT', message: somali });

  assert.equal(conflict?.message, somali);
  assert.deepEqual(conflict?.conflicts, []);
  assert.match(conflictHeading('TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT'), /own assignment/);
});
