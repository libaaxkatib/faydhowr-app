import test from 'node:test';
import assert from 'node:assert/strict';

import { replacedEmployeeDisplay, replacedEmployeeText } from './replacedEmployee.ts';

const link = { assignment_id: 11, employee_id: 7, employee_number: 'EMP-000007', full_name: 'Amina Yusuf' };

test('a Replacement shows the Permanent employee it really replaces, from the recorded link', () => {
  const display = replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: link });
  assert.deepEqual(display, { kind: 'linked', employeeId: 7, name: 'Amina Yusuf', number: 'EMP-000007' });
  assert.equal(display.kind === 'linked' && replacedEmployeeText(display), 'Amina Yusuf (EMP-000007)');
});

test('a Permanent row shows nothing, even if a link were somehow present', () => {
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: 'permanent', replaces: null }), { kind: 'none' });
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: 'permanent', replaces: link }), { kind: 'none' });
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: undefined, replaces: link }), { kind: 'none' });
});

test('a Replacement with no recorded link says so plainly - nothing is guessed or filled in', () => {
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: null }), { kind: 'missing' });
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: undefined }), { kind: 'missing' });
  assert.deepEqual(replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: { ...link, employee_id: 0 } }), { kind: 'missing' }, 'a link without a real employee is no link');
});

test('a link whose employee record has no name or number still points at the real employee, by ID', () => {
  const display = replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: { ...link, full_name: null, employee_number: null } });
  assert.deepEqual(display, { kind: 'linked', employeeId: 7, name: null, number: null });
  assert.equal(display.kind === 'linked' && replacedEmployeeText(display), 'ID 7');

  const blank = replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: { ...link, full_name: '  ', employee_number: '' } });
  assert.deepEqual(blank, { kind: 'linked', employeeId: 7, name: null, number: null });
});

test('the text leaves out a missing number but keeps the name', () => {
  const display = replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: { ...link, employee_number: null } });
  assert.equal(display.kind === 'linked' && replacedEmployeeText(display), 'Amina Yusuf');
});
