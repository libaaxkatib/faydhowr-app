import test from 'node:test';
import assert from 'node:assert/strict';

import { canManageDebt, canPayUnderpayment, canVoidPayment, canWriteOff, newIdempotencyKey, settlementToday, validateSettlementAmount, validateSettlementDate, validateWriteOffReason } from './payrollCorrections.ts';

test('only a Super Admin is offered the payment of an underpayment', () => {
  assert.equal(canPayUnderpayment('super_admin'), true);
  for (const role of ['hr_manager', 'marketing_manager', 'mobile_app_manager', 'hr_employee', undefined, null]) assert.equal(canPayUnderpayment(role), false);
});

test('a Super Admin or an HR Manager is offered the management of an employee debt, nobody else', () => {
  assert.equal(canManageDebt('super_admin'), true);
  assert.equal(canManageDebt('hr_manager'), true);
  for (const role of ['marketing_manager', 'mobile_app_manager', 'hr_employee', 'sales', undefined]) assert.equal(canManageDebt(role), false);
});

test('every dialog opening gets its own idempotency key', () => {
  const a = newIdempotencyKey();
  const b = newIdempotencyKey();
  assert.notEqual(a, b);
  assert.ok(a.length >= 8 && a.length <= 64);
});

test('an amount must be positive and not more than what is still open', () => {
  assert.equal(validateSettlementAmount('100', 10000), null);
  assert.equal(validateSettlementAmount('100.01', 10000) !== null, true);
  assert.equal(validateSettlementAmount('0', 10000) !== null, true);
  assert.equal(validateSettlementAmount('', 10000) !== null, true);
  assert.equal(validateSettlementAmount('abc', 10000) !== null, true);
});

test('only a Super Admin is offered the write-off of a debt and the voiding of a payment to an employee', () => {
  assert.equal(canWriteOff('super_admin'), true);
  assert.equal(canVoidPayment('super_admin'), true);
  for (const role of ['hr_manager', 'marketing_manager', 'mobile_app_manager', 'hr_employee', undefined, null]) {
    assert.equal(canWriteOff(role), false);
    assert.equal(canVoidPayment(role), false);
  }
});

test('a write-off needs a real reason', () => {
  assert.equal(validateWriteOffReason('Employee left the country'), null);
  assert.notEqual(validateWriteOffReason(''), null);
  assert.notEqual(validateWriteOffReason('   '), null);
  assert.notEqual(validateWriteOffReason('no'), null);
});

test('a payroll payment marked paid can be voided by a Super Admin or an HR Manager only', async () => {
  const { canVoidPayrollPayment } = await import('./payrollCorrections.ts');
  assert.equal(canVoidPayrollPayment('super_admin'), true);
  assert.equal(canVoidPayrollPayment('hr_manager'), true);
  for (const role of ['marketing_manager', 'mobile_app_manager', 'hr_employee', 'sales', undefined, null]) assert.equal(canVoidPayrollPayment(role), false);
});

// F-DATE: the settlement day is the Mogadishu calendar day (UTC+3, no daylight saving), whatever time zone the browser is in.
const at = (iso: string) => new Date(`${iso}Z`);

test('the settlement dialog proposes the Mogadishu day, also between 00:00 and 03:00 when UTC is still on the previous day', () => {
  assert.equal(settlementToday(at('2026-10-25T21:00:00')), '2026-10-26'); // Mogadishu 00:00
  assert.equal(settlementToday(at('2026-10-25T23:59:00')), '2026-10-26'); // Mogadishu 02:59
  assert.equal(settlementToday(at('2026-10-25T20:59:59')), '2026-10-25'); // Mogadishu 23:59:59 the day before
  assert.equal(settlementToday(at('2026-10-26T00:00:00')), '2026-10-26'); // Mogadishu 03:00, the calendars agree again
  assert.equal(settlementToday(at('2026-10-25T12:00:00')), '2026-10-25'); // an ordinary time of day
  assert.equal(settlementToday(at('2026-10-31T22:00:00')), '2026-11-01'); // a month boundary
  assert.equal(settlementToday(at('2026-12-31T21:30:00')), '2027-01-01'); // a year boundary
});

test('the settlement date accepts today and the past in Mogadishu and refuses a genuinely future day', () => {
  const night = at('2026-10-25T21:30:00'); // 00:30 on 26 October in Mogadishu, still 25 October in UTC
  assert.equal(validateSettlementDate('2026-10-26', night), null);
  assert.equal(validateSettlementDate('2026-10-25', night), null);
  assert.notEqual(validateSettlementDate('2026-10-27', night), null);

  const justBefore = at('2026-10-25T20:59:59'); // 23:59:59 on 25 October in Mogadishu
  assert.equal(validateSettlementDate('2026-10-25', justBefore), null);
  assert.notEqual(validateSettlementDate('2026-10-26', justBefore), null);

  const midday = at('2026-10-25T12:00:00');
  assert.equal(validateSettlementDate('2026-10-25', midday), null);
  assert.notEqual(validateSettlementDate('2026-10-26', midday), null);
});

test('a settlement date that is not a plain calendar day is refused and the day itself is never rewritten', () => {
  const now = at('2026-10-25T12:00:00');
  for (const value of ['', 'tomorrow', '2026-10-25T10:00:00', '25/10/2026', '2026-1-5']) assert.notEqual(validateSettlementDate(value, now), null, value);
  assert.equal(validateSettlementDate('2026-10-25', now), null);
  // the value is compared as text and sent as text: no Date object is built from it, so no time zone can move it to another day
  assert.equal(settlementToday(now).length, 10);
});
