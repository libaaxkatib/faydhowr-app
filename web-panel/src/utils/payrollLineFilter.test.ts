import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { filterPayrollLines, normaliseSearch } from './payrollLineFilter.ts';

const line = (employee_name: string, employee_number: string, company_name: string | null, workplace_name: string | null, payment_status: string) => ({ employee_name, employee_number, company_name, workplace_name, payment_status });
const LINES = [
  line('Amina Warsame', 'EMP-100', 'Alpha Hospital', 'Maternity Wing', 'unpaid'),
  line('Bashir Ali', 'EMP-101', 'Alpha Hospital', null, 'paid'),
  line('Caasha Hassan', 'EMP-102', 'Beta School', 'Main Gate', 'unpaid'),
];

test('no search and no payment filter keeps every line, in order', () => {
  assert.deepEqual(filterPayrollLines(LINES, '', ''), LINES);
  assert.deepEqual(filterPayrollLines(LINES, '   ', ''), LINES);
});

test('the search finds an employee by name or number, and a company or workplace, in any case', () => {
  assert.deepEqual(filterPayrollLines(LINES, 'WARSAME', '').map((l) => l.employee_number), ['EMP-100']);
  assert.deepEqual(filterPayrollLines(LINES, 'emp-102', '').map((l) => l.employee_name), ['Caasha Hassan']);
  assert.deepEqual(filterPayrollLines(LINES, 'alpha', '').map((l) => l.employee_number), ['EMP-100', 'EMP-101']);
  assert.deepEqual(filterPayrollLines(LINES, 'main gate', '').map((l) => l.employee_number), ['EMP-102']);
});

test('every word typed must match, so a longer search narrows the list', () => {
  assert.deepEqual(filterPayrollLines(LINES, 'alpha ali', '').map((l) => l.employee_name), ['Bashir Ali']);
  assert.deepEqual(filterPayrollLines(LINES, 'alpha school', ''), []);
});

test('the payment filter and the search apply together', () => {
  assert.deepEqual(filterPayrollLines(LINES, '', 'paid').map((l) => l.employee_number), ['EMP-101']);
  assert.deepEqual(filterPayrollLines(LINES, '', 'unpaid').map((l) => l.employee_number), ['EMP-100', 'EMP-102']);
  assert.deepEqual(filterPayrollLines(LINES, 'alpha', 'unpaid').map((l) => l.employee_number), ['EMP-100']);
  assert.deepEqual(filterPayrollLines(LINES, 'alpha', 'paid').map((l) => l.employee_number), ['EMP-101']);
});

test('a line with missing company or workplace is still found by its other fields', () => {
  assert.deepEqual(filterPayrollLines(LINES, 'bashir', '').length, 1);
  assert.equal(normaliseSearch('  Ali   HASSAN '), 'ali hassan');
});

test('filtering never touches the lines or their amounts', () => {
  const before = JSON.stringify(LINES);
  filterPayrollLines(LINES, 'alpha', 'paid');
  assert.equal(JSON.stringify(LINES), before);
});

test('the payroll lines table uses it, says that the totals are for the whole month, and keeps the table scroll region', () => {
  const page = readFileSync(new URL('../features/hrm/PayrollRunsPage.tsx', import.meta.url), 'utf8');
  assert.ok(page.includes('filterPayrollLines(allItems, search, payment)'));
  assert.ok(page.includes("t('warning.payroll.totalsWholeMonth')"));
  assert.ok(page.includes('<NoMatchState onReset={reset} />'));
  assert.ok(page.includes('role="region" tabIndex={0} aria-label={t(\'option.common.dataTable\')}'));
});
