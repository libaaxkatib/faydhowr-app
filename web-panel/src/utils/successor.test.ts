import test from 'node:test';
import assert from 'node:assert/strict';

import { EMPTY_SUCCESSOR, missingSuccessors, needsSuccessor, successorStartDate, toSuccessorPayload, validateSuccessor } from './successor.ts';

test('only a Permanent assignment needs a replacement choice', () => {
  assert.equal(needsSuccessor('permanent'), true);
  assert.equal(needsSuccessor(undefined), true);
  assert.equal(needsSuccessor(null), true);
  assert.equal(needsSuccessor('replacement'), false);
});

test('a removal without a choice is not complete', () => {
  assert.match(validateSuccessor(EMPTY_SUCCESSOR) ?? '', /Temporary employee.*Waiting List/);
  assert.equal(toSuccessorPayload(EMPTY_SUCCESSOR), null);
  assert.notEqual(validateSuccessor({ ...EMPTY_SUCCESSOR, type: 'temporary' }), null, 'a type without a person is incomplete');
});

test('a Temporary who covered the position sends no salary: it is inherited automatically', () => {
  const draft = { ...EMPTY_SUCCESSOR, type: 'temporary' as const, employeeId: 12, salaryAmount: '999' };
  assert.equal(validateSuccessor(draft), null);
  assert.deepEqual(toSuccessorPayload(draft), { type: 'temporary', employee_id: 12 });
});

test('a Waiting List person needs the salary HR agrees, with a three-letter currency', () => {
  const base = { ...EMPTY_SUCCESSOR, type: 'waiting_list' as const, employeeId: 7 };
  assert.match(validateSuccessor(base) ?? '', /salary/);
  assert.match(validateSuccessor({ ...base, salaryAmount: '0' }) ?? '', /salary/);
  assert.match(validateSuccessor({ ...base, salaryAmount: '250', salaryCurrency: 'US' }) ?? '', /currency/);
  assert.deepEqual(toSuccessorPayload({ ...base, salaryAmount: '250', salaryCurrency: 'usd' }), { type: 'waiting_list', employee_id: 7, salary_amount: 250, salary_currency: 'USD' });
});

test('the new Permanent assignment starts the day AFTER the last paid day, across month, year and leap boundaries', () => {
  assert.equal(successorStartDate('2026-10-15'), '2026-10-16');
  assert.equal(successorStartDate('2026-10-31'), '2026-11-01');
  assert.equal(successorStartDate('2026-12-31'), '2027-01-01');
  assert.equal(successorStartDate('2028-02-28'), '2028-02-29');
  assert.equal(successorStartDate('2027-02-28'), '2027-03-01');
});

test('a separation lists every open Permanent assignment that still has no choice', () => {
  const ok = { ...EMPTY_SUCCESSOR, type: 'temporary' as const, employeeId: 3 };
  assert.deepEqual(missingSuccessors([10, 11, 12], { 11: ok }), [10, 12]);
  assert.deepEqual(missingSuccessors([11], { 11: ok }), []);
  assert.deepEqual(missingSuccessors([], {}), []);
});
