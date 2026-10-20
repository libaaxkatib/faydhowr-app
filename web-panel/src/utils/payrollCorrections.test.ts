import test from 'node:test';
import assert from 'node:assert/strict';

import { canManageDebt, canPayUnderpayment, canVoidPayment, canWriteOff, newIdempotencyKey, validateSettlementAmount, validateWriteOffReason } from './payrollCorrections.ts';

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
