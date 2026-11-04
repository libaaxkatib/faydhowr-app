import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { defaultPayrollMonth, previousMonth } from './payrollMonth.ts';

test('the default payroll month is always the previous calendar month (the owner examples)', () => {
  assert.equal(previousMonth(new Date(2026, 10, 4)), '2026-10', 'November 2026 -> October 2026');
  assert.equal(previousMonth(new Date(2026, 11, 15)), '2026-11', 'December 2026 -> November 2026');
  assert.equal(previousMonth(new Date(2027, 0, 9)), '2026-12', 'January 2027 -> December 2026');
});

test('month and year boundaries and short months never skip or repeat a month', () => {
  assert.equal(previousMonth(new Date(2026, 2, 31)), '2026-02', '31 March -> February');
  assert.equal(previousMonth(new Date(2026, 4, 31)), '2026-04', '31 May -> April (30 days)');
  assert.equal(previousMonth(new Date(2028, 2, 30)), '2028-02', 'leap year');
  assert.equal(previousMonth(new Date(2026, 0, 1, 0, 30)), '2025-12', 'just after midnight on 1 January');
  assert.equal(previousMonth(new Date(2026, 11, 31, 23, 59)), '2026-11', 'the last minute of the year');
});

test('the screens use this one default, and the person can still choose another month', () => {
  assert.equal(defaultPayrollMonth, previousMonth);

  const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');
  for (const page of ['../features/hrm/PayrollRunsPage.tsx', '../features/hrm/PayrollRollupPage.tsx', '../features/hrm/CompanyEmployeesPage.tsx', '../features/hrm/EmployeeDetailPage.tsx']) {
    assert.match(read(page), /defaultPayrollMonth\(\)/, `${page} opens on the previous month`);
  }
  // The month is still a normal input of the page: picking another month is not blocked.
  assert.match(read('../features/hrm/PayrollRunsPage.tsx'), /onChange=\{\(e\) => \{[\s\S]{0,160}setPeriod\(e\.target\.value\)/);
});

test('the Temporary Replacements page is NOT part of this change: it still opens on the CURRENT month (owner decision 2026-11-04)', () => {
  const page = readFileSync(new URL('../features/hrm/TemporaryReplacementsPage.tsx', import.meta.url), 'utf8');

  assert.match(page, /useState\(localMonth\(\)\)/);
  assert.ok(!/defaultPayrollMonth|previousMonth/.test(page), 'it does not use the previous-month default');
});

test('the payment list and the Excel downloads use the month the person selected', () => {
  const page = readFileSync(new URL('../features/hrm/PayrollRunsPage.tsx', import.meta.url), 'utf8');
  const api = readFileSync(new URL('../api/hr.ts', import.meta.url), 'utf8');

  assert.match(page, /hrApi\.payrollRuns\.downloadPaymentList\(period, listScope, listSelected, previewed\)/, 'the payment list follows the selected month');
  assert.match(page, /hrApi\.payrollRuns\.exportExcel\(/, 'the payroll workbook is exported for the shown month');
  assert.match(api, /exportExcel: \(run: Pick<PayrollRun, 'id' \| 'period'>\)/, 'the workbook is named and requested by the run\'s own period');
});
