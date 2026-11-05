import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

import { defaultPayrollMonth, previousMonth } from './payrollMonth.ts';
import { payrollRelease1b } from '../i18n/messages/payrollRelease1b.ts';

// Owner decision O-2 (label only): the dashboard says which month each group counts. Nothing about how the months are chosen changed.
const dashboard = readFileSync(new URL('../features/hrm/HrDashboardPage.tsx', import.meta.url), 'utf8');

test('the financial cards are labelled with the calendar month and the payroll group with its payroll month', () => {
  assert.match(dashboard, /data-testid="dashboard-calendar-month"[^>]*>\{t\('option\.hr\.calendarMonthLabel', \{ month: monthName\(data\.calendar_month\) \}\)\}/);
  assert.match(dashboard, /data-testid="dashboard-payroll-month-label"[^>]*>\{t\('option\.hr\.payrollMonthLabel', \{ month: monthName\(payrollMonth \?\? data\.payroll\.period\) \}\)\}/);
});

test('the labels are the two proposed messages with their {month} placeholder in both languages', () => {
  const calendar = payrollRelease1b['option.hr.calendarMonthLabel'];
  const payroll = payrollRelease1b['option.hr.payrollMonthLabel'];

  assert.equal(calendar.en, 'Calendar month: {month}');
  assert.equal(payroll.en, 'Payroll month: {month}');
  assert.ok(calendar.so.includes('{month}') && payroll.so.includes('{month}'));
  assert.equal(calendar.review, 'proposed');
  assert.equal(payroll.review, 'proposed');
});

test('the month selection is unchanged: the payroll group still starts on the previous month and the picker still sends payroll_period', () => {
  assert.equal(defaultPayrollMonth, previousMonth);
  assert.equal(previousMonth(new Date(2026, 10, 5)), '2026-10');
  assert.equal(previousMonth(new Date(2027, 0, 15)), '2026-12');
  assert.match(dashboard, /const \[payrollMonth, setPayrollMonth\] = useState<string \| null>\(null\);/);
  assert.match(dashboard, /queryFn: \(\) => hrApi\.dashboard\(payrollMonth \?\? undefined\)/);
  assert.match(dashboard, /value=\{payrollMonth \?\? data\.payroll\.period\}/);
});
