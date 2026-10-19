import test from 'node:test';
import assert from 'node:assert/strict';

import {
  dayBefore,
  formatCents,
  isValidPeriod,
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
  assert.ok(validateReplaceForm({ ...replace, replacement_date: '2025-12-31' }, '2026-01-01', 1).replacement_date);
});

test('return and stop checks', () => {
  assert.deepEqual(validateReturnForm('2026-10-21', '2026-10-01'), {});
  assert.ok(validateReturnForm('2026-10-01', '2026-10-01').return_date);
  assert.ok(validateReturnForm('', '2026-10-01').return_date);
  assert.deepEqual(validateStopForm('2026-10-20', 'contract ended', '2026-10-01'), {});
  assert.ok(validateStopForm('2026-09-30', 'contract ended', '2026-10-01').end_date);
  assert.ok(validateStopForm('2026-10-20', 'x', '2026-10-01').reason);
});

test('changing a start date warns, naming the first month it touches and keeping the original', () => {
  assert.equal(startDateChangeWarning('2026-10-10', '2026-10-10'), null);
  const warning = startDateChangeWarning('2026-10-10', '2026-08-20');
  assert.match(warning ?? '', /2026-08/);
  assert.match(warning ?? '', /2026-10-10/);
});
