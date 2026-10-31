import test from 'node:test';
import assert from 'node:assert/strict';

import { PICKER_PAGE_SIZE, hasMore, pickerLabel, pickerOptions, pickerParams, type PickerEmployee } from './employeePicker.ts';

const person = (id: number, name: string, extra: Partial<PickerEmployee> = {}): PickerEmployee => ({ id, full_name: name, employee_number: `EMP-${String(id).padStart(6, '0')}`, ...extra });
const notReady = (missing: string[]) => `registration not complete: ${missing.join(', ')}`;

test('the picker asks the server for Active people of ONE type, a page at a time - never the first 100 of everyone', () => {
  assert.deepEqual(pickerParams('temporary', '', 1), { status: 'active', employment_type: 'temporary', per_page: PICKER_PAGE_SIZE, page: 1 });
  assert.deepEqual(pickerParams('permanent', '', 3), { status: 'active', employment_type: 'permanent', per_page: PICKER_PAGE_SIZE, page: 3 });
  assert.ok(PICKER_PAGE_SIZE <= 100, 'within the endpoint maximum');
});

test('a typed term is trimmed and also asks the server to match the database ID; a blank term sends no search at all', () => {
  assert.deepEqual(pickerParams('temporary', '  hodan  ', 1), { status: 'active', employment_type: 'temporary', search: 'hodan', search_by_id: true, per_page: PICKER_PAGE_SIZE, page: 1 });
  assert.deepEqual(pickerParams('temporary', '42', 2), { status: 'active', employment_type: 'temporary', search: '42', search_by_id: true, per_page: PICKER_PAGE_SIZE, page: 2 });
  assert.equal('search' in pickerParams('temporary', '   ', 1), false);
  assert.equal('search_by_id' in pickerParams('temporary', '', 1), false);
});

test('every option carries the name, the employee number and the ID, so two people with the same name are told apart', () => {
  const options = pickerOptions([person(7, 'Hodan Ali'), person(8, 'Hodan Ali')], { employmentType: 'temporary', notReadyLabel: notReady });
  assert.deepEqual(options, [
    { value: '7', label: 'Hodan Ali (EMP-000007 · ID 7)' },
    { value: '8', label: 'Hodan Ali (EMP-000008 · ID 8)' },
  ]);
  assert.equal(pickerLabel(person(7, 'Hodan Ali')), 'Hodan Ali (EMP-000007 · ID 7)');
});

test('the covered person is never offered as their own replacement, and nobody is listed twice', () => {
  const options = pickerOptions([person(1, 'Covered'), person(2, 'Cover'), person(2, 'Cover')], { employmentType: 'temporary', excludeId: '1', notReadyLabel: notReady });
  assert.deepEqual(options.map((o) => o.value), ['2']);
});

test('a Temporary person whose registration is not complete is shown disabled with the reason; a complete one is selectable', () => {
  const options = pickerOptions(
    [
      person(1, 'Complete', { registration_workflow: { eligible_for_waiting_and_company: true, missing: [] } }),
      person(2, 'Incomplete', { registration_workflow: { eligible_for_waiting_and_company: false, missing: ['Guarantor verified', 'Contract signed'] } }),
      person(3, 'Unknown'),
    ],
    { employmentType: 'temporary', notReadyLabel: notReady },
  );
  assert.equal(options[0].disabled, undefined);
  assert.equal(options[1].disabled, true);
  assert.match(options[1].label, /registration not complete: Guarantor verified, Contract signed$/);
  assert.equal(options[2].disabled, undefined, 'no summary means nothing is claimed: the server still decides on save');
});

test('the covered (Permanent) picker never disables anyone: the registration workflow is a rule for the person who covers', () => {
  const options = pickerOptions([person(1, 'Permanent', { registration_workflow: { eligible_for_waiting_and_company: false, missing: ['Uniform confirmed'] } })], { employmentType: 'permanent', notReadyLabel: notReady });
  assert.equal(options[0].disabled, undefined);
});

test('"Show more" is offered only while more people match than are listed', () => {
  assert.equal(hasMore(50, 120), true);
  assert.equal(hasMore(120, 120), false);
  assert.equal(hasMore(0, 0), false);
});
