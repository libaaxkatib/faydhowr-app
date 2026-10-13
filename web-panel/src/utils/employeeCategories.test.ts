import test from 'node:test';
import assert from 'node:assert/strict';

import { OFFICE_STAFF_CATEGORY, recruitmentCategories } from './employeeCategories.ts';

/** Run with: node --test src/utils/employeeCategories.test.ts */

test('recruitment pickers leave out Office Staff, but keep it for an employee who already has it', () => {
  const all = [{ id: 1, name: 'General Cleaning' }, { id: 2, name: 'Cooking' }, { id: 6, name: 'Office Staff' }];
  assert.deepEqual(recruitmentCategories(all).map((c) => c.id), [1, 2]);
  assert.deepEqual(recruitmentCategories(all, 6).map((c) => c.id), [1, 2, 6]);
  assert.deepEqual(recruitmentCategories(all, 1).map((c) => c.id), [1, 2]);
  assert.deepEqual(recruitmentCategories([{ id: 9, name: ' office staff ' }]).map((c) => c.id), [], 'letter case and spacing do not matter');
  assert.equal(OFFICE_STAFF_CATEGORY, 'Office Staff');
});
