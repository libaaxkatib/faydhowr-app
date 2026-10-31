import test from 'node:test';
import assert from 'node:assert/strict';

import { PICKER_PAGE_SIZE, foundButNoneChoosable, hasMore, isSearching, notChoosable, pickerLabel, pickerOptions, pickerParams, type NotChoosable, type PickerEmployee } from './employeePicker.ts';

const person = (id: number, name: string, extra: Partial<PickerEmployee> = {}): PickerEmployee => ({
  id,
  full_name: name,
  employee_number: `EMP-${String(id).padStart(6, '0')}`,
  status: 'active',
  employment_type: 'temporary',
  ...extra,
});
const ready = { eligible_for_waiting_and_company: true, missing: [] as string[] };
const incomplete = { eligible_for_waiting_and_company: false, missing: ['Guarantor verified'] };
const say = (why: NotChoosable) => (why.reason === 'not_active' ? `not Active (${why.status})` : why.reason);

// ------------------------------------------------------------------ the query

test('nothing typed: the pool of people who can be chosen - Active, of ONE type, a page of 50 at a time', () => {
  assert.deepEqual(pickerParams('temporary', '', 1), { status: 'active', employment_type: 'temporary', per_page: PICKER_PAGE_SIZE, page: 1 });
  assert.deepEqual(pickerParams('permanent', '   ', 3), { status: 'active', employment_type: 'permanent', per_page: PICKER_PAGE_SIZE, page: 3 });
  assert.ok(PICKER_PAGE_SIZE <= 100, 'within the endpoint maximum');
});

test('something typed: EVERY employee is searched (no status and no type filter), by name, number, phone and database ID', () => {
  for (const kind of ['temporary', 'permanent'] as const) {
    const params = pickerParams(kind, '  hodan  ', 1);
    assert.deepEqual(params, { search: 'hodan', search_by_id: true, per_page: PICKER_PAGE_SIZE, page: 1 });
    assert.equal('status' in params, false, 'a person who is not Active must still be found');
    assert.equal('employment_type' in params, false, 'a Permanent person must still be found');
  }
  assert.deepEqual(pickerParams('temporary', 'EMP-005625', 2), { search: 'EMP-005625', search_by_id: true, per_page: PICKER_PAGE_SIZE, page: 2 }, 'Show more asks for page 2 of the SAME search');
  assert.equal(isSearching('  x '), true);
  assert.equal(isSearching('   '), false);
});

test('a new search starts at page 1 and "Show more" moves one page at a time within it', () => {
  assert.equal(pickerParams('temporary', 'ali', 1).page, 1);
  assert.equal(pickerParams('temporary', 'ali', 2).page, 2);
  assert.equal(pickerParams('temporary', 'ali', 2).search, pickerParams('temporary', ' ali ', 3).search, 'the term is trimmed, so a stray space never starts a different search');
});

// ------------------------------------------------------------------ who can be chosen, and why not

test('the person doing the cover: only an Active, registered Temporary employee can be chosen', () => {
  assert.equal(notChoosable(person(1, 'Ok', { registration_workflow: ready }), 'temporary'), null);
  assert.equal(notChoosable(person(2, 'No summary'), 'temporary'), null, 'no summary means nothing is claimed: the server still decides on save');
});

test('a Permanent employee found by the search cannot be chosen to cover (and is never converted)', () => {
  assert.deepEqual(notChoosable(person(3, 'Perm', { employment_type: 'permanent' }), 'temporary'), { reason: 'not_temporary' });
  assert.deepEqual(notChoosable(person(4, 'No type', { employment_type: undefined }), 'temporary'), { reason: 'not_temporary' }, 'no type means Permanent, the default');
  assert.deepEqual(notChoosable(person(5, 'Null type', { employment_type: null }), 'temporary'), { reason: 'not_temporary' });
});

test('a Waiting List, Applicant or Inactive person cannot be chosen, even if their type is Temporary (Waiting List stays ineligible)', () => {
  for (const status of ['waiting', 'applicant', 'inactive', 'approved']) {
    assert.deepEqual(notChoosable(person(6, 'X', { status }), 'temporary'), { reason: 'not_active', status });
  }
  assert.deepEqual(notChoosable(person(7, 'Waiting perm', { status: 'waiting', employment_type: 'permanent' }), 'temporary'), { reason: 'not_active', status: 'waiting' }, 'status is said before type');
});

