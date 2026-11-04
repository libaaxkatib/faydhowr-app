import test from 'node:test';
import assert from 'node:assert/strict';

import {
  assignmentShiftEdit,
  assignmentShiftLabel,
  dayBefore,
  formatCents,
  isCorrectionReasonRequired,
  isTemporaryWorker,
  isValidPeriod,
  returnsToSameAssignment,
  shiftPeriod,
  startDateChangeWarning,
  totalsByCurrency,
  validateAssignForm,
  validateReplaceForm,
  validateReturnForm,
  validateStopForm,
} from './companyEmployees.ts';

const assign = { employee_id: 5, assignment_type: 'permanent' as const, position_id: 2, start_date: '2026-10-01', salary_amount: '250', salary_currency: 'USD' };
const replace = { replacement_employee_id: 9, replacement_date: '2026-10-16', original_last_day: '', assignment_type: 'replacement' as const, position_id: 2, salary_amount: '250', salary_currency: 'USD' };

test('months shift across years and are validated', () => {
  assert.equal(shiftPeriod('2026-12', 1), '2027-01');
  assert.equal(shiftPeriod('2026-01', -1), '2025-12');
  assert.equal(isValidPeriod('2026-13'), false);
  assert.equal(isValidPeriod('2026-10'), true);
});

test('the day before handles month and year boundaries', () => {
  assert.equal(dayBefore('2026-10-16'), '2026-10-15');
  assert.equal(dayBefore('2026-03-01'), '2026-02-28');
  assert.equal(dayBefore('2027-01-01'), '2026-12-31');
});

test('totals are listed per currency and never added together', () => {
  const totals = totalsByCurrency({ USD: 50000, EUR: 10000 });
  assert.deepEqual(totals.map((t) => t.currency), ['EUR', 'USD']);
  assert.equal(totals.find((t) => t.currency === 'USD')?.text, formatCents(50000, 'USD'));
  assert.equal(totals.length, 2);
  assert.deepEqual(totalsByCurrency(undefined), []);
});

test('a complete assignment form is accepted; an empty one names every required choice', () => {
  assert.deepEqual(validateAssignForm(assign), {});
  const empty = validateAssignForm({ employee_id: '', assignment_type: '', position_id: '', start_date: '', salary_amount: '', salary_currency: '' });
  assert.deepEqual(Object.keys(empty).sort(), ['assignment_type', 'employee_id', 'position_id', 'salary_amount', 'salary_currency', 'start_date']);
});

test('the type is never defaulted: Permanent or Replacement must be chosen', () => {
  assert.ok(validateAssignForm({ ...assign, assignment_type: '' }).assignment_type);
  assert.ok(validateReplaceForm({ ...replace, assignment_type: '' }, '2026-01-01', 1).assignment_type);
});

test('salary must be positive and the currency a 3-letter code', () => {
  assert.ok(validateAssignForm({ ...assign, salary_amount: '0' }).salary_amount);
  assert.ok(validateAssignForm({ ...assign, salary_amount: '-5' }).salary_amount);
  assert.ok(validateAssignForm({ ...assign, salary_currency: 'US' }).salary_currency);
});

test('replace: not yourself, a sensible last day, nothing before the assignment started', () => {
  assert.deepEqual(validateReplaceForm(replace, '2026-01-01', 1), {});
  assert.ok(validateReplaceForm({ ...replace, replacement_employee_id: 1 }, '2026-01-01', 1).replacement_employee_id);
  assert.ok(validateReplaceForm({ ...replace, original_last_day: '2026-10-16' }, '2026-01-01', 1).original_last_day);
  const early = validateReplaceForm({ ...replace, replacement_date: '2025-12-31' }, '2026-01-01', 1);
  assert.ok(early.original_last_day, 'the last working day is what is before the assignment started, so the message belongs to that field');
  assert.equal(early.replacement_date, undefined);
});

test('replace: last working day Oct 11, Replacement from Oct 12 on an assignment that started Oct 1 is accepted', () => {
  const october = { ...replace, original_last_day: '2026-10-11', replacement_date: '2026-10-12', assignment_type: 'replacement' as const, salary_amount: '', salary_currency: '' };
  assert.deepEqual(validateReplaceForm(october, '2026-10-01', 1), {});
  // The same dates on a row that starts Oct 13 are refused - and the message names the last working day, not the Replacement date.
  const refused = validateReplaceForm(october, '2026-10-13', 1);
  assert.ok(refused.original_last_day);
  assert.equal(refused.replacement_date, undefined);
  // A last working day on the very first day of the assignment is allowed.
  assert.deepEqual(validateReplaceForm({ ...october, original_last_day: '2026-10-01', replacement_date: '2026-10-02' }, '2026-10-01', 1), {});
});

