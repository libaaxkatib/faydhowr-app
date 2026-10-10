import test from 'node:test';
import assert from 'node:assert/strict';

import {
  assignmentLabel,
  describeDailyRate,
  duplicateMatchesFromError,
  expandDateRange,
  friendlyErrorMessage,
  isWorkplaceMissing,
  localToday,
  nextMonth,
} from './hrHelpers.ts';

/**
 * Run with: node --test src/utils/hrHelpers.test.ts
 * Covers the pure helpers behind the assignment labels, plain-language error
 * messages, duplicate-phone handling and the workday date range expansion.
 */

test('assignmentLabel shows the office, company + workplace, or just the company while the workplace is not set', () => {
  assert.equal(assignmentLabel({ location_type: 'office', client_company_name: null, work_location_name: 'Main' }), 'Fayadhowr Office');
  assert.equal(assignmentLabel({ location_type: 'client', client_company_name: 'Acme', work_location_name: 'Branch 1' }), 'Acme · Branch 1');
  assert.equal(assignmentLabel({ location_type: 'client', client_company_name: 'Acme', work_location_name: null }), 'Acme');
  assert.equal(assignmentLabel({ location_type: 'client', client_company_name: 'Acme' }), 'Acme');
});

test('isWorkplaceMissing is true only for a company assignment without a location', () => {
  assert.equal(isWorkplaceMissing({ location_type: 'client', work_location_id: null }), true);
  assert.equal(isWorkplaceMissing({ location_type: 'client', work_location_id: 4 }), false);
  assert.equal(isWorkplaceMissing({ location_type: 'office', work_location_id: 9 }), false);
});

test('friendlyErrorMessage prefers plain wording for known codes, then the server message, then the fallback', () => {
  assert.match(friendlyErrorMessage({ code: 'PAYROLL_PERIOD_LOCKED', message: 'x' }, 'fallback'), /payroll adjustment/);
  assert.equal(friendlyErrorMessage({ code: 'SOMETHING_NEW', message: 'Server says no.' }, 'fallback'), 'Server says no.');
  assert.equal(friendlyErrorMessage({}, 'fallback'), 'fallback');
  assert.equal(friendlyErrorMessage('not an error', 'fallback'), 'fallback');
});

test('friendlyErrorMessage shows the first specific field message for a generic validation error', () => {
  const error = { code: 'VALIDATION_ERROR', message: 'The given data was invalid.', fieldErrors: { name: ['The name has already been taken.'] } };
  assert.equal(friendlyErrorMessage(error, 'fallback'), 'The name has already been taken.');
  assert.equal(friendlyErrorMessage({ code: 'OTHER', message: 'Kept.', fieldErrors: { name: ['x'] } }, 'fallback'), 'Kept.');
});

test('duplicateMatchesFromError returns the matches only for a DUPLICATE_EMPLOYEE_PHONE answer', () => {
  const match = { id: 7, employee_number: 'EMP-7', full_name: 'Amina', status: 'active', archived: false, matched_on: ['phone'] };
  const error = { code: 'DUPLICATE_EMPLOYEE_PHONE', fieldErrors: { matches: [match, { nonsense: true }] } };
  assert.deepEqual(duplicateMatchesFromError(error), [match]);
  assert.equal(duplicateMatchesFromError({ code: 'VALIDATION_ERROR', fieldErrors: { matches: [match] } }), null);
  assert.deepEqual(duplicateMatchesFromError({ code: 'DUPLICATE_EMPLOYEE_PHONE' }), []);
});

test('expandDateRange lists every day inclusively and refuses bad or oversized ranges', () => {
  assert.deepEqual(expandDateRange('2026-02-27', '2026-03-02'), ['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02']);
  assert.deepEqual(expandDateRange('2026-05-05', '2026-05-05'), ['2026-05-05']);
  assert.deepEqual(expandDateRange('2026-05-06', '2026-05-05'), []);
  assert.deepEqual(expandDateRange('', '2026-05-05'), []);
  assert.deepEqual(expandDateRange('2026-01-01', '2026-12-31'), []);
});

test('localToday and nextMonth format dates as the API expects', () => {
  assert.equal(localToday(new Date(2026, 0, 5)), '2026-01-05');
  assert.equal(nextMonth('2026-12'), '2027-01');
  assert.equal(nextMonth('2026-03'), '2026-04');
});

test('describeDailyRate formats numbers and leaves explanatory text alone', () => {
  assert.equal(describeDailyRate('10.0000'), '$10.00 per day');
  assert.equal(describeDailyRate('varies during the month'), 'varies during the month');
});
