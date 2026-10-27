import test from 'node:test';
import assert from 'node:assert/strict';

import { canCheckConflict, conflictFromError, conflictHeading, conflictMessage, isCoverConflictCode } from './coverConflict.ts';
import { setActiveLanguage } from '../i18n/active.ts';
import { translateKey } from '../i18n/messages/index.ts';

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

test('the employee own-assignment warning opens with the owner-approved text in the chosen language and names the earlier assignment', () => {
  const conflict = {
    code: 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT' as const,
    message: 'server wording',
    conflicts: [{ kind: 'assignment' as const, id: 41, start_date: '2026-01-01', end_date: null, shift: null, company: 'Alpha' }],
  };
  try {
    setActiveLanguage('so');
    const somali = conflictMessage(conflict);
    assert.ok(somali.startsWith(translateKey('so', 'warning.pipeline.coverOwnAssignmentOverlap')), 'the approved Somali paragraph comes first, unchanged');
    assert.match(somali, /qoondaynta #41 ee Alpha/);
    setActiveLanguage('en');
    const english = conflictMessage(conflict);
    assert.ok(english.startsWith(translateKey('en', 'warning.pipeline.coverOwnAssignmentOverlap')));
    assert.match(english, /assignment #41 at Alpha, from 2026-01-01 until no end date yet/);
    assert.notEqual(somali, english);
  } finally {
    setActiveLanguage('en');
  }
  assert.match(conflictHeading('TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT'), /own assignment/);
});

test('a conflict without a named record shows the server message as received', () => {
  assert.equal(conflictMessage({ code: 'TEMPORARY_COVER_OVERLAPS_POSITION', message: 'as received', conflicts: [] }), 'as received');
});

test('the Somali opening paragraph is identical in the catalogue and in the backend', async () => {
  const { readFileSync } = await import('node:fs');
  const backend = readFileSync(new URL('../../../backend/app/Support/Hr/CoverConflicts.php', import.meta.url), 'utf8');
  assert.ok(backend.includes(translateKey('so', 'warning.pipeline.coverOwnAssignmentOverlap')), 'CoverConflicts must carry the approved Somali text');
});
