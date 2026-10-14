import test from 'node:test';
import assert from 'node:assert/strict';

import { decideCreatePosition, findPositionByName, normalisePositionName, OFFICE_STAFF_POSITION_NAMES, positionKey, positionNameError } from './positions.ts';
import { buildPayload, emptyForm, validateOfficeStaffForm } from './officeStaff.ts';

/** Run with: node --test src/utils/positions.test.ts */

const POSITIONS = OFFICE_STAFF_POSITION_NAMES.map((name, index) => ({ id: index + 1, name }));

test('there are exactly nine Office Staff positions, with the names the owner listed', () => {
  assert.equal(OFFICE_STAFF_POSITION_NAMES.length, 9);
  assert.deepEqual([...OFFICE_STAFF_POSITION_NAMES], [
    'Administrative Officer', 'HR Officer', 'Marketing Officer', 'Accountant', 'Sales Officer',
    'Customer Service Officer', 'Secretary / Receptionist', 'Driver', 'Office Cleaner',
  ]);
  assert.equal(new Set(OFFICE_STAFF_POSITION_NAMES.map(positionKey)).size, 9, 'no two of them are the same name');
});

test('names are tidied and compared ignoring letter case and spacing', () => {
  assert.equal(normalisePositionName('  HR   Officer '), 'HR Officer');
  assert.equal(positionKey('  HR   OFFICER '), 'hr officer');
  assert.equal(positionKey('Secretary /  Receptionist'), 'secretary / receptionist');
});

test('every one of the nine is found however it is typed, and a similar but different name is not', () => {
  for (const position of POSITIONS) {
    assert.equal(findPositionByName(POSITIONS, position.name)?.id, position.id);
    assert.equal(findPositionByName(POSITIONS, `  ${position.name.toUpperCase()}  `)?.id, position.id);
  }
  assert.equal(findPositionByName(POSITIONS, 'Driver Assistant'), null);
  assert.equal(findPositionByName(POSITIONS, 'Officer'), null);
  assert.equal(findPositionByName(POSITIONS, '   '), null);
});

test('creating a position that already exists reuses it instead of creating a duplicate', () => {
  assert.deepEqual(decideCreatePosition(POSITIONS, ' driver '), { kind: 'use_existing', id: 8, name: 'Driver' });
  assert.deepEqual(decideCreatePosition(POSITIONS, 'hr   officer'), { kind: 'use_existing', id: 2, name: 'HR Officer' });
});

test('a new name is created tidied, and blank or oversized names are refused', () => {
  assert.deepEqual(decideCreatePosition(POSITIONS, '  Security   Guard '), { kind: 'create', name: 'Security Guard' });
  assert.equal(decideCreatePosition(POSITIONS, '   ').kind, 'invalid');
  assert.equal(decideCreatePosition(POSITIONS, '').kind, 'invalid');
  assert.equal(decideCreatePosition(POSITIONS, 'x'.repeat(151)).kind, 'invalid');
  assert.equal(positionNameError('x'.repeat(150)), null);
});

test('an empty position list creates the first position normally', () => {
  assert.deepEqual(decideCreatePosition([], 'Driver'), { kind: 'create', name: 'Driver' });
});

test('choosing or creating a position never fills in or changes the salary: it is always typed for that employee', () => {
  const form = { ...emptyForm('2026-10-14'), position_id: '' };
  // no position -> still asks for both
  assert.deepEqual(Object.keys(validateOfficeStaffForm(form, 'existing')).filter((k) => k === 'position_id' || k === 'monthly_salary').sort(), ['monthly_salary', 'position_id']);
  // a position chosen -> salary is STILL required and still empty
  const withPosition = { ...form, position_id: '8' };
  assert.equal(withPosition.monthly_salary, '');
  assert.ok('monthly_salary' in validateOfficeStaffForm(withPosition, 'existing'));
  // the same position with two different typed salaries sends exactly what was typed
  const base = { ...withPosition, emergency_contact_name: 'E', emergency_contact_phone: '0615333444', guarantor_name: 'G', guarantor_phone: '0615555666' };
  assert.equal(buildPayload('existing', { ...base, monthly_salary: '150' }, 1).monthly_salary, '150');
  assert.equal(buildPayload('existing', { ...base, monthly_salary: '900.50' }, 2).monthly_salary, '900.50');
  // switching position does not alter the typed salary
  assert.equal(buildPayload('existing', { ...base, monthly_salary: '150', position_id: '3' }, 1).monthly_salary, '150');
});
