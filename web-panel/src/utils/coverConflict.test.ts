import test from 'node:test';
import assert from 'node:assert/strict';

import { canCheckConflict, conflictFromError, conflictHeading, conflictMessage, isCoverConflictCode, replacementConflictMessage, replacementReturnMessage, replacementSiblingMessage } from './coverConflict.ts';
import { friendlyErrorMessage } from './hrHelpers.ts';
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

test('a cover that overlaps a Replacement of the same position (owner decision O-1) is a conflict, named in both languages', () => {
  const conflict = {
    code: 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT' as const,
    message: 'server wording',
    conflicts: [{ kind: 'replacement' as const, id: 19, start_date: '2026-11-01', end_date: '2026-11-10', shift: null, company: 'Alpha' }],
  };
  assert.equal(isCoverConflictCode(conflict.code), true);
  assert.equal(conflictFromError({ code: conflict.code, message: 'm', errors: { conflicts: conflict.conflicts } })?.conflicts[0]?.id, 19);
  try {
    setActiveLanguage('en');
    assert.match(conflictMessage(conflict), /already held by Replacement #19 at Alpha, from 2026-11-01 until 2026-11-10/);
    assert.match(conflictHeading(conflict.code), /held by a Replacement/);
    setActiveLanguage('so');
    assert.match(conflictMessage(conflict), /Beddelaadda \(Replacement\) #19 ee Alpha/);
  } finally {
    setActiveLanguage('en');
  }
});

test('the cover-overlap codes are conflicts, other errors are not', () => {
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

test('a Replacement refused because a Temporary cover holds the days (O-1, reverse direction) is named in both languages', () => {
  const error = {
    code: 'REPLACEMENT_OVERLAPS_TEMPORARY_COVER',
    message: 'server wording',
    fieldErrors: { conflicts: [{ kind: 'cover', id: 4, start_date: '2026-10-22', end_date: '2026-10-25', shift: null, company: 'Alpha' }] },
  };
  try {
    setActiveLanguage('en');
    assert.match(replacementConflictMessage(error) ?? '', /This Replacement cannot be saved: .* cover #4 at Alpha, from 2026-10-22 until 2026-10-25\./);
    assert.equal(friendlyErrorMessage(error, 'fallback'), replacementConflictMessage(error), 'every assignment dialog shows it through friendlyErrorMessage');
    setActiveLanguage('so');
    assert.match(replacementConflictMessage(error) ?? '', /Beddelaaddan lama keydin karo: .* beddelaadda #4 ee Alpha/);
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(replacementConflictMessage({ code: 'VALIDATION_ERROR', message: 'x' }), null);
  assert.equal(replacementConflictMessage({ code: 'REPLACEMENT_OVERLAPS_TEMPORARY_COVER', message: 'as received', fieldErrors: {} }), null, 'no named cover: the server message is shown as received');
  assert.equal(friendlyErrorMessage({ code: 'REPLACEMENT_OVERLAPS_TEMPORARY_COVER', message: 'as received' }, 'fallback'), 'as received');
});

test('a Replacement refused because it would last until or after its holder returned (F-A) is explained with both dates in both languages', () => {
  const error = {
    code: 'REPLACEMENT_RUNS_PAST_HOLDER_RETURN',
    message: 'server wording',
    fieldErrors: { holder_return_date: ['2026-11-10'], latest_end_date: ['2026-11-09'], requested_end_date: ['2026-11-10'], end_date: ['The last day cannot be later than 2026-11-09.'] },
  };
  try {
    setActiveLanguage('en');
    assert.match(replacementReturnMessage(error) ?? '', /must end no later than the day before the original employee returns\. The return date is 2026-11-10, so the latest last day is 2026-11-09\. Nothing was changed\./);
    assert.equal(friendlyErrorMessage(error, 'fallback'), replacementReturnMessage(error), 'every assignment dialog shows it through friendlyErrorMessage');
    setActiveLanguage('so');
    assert.match(replacementReturnMessage(error) ?? '', /Taariikhda soo laabashada waa 2026-11-10, sidaa darteed maalinta ugu dambeysa ee la oggol yahay waa 2026-11-09\./);
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(replacementReturnMessage({ code: 'VALIDATION_ERROR', message: 'x' }), null);
  assert.equal(replacementReturnMessage({ code: 'REPLACEMENT_RUNS_PAST_HOLDER_RETURN', message: 'as received', fieldErrors: {} }), null, 'no dates named: the server message is shown as received');
  assert.equal(friendlyErrorMessage({ code: 'REPLACEMENT_RUNS_PAST_HOLDER_RETURN', message: 'as received' }, 'fallback'), 'as received');
});

test('a Replacement refused because another Replacement of the position holds the days (A7) names that Replacement in both languages', () => {
  const error = {
    code: 'REPLACEMENT_OVERLAPS_REPLACEMENT',
    message: 'server wording',
    fieldErrors: { conflicts: [{ kind: 'replacement', id: 7, start_date: '2026-10-16', end_date: null, shift: null, company: 'Alpha' }] },
  };
  try {
    setActiveLanguage('en');
    assert.match(replacementSiblingMessage(error) ?? '', /another Replacement of the same position already holds some of these days: Replacement #7 at Alpha, from 2026-10-16 until /);
    assert.equal(friendlyErrorMessage(error, 'fallback'), replacementSiblingMessage(error));
    setActiveLanguage('so');
    assert.match(replacementSiblingMessage(error) ?? '', /beddelaad kale oo isla jagadan ah ayaa hore u haysa maalmahan qaar: Beddelaadda \(Replacement\) #7 ee Alpha/);
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(replacementSiblingMessage({ code: 'REPLACEMENT_OVERLAPS_REPLACEMENT', message: 'as received', fieldErrors: {} }), null);
  assert.equal(replacementSiblingMessage({ code: 'X', message: 'x' }), null);
});
