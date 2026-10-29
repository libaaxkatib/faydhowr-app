import test from 'node:test';
import assert from 'node:assert/strict';

import { computeRange, parseIsoDate, parsePreset, toDateString, withPreset, withTypedDate } from './dateRangePreset.ts';

const WEDNESDAY = new Date(2026, 9, 28, 14, 30); // 28 October 2026, afternoon

test('each preset is worked out from local calendar days', () => {
  assert.deepEqual(computeRange('today', WEDNESDAY), { from: '2026-10-28', to: '2026-10-28' });
  assert.deepEqual(computeRange('this_week', WEDNESDAY), { from: '2026-10-25', to: '2026-10-28' }, 'the week starts on Sunday, as before');
  assert.deepEqual(computeRange('this_month', WEDNESDAY), { from: '2026-10-01', to: '2026-10-28' });
  assert.deepEqual(computeRange('previous_month', WEDNESDAY), { from: '2026-09-01', to: '2026-09-30' });
  assert.equal(computeRange('custom', WEDNESDAY), null);
});

test('January looks back to December of the year before', () => {
  assert.deepEqual(computeRange('previous_month', new Date(2027, 0, 15)), { from: '2026-12-01', to: '2026-12-31' });
});

test('late evening local time is still the same calendar day (no shift to the day before)', () => {
  assert.equal(toDateString(new Date(2026, 9, 28, 23, 59)), '2026-10-28');
  assert.equal(toDateString(new Date(2026, 9, 28, 0, 1)), '2026-10-28');
});

test('a bad preset or date in the address bar is ignored instead of breaking the report', () => {
  assert.equal(parsePreset('yesterday'), 'custom');
  assert.equal(parsePreset(null), 'custom');
  assert.equal(parsePreset('this_month'), 'this_month');
  assert.equal(parseIsoDate('2026-10-28'), '2026-10-28');
  for (const bad of ['2026-13-01', '2026-02-30', 'tomorrow', '28/10/2026', '', null]) assert.equal(parseIsoDate(bad as string | null), '', String(bad));
});

test('choosing a preset sets the range, goes back to page 1 and keeps the other filters', () => {
  const next = withPreset(new URLSearchParams('department=3&page=4&leave_type=sick'), 'this_month', WEDNESDAY);
  assert.deepEqual([next.get('preset'), next.get('from'), next.get('to'), next.get('page'), next.get('department'), next.get('leave_type')], ['this_month', '2026-10-01', '2026-10-28', null, '3', 'sick']);
});

test('choosing "custom" keeps the dates that were typed', () => {
  const next = withPreset(new URLSearchParams('preset=this_month&from=2026-10-01&to=2026-10-28'), 'custom', WEDNESDAY);
  assert.deepEqual([next.get('preset'), next.get('from'), next.get('to')], ['custom', '2026-10-01', '2026-10-28']);
});

test('typing a date turns the preset into custom, empties a cleared date and returns to page 1', () => {
  const typed = withTypedDate(new URLSearchParams('preset=this_month&from=2026-10-01&to=2026-10-28&page=3'), 'from', '2026-10-10');
  assert.deepEqual([typed.get('preset'), typed.get('from'), typed.get('to'), typed.get('page')], ['custom', '2026-10-10', '2026-10-28', null]);
  const cleared = withTypedDate(typed, 'to', '');
  assert.equal(cleared.get('to'), null);
  assert.equal(cleared.get('from'), '2026-10-10');
});
