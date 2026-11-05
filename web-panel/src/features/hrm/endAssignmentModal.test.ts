import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

import { EMPTY_SUCCESSOR, optionalSuccessor, type SuccessorDraft } from '../../utils/successor.ts';

// "End Assignment" on the employee page (EndAssignmentModal): a replacement is OPTIONAL (owner decision 2026-11-04), as it already is in the Stop dialog of
// the company employees page. It is checked only when HR starts choosing one. The server stays the authority and validates whatever it receives.
const source = readFileSync(new URL('./EmployeeDetailPage.tsx', import.meta.url), 'utf8');
const modal = source.slice(source.indexOf('function EndAssignmentModal('), source.indexOf('function MarkSeparatedModal('));

test('no replacement chosen: the end is allowed and no successor is sent', () => {
  assert.deepEqual(optionalSuccessor(EMPTY_SUCCESSOR, '2026-10-15'), { error: null, payload: undefined });
  assert.deepEqual(optionalSuccessor(EMPTY_SUCCESSOR), { error: null, payload: undefined });
});

test('a replacement that is chosen is validated exactly as before', () => {
  const half: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'temporary' };
  assert.notEqual(optionalSuccessor(half, '2026-10-15').error, null, 'a type without a person is incomplete');
  assert.equal(optionalSuccessor(half, '2026-10-15').payload, undefined);

  const noSalary: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'waiting_list', employeeId: 9 };
  assert.match(optionalSuccessor(noSalary, '2026-10-15').error ?? '', /salary/);

  const tooEarly: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'temporary', employeeId: 9, effectiveDate: '2026-10-15' };
  assert.match(optionalSuccessor(tooEarly, '2026-10-15').error ?? '', /must be after the last paid day/);
});

test('a valid replacement becomes the request payload', () => {
  const temporary: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'temporary', employeeId: 12 };
  assert.deepEqual(optionalSuccessor(temporary, '2026-10-15'), { error: null, payload: { type: 'temporary', employee_id: 12 } });

  const waiting: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'waiting_list', employeeId: 9, salaryAmount: '250', salaryCurrency: 'usd' };
  assert.deepEqual(optionalSuccessor(waiting, '2026-10-15').payload, { type: 'waiting_list', employee_id: 9, salary_amount: 250, salary_currency: 'USD' });
});

test('the dialog no longer forces a choice: it uses the optional check and says the replacement is optional', () => {
  assert.ok(modal.length > 200, 'the dialog source was found');
  assert.match(modal, /optionalSuccessor\(successor, endDate\)/);
  assert.match(modal, /onConfirm\(endDate, note \|\| undefined, chosen\.payload\)/);
  assert.match(modal, /data-testid="end-successor-optional">\{t\('option\.correction\.replacementOptional'\)\}/);
  // The old rule asked for a complete choice on every Permanent assignment.
  assert.doesNotMatch(modal, /validateSuccessor\(/);
  assert.doesNotMatch(modal, /cannot be left without a replacement/);
});

test('the end date is still required and non-Permanent assignments are not asked for a replacement at all', () => {
  assert.match(modal, /htmlFor="end-date" required/);
  assert.match(modal, /const askSuccessor = needsSuccessor\(assignment\.assignment_type\);/);
  assert.match(modal, /askSuccessor && <SuccessorPicker /);
  assert.match(modal, /const chosen = askSuccessor \? optionalSuccessor\(successor, endDate\) : \{ error: null, payload: undefined \};/);
});
