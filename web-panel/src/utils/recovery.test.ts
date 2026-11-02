import test from 'node:test';
import assert from 'node:assert/strict';

import { canApply, cleanParams, isReadyToPreview, monthBounds, newIdempotencyKey, signedMoney } from './recovery.ts';
import type { RecoveryPreview } from '../types/recovery.ts';

const preview = (over: Partial<RecoveryPreview> = {}): RecoveryPreview => ({
  workflow: 'reopen_assignment',
  assignment_id: 1,
  month: null,
  periods: [],
  blockers: [],
  warnings: [],
  changes: [],
  impact: null,
  verification: { ok: true, checks: {}, problems: [] },
  can_apply: true,
  token: 'token',
  token_valid_minutes: 15,
  ...over,
});

test('reopen and restore need no input; the date workflows need a valid month and something to change', () => {
  assert.equal(isReadyToPreview('reopen_assignment', {}), true);
  assert.equal(isReadyToPreview('restore_cancelled_assignment', {}), true);
  assert.equal(isReadyToPreview('correct_assignment_dates', {}), false);
  assert.equal(isReadyToPreview('correct_assignment_dates', { month: '2026-10' }), false);
  assert.equal(isReadyToPreview('correct_assignment_dates', { month: '2026-13', end_date: '2026-10-20' }), false);
  assert.equal(isReadyToPreview('correct_assignment_dates', { month: '2026-10', end_date: '2026-10-20' }), true);
  assert.equal(isReadyToPreview('correct_absence', { month: '2026-10', returned_on: '2026-10-22' }), false, 'which absence');
  assert.equal(isReadyToPreview('correct_absence', { month: '2026-10', absence_id: '4', returned_on: '2026-10-22' }), true);
});

test('only the fields a workflow uses are sent, and blanks are dropped', () => {
  assert.deepEqual(cleanParams('reopen_assignment', { month: '2026-10', end_date: '2026-10-20' }), {});
  assert.deepEqual(cleanParams('correct_assignment_dates', { month: '2026-10', start_date: ' ', end_date: '2026-10-20', returned_on: '2026-10-22' }), { month: '2026-10', end_date: '2026-10-20' });
});

test('Apply needs an allowed preview with a token, a reason of at least ten characters and the confirmation', () => {
  assert.equal(canApply(preview(), 'Ended by mistake', true), true);
  assert.equal(canApply(null, 'Ended by mistake', true), false);
  assert.equal(canApply(preview({ can_apply: false }), 'Ended by mistake', true), false);
  assert.equal(canApply(preview({ token: null }), 'Ended by mistake', true), false);
  assert.equal(canApply(preview(), 'too short', true), false);
  assert.equal(canApply(preview(), '          x', true), false, 'spaces do not count');
  assert.equal(canApply(preview(), 'Ended by mistake', false), false);
});

test('every preview gets its own idempotency key, within the server limit', () => {
  const keys = new Set(Array.from({ length: 50 }, () => newIdempotencyKey()));

  assert.equal(keys.size, 50);
  for (const key of keys) assert.ok(key.length >= 8 && key.length <= 80);
});

test('the date inputs are limited to the chosen month', () => {
  assert.deepEqual(monthBounds('2026-10'), { min: '2026-10-01', max: '2026-10-31' });
  assert.deepEqual(monthBounds('2026-02'), { min: '2026-02-01', max: '2026-02-28' });
  assert.deepEqual(monthBounds('2028-02'), { min: '2028-02-01', max: '2028-02-29' });
  assert.equal(monthBounds('2026-13'), null);
  assert.equal(monthBounds(''), null);
});

test('a difference is written with its sign', () => {
  const fmt = (c: number) => `$${(c / 100).toFixed(2)}`;

  assert.equal(signedMoney(11000, fmt), '+$110.00');
  assert.equal(signedMoney(-2000, fmt), '-$20.00');
  assert.equal(signedMoney(0, fmt), '$0.00');
});
