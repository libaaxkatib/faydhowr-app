import test from 'node:test';
import assert from 'node:assert/strict';

import {
  categoryTileLabel,
  categoryTileTo,
  dashboardCardTo,
  parseEmployeeListUrl,
  parseReplacementStatus,
  parseWorkforceRequestStatuses,
  utcDateDaysAgo,
  type DashboardCardKey,
} from './hrDashboardLinks.ts';

/** Run with: node --test src/utils/hrDashboardLinks.test.ts */

const NOW = new Date('2026-10-13T10:00:00Z');

/** The full destination table the owner asked for: every card, one expected link. */
const EXPECTED: Record<DashboardCardKey, string> = {
  total_employees: '/hr/employees',
  active_employees: '/hr/employees?status=active',
  inactive_employees: '/hr/former-employees',
  new_applicants: '/hr/employees?status=applicant&application_date_from=2026-09-13',
  practical: '/hr/employees?status=practical',
  waiting: '/hr/waiting',
  approved: '/hr/employees?status=approved',
  supervisor_pool: '/hr/supervisor-pool',
  active_replacements: '/hr/temporary-replacements?status=active',
  expired_replacements: '/hr/temporary-replacements?status=expired',
  office_staff: '/hr/office-staff',
  client_company_employees: '/hr/employees?client_company_only=true',
  open_workforce_requests: '/hr/workforce-requests?status=open',
  fulfilled_workforce_requests: '/hr/workforce-requests?status=fulfilled',
};

for (const [key, expected] of Object.entries(EXPECTED)) {
  test(`card ${key} opens ${expected}`, () => {
    assert.equal(dashboardCardTo(key as DashboardCardKey, NOW), expected);
  });
}

test('every card has a destination and none is left out of the table', () => {
  assert.equal(Object.keys(EXPECTED).length, 14);
  for (const key of Object.keys(EXPECTED)) {
    assert.ok(dashboardCardTo(key as DashboardCardKey, NOW).startsWith('/hr/'));
  }
});

test('the new-applicants window is the last 30 days in UTC and crosses month and year ends', () => {
  assert.equal(utcDateDaysAgo(new Date('2026-10-13T23:59:59Z'), 30), '2026-09-13');
  assert.equal(utcDateDaysAgo(new Date('2026-10-13T00:00:00Z'), 30), '2026-09-13');
  assert.equal(utcDateDaysAgo(new Date('2026-01-10T12:00:00Z'), 30), '2025-12-11');
  assert.equal(utcDateDaysAgo(new Date('2024-03-01T12:00:00Z'), 30), '2024-01-31'); // leap year
});

test('category tiles open the employee list for that category', () => {
  assert.equal(categoryTileTo(7), '/hr/employees?employee_category_id=7');
});

test('every link the employee list is sent can be read back by the list (no ignored filter)', () => {
  const read = (key: DashboardCardKey) => parseEmployeeListUrl(dashboardCardTo(key, NOW).split('?')[1] ?? '');

  assert.deepEqual(read('active_employees'), { status: 'active', employeeCategoryId: '', applicationDateFrom: '', applicationDateTo: '', clientCompanyOnly: false });
  assert.equal(read('practical').status, 'practical');
  assert.equal(read('approved').status, 'approved');
  assert.deepEqual(
    [read('new_applicants').status, read('new_applicants').applicationDateFrom],
    ['applicant', '2026-09-13'],
  );
  assert.equal(read('client_company_employees').clientCompanyOnly, true);
  assert.equal(read('client_company_employees').status, '');
  assert.equal(parseEmployeeListUrl(categoryTileTo(12).split('?')[1]).employeeCategoryId, '12');
});

test('total employees opens the list with no filter at all', () => {
  assert.deepEqual(parseEmployeeListUrl(''), { status: '', employeeCategoryId: '', applicationDateFrom: '', applicationDateTo: '', clientCompanyOnly: false });
});

test('unknown or malformed URL filters are dropped instead of being half applied', () => {
  const parsed = parseEmployeeListUrl('status=banana&employee_category_id=abc&application_date_from=2026-13-40&application_date_to=yesterday&client_company_only=1');
  assert.deepEqual(parsed, { status: '', employeeCategoryId: '', applicationDateFrom: '', applicationDateTo: '', clientCompanyOnly: false });
  assert.equal(parseEmployeeListUrl('application_date_from=2026-02-30').applicationDateFrom, '');
});

test('Workforce Requests: open means open + partially filled, fulfilled means fulfilled, unknown shows everything', () => {
  assert.deepEqual(parseWorkforceRequestStatuses('status=open'), ['open', 'partially_filled']);
  assert.deepEqual(parseWorkforceRequestStatuses('status=fulfilled'), ['fulfilled']);
  assert.deepEqual(parseWorkforceRequestStatuses('status=cancelled'), ['cancelled']);
  assert.equal(parseWorkforceRequestStatuses('status=nope'), null);
  assert.equal(parseWorkforceRequestStatuses(''), null);
  const open = parseWorkforceRequestStatuses(dashboardCardTo('open_workforce_requests', NOW).split('?')[1]);
  const fulfilled = parseWorkforceRequestStatuses(dashboardCardTo('fulfilled_workforce_requests', NOW).split('?')[1]);
  assert.deepEqual([open, fulfilled], [['open', 'partially_filled'], ['fulfilled']]);
});

test('Temporary Replacements: the Active Replacements card is read back as active', () => {
  assert.equal(parseReplacementStatus(dashboardCardTo('active_replacements', NOW).split('?')[1]), 'active');
  assert.equal(parseReplacementStatus('status=ended'), 'ended');
  assert.equal(parseReplacementStatus(dashboardCardTo('expired_replacements', NOW).split('?')[1]), 'expired');
  assert.equal(parseReplacementStatus('status=open'), null);
  assert.equal(parseReplacementStatus(''), null);
});

test('the Office Staff category tile is labelled apart from the Office Staff assignment card; other categories keep their names', () => {
  assert.equal(categoryTileLabel('Office Staff'), 'Office Staff (Category)');
  assert.equal(categoryTileLabel(' office staff '), 'Office Staff (Category)');
  for (const name of ['General Cleaning', 'Cooking', 'Waiter', 'Barista', 'Home Team', 'Supervisor']) assert.equal(categoryTileLabel(name), name);
  // The label never changes where the tile goes: it still opens the employee list for that category id.
  assert.equal(categoryTileTo(7), '/hr/employees?employee_category_id=7');
  // The assignment-count card keeps its own link, unchanged.
  assert.equal(dashboardCardTo('office_staff', new Date('2026-10-14T00:00:00Z')), '/hr/office-staff');
});