test('a Temporary person whose registration is not complete cannot be chosen', () => {
  assert.deepEqual(notChoosable(person(8, 'Inc', { registration_workflow: incomplete }), 'temporary'), { reason: 'registration_incomplete' });
  assert.deepEqual(notChoosable(person(9, 'Perm inc', { employment_type: 'permanent', registration_workflow: incomplete }), 'temporary'), { reason: 'not_temporary' }, 'the type is the more basic reason');
});

test('the covered employee cannot be chosen as their own replacement', () => {
  assert.deepEqual(notChoosable(person(10, 'Same'), 'temporary', '10'), { reason: 'covered_person' });
  assert.equal(notChoosable(person(10, 'Same'), 'temporary', '11'), null);
});

test('the person being covered: an Active Permanent employee; a Temporary employee cannot be covered; the registration workflow does not apply', () => {
  assert.equal(notChoosable(person(11, 'P', { employment_type: 'permanent' }), 'permanent'), null);
  assert.equal(notChoosable(person(12, 'P2', { employment_type: undefined }), 'permanent'), null);
  assert.deepEqual(notChoosable(person(13, 'T'), 'permanent'), { reason: 'not_permanent' });
  assert.deepEqual(notChoosable(person(14, 'W', { employment_type: 'permanent', status: 'waiting' }), 'permanent'), { reason: 'not_active', status: 'waiting' });
  assert.equal(notChoosable(person(15, 'P3', { employment_type: 'permanent', registration_workflow: incomplete }), 'permanent'), null);
});

// ------------------------------------------------------------------ the options

test('every option carries the name, the employee number and the ID, so two people with the same name are told apart', () => {
  const options = pickerOptions([person(7, 'Hodan Ali'), person(8, 'Hodan Ali')], { employmentType: 'temporary', reasonText: say });
  assert.deepEqual(options, [
    { value: '7', label: 'Hodan Ali (EMP-000007 · ID 7)' },
    { value: '8', label: 'Hodan Ali (EMP-000008 · ID 8)' },
  ]);
  assert.equal(pickerLabel(person(7, 'Hodan Ali')), 'Hodan Ali (EMP-000007 · ID 7)');
});

test('a search result lists everyone found: the ones who cannot be chosen are disabled with the reason, and come after the ones who can', () => {
  const found = [
    person(1, 'Perm one', { employment_type: 'permanent' }),
    person(2, 'Temp ok', { registration_workflow: ready }),
    person(3, 'Waiting temp', { status: 'waiting' }),
    person(4, 'Temp incomplete', { registration_workflow: incomplete }),
    person(5, 'Temp ok two'),
  ];
  const options = pickerOptions(found, { employmentType: 'temporary', reasonText: say });

  assert.deepEqual(options.map((o) => [o.value, o.disabled === true]), [['2', false], ['5', false], ['1', true], ['3', true], ['4', true]]);
  assert.match(options[2].label, /^Perm one \(EMP-000001 · ID 1\) — not_temporary$/);
  assert.match(options[3].label, / — not Active \(waiting\)$/);
  assert.match(options[4].label, / — registration_incomplete$/);
  assert.equal(options.length, found.length, 'nobody who matched the search is hidden');
});

test('the covered employee is listed disabled (so a search for them explains why), and nobody is listed twice', () => {
  const options = pickerOptions([person(1, 'Covered'), person(2, 'Cover'), person(2, 'Cover')], { employmentType: 'temporary', excludeId: '1', reasonText: say });
  assert.deepEqual(options.map((o) => [o.value, o.disabled === true]), [['2', false], ['1', true]]);
});

test('"Show more" is offered only while more people match than are listed', () => {
  assert.equal(hasMore(50, 120), true);
  assert.equal(hasMore(120, 120), false);
  assert.equal(hasMore(0, 0), false);
});

test('when people were found but none can be chosen, the screen says so (the greyed names are only visible once the list is opened)', () => {
  const none = pickerOptions([person(1, 'Perm', { employment_type: 'permanent' }), person(2, 'Waiting', { status: 'waiting' })], { employmentType: 'temporary', reasonText: say });
  assert.equal(foundButNoneChoosable(none), true);
  const some = pickerOptions([person(1, 'Perm', { employment_type: 'permanent' }), person(2, 'Ok')], { employmentType: 'temporary', reasonText: say });
  assert.equal(foundButNoneChoosable(some), false);
  assert.equal(foundButNoneChoosable([]), false, 'nobody found is a different message ("No employee matches this search")');
});
