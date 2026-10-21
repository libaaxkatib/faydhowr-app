import test from 'node:test';
import assert from 'node:assert/strict';

import { activeFilterCount, activeMoreFilterCount, parseExtraEmployeeFilters } from './employeeListFilters.ts';

test('every filter is read back from the address bar', () => {
  const f = parseExtraEmployeeFilters('?q=amina&department_id=3&location=Hodan&profile_complete=false&guarantor_needed=true&is_supervisor=true&joining_date_from=2026-01-01&joining_date_to=2026-02-01&page=4');
  assert.deepEqual(f, {
    search: 'amina',
    departmentId: '3',
    location: 'Hodan',
    profileComplete: 'false',
    guarantorNeeded: 'true',
    isSupervisor: 'true',
    joiningDateFrom: '2026-01-01',
    joiningDateTo: '2026-02-01',
    page: 4,
  });
});

test('anything malformed is dropped, never half applied', () => {
  const f = parseExtraEmployeeFilters('?department_id=abc&profile_complete=maybe&joining_date_from=yesterday&page=-2');
  assert.equal(f.departmentId, '');
  assert.equal(f.profileComplete, '');
  assert.equal(f.joiningDateFrom, '');
  assert.equal(f.page, 1);
  assert.equal(parseExtraEmployeeFilters('').page, 1);
});

test('the "More filters" badge counts only the collapsed filters, the clear button counts all but the page', () => {
  assert.equal(activeMoreFilterCount('?q=x&status=active&department_id=2'), 0);
  assert.equal(activeMoreFilterCount('?location=Hodan&profile_complete=true&application_date_from=2026-01-01'), 3);
  assert.equal(activeFilterCount('?q=x&status=active&department_id=2&page=3'), 3);
  assert.equal(activeFilterCount(''), 0);
});