test('a Return resumes the same assignment only while the original is still open; older data (original already ended) gets a new Permanent one', () => {
  assert.equal(returnsToSameAssignment({ status: 'active', end_date: null }), true);
  assert.equal(returnsToSameAssignment({ status: 'ended', end_date: '2026-10-11' }), false, 'the production shape #272 -> #297 -> #298');
  assert.equal(returnsToSameAssignment({ status: 'active', end_date: '2026-10-31' }), false);
  assert.equal(returnsToSameAssignment(undefined), false, 'unknown (history still loading): never claim the same assignment');
});

const salary = { amount: '300', currency: 'USD', confirmed: true };

test('a Replacement needs no salary of its own; a Permanent successor does', () => {
  const noSalary = { ...replace, salary_amount: '', salary_currency: '' };
  assert.deepEqual(validateReplaceForm(noSalary, '2026-01-01', 1), {}, 'Replacement: the salary is inherited from the replaced Permanent');
  const permanent = validateReplaceForm({ ...noSalary, assignment_type: 'permanent' }, '2026-01-01', 1);
  assert.ok(permanent.salary_amount && permanent.salary_currency);
});

test('the returning employee salary must be entered and explicitly confirmed, never assumed', () => {
  assert.deepEqual(validateReturnForm('2026-10-21', '2026-10-01', salary), {});
  assert.ok(validateReturnForm('2026-10-21', '2026-10-01', { ...salary, confirmed: false }).salary_confirmed);
  assert.ok(validateReturnForm('2026-10-21', '2026-10-01', { ...salary, amount: '' }).salary_amount);
  assert.ok(validateReturnForm('2026-10-21', '2026-10-01', { ...salary, amount: '0' }).salary_amount);
  assert.ok(validateReturnForm('2026-10-21', '2026-10-01', { ...salary, currency: 'US' }).salary_currency);
});

test('the server asking for a correction reason and the Temporary-worker warning are recognised', () => {
  assert.equal(isCorrectionReasonRequired({ code: 'CORRECTION_REASON_REQUIRED' }), true);
  assert.equal(isCorrectionReasonRequired({ code: 'CLOSED_PAYROLL_CORRECTION_NOT_ALLOWED' }), false);
  assert.equal(isCorrectionReasonRequired(null), false);
  assert.equal(isTemporaryWorker({ employment_type: 'temporary' }), true);
  assert.equal(isTemporaryWorker({ employment_type: 'permanent' }), false);
  assert.equal(isTemporaryWorker(null), false);
});

test('return and stop checks', () => {
  assert.deepEqual(validateReturnForm('2026-10-21', '2026-10-01', salary), {});
  assert.ok(validateReturnForm('2026-10-01', '2026-10-01', salary).return_date);
  assert.ok(validateReturnForm('', '2026-10-01', salary).return_date);
  assert.deepEqual(validateStopForm('2026-10-20', 'contract ended', '2026-10-01'), {});
  assert.ok(validateStopForm('2026-09-30', 'contract ended', '2026-10-01').end_date);
  assert.ok(validateStopForm('2026-10-20', 'x', '2026-10-01').reason);
});

test('changing a start date warns, naming the first month it touches and keeping the original', () => {
  assert.equal(startDateChangeWarning('2026-10-10', '2026-10-10'), null);
  const warning = startDateChangeWarning('2026-10-10', '2026-08-20');
  assert.match(warning ?? '', /2026-08/);
  assert.match(warning ?? '', /2026-10-10/);
  assert.match(warning ?? '', /Super Admin/, 'it says who can change a closed month');
});

test('a shift is shown as Day or Night, and an assignment without one is Not recorded (never assumed)', () => {
  assert.equal(assignmentShiftLabel('day'), 'Day shift');
  assert.equal(assignmentShiftLabel('night'), 'Night shift');
  assert.equal(assignmentShiftLabel(null), 'Not recorded');
  assert.equal(assignmentShiftLabel(undefined), 'Not recorded');
  assert.equal(assignmentShiftLabel('evening'), 'Not recorded');
});

test('an edit sends the shift only when HR changed it, and an empty choice clears it', () => {
  assert.deepEqual(assignmentShiftEdit(null, ''), {}, 'unchanged unknown: nothing is sent');
  assert.deepEqual(assignmentShiftEdit('day', 'day'), {}, 'unchanged: nothing is sent, so editing the salary never touches the shift');
  assert.deepEqual(assignmentShiftEdit(null, 'night'), { shift: 'night' });
  assert.deepEqual(assignmentShiftEdit('day', 'night'), { shift: 'night' });
  assert.deepEqual(assignmentShiftEdit('night', ''), { shift: null });
});
