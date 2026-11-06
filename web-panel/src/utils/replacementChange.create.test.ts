import assert from 'node:assert/strict';
import test from 'node:test';

import { canCreateCover, createNeedsReason, isCorrectionReasonAsked, isStaleCoverError, type CreateCoverState } from './replacementChange.ts';

const ready: CreateCoverState = { hasFields: true, blocked: false, reachesClosedPayroll: false, reasonAsked: false, roleMayNotCorrect: false, reason: '', checking: false };

test('a cover that touches only open months needs no reason (UG-3)', () => {
  assert.equal(createNeedsReason(ready), false);
  assert.equal(canCreateCover(ready), true);
});

test('a back-dated cover that reaches closed payroll needs a written reason of at least 10 characters (UG-3)', () => {
  const closed = { ...ready, reachesClosedPayroll: true };

  assert.equal(createNeedsReason(closed), true);
  assert.equal(canCreateCover(closed), false);
  assert.equal(canCreateCover({ ...closed, reason: 'Too short' }), false);
  assert.equal(canCreateCover({ ...closed, reason: '   Too short   ' }), false);
  assert.equal(canCreateCover({ ...closed, reason: 'Cover was not recorded in September' }), true);
});

test('a role that may not correct closed payroll cannot create such a cover and is not asked for a reason', () => {
  const refused = { ...ready, reachesClosedPayroll: true, roleMayNotCorrect: true, reason: 'Cover was not recorded in September' };

  assert.equal(createNeedsReason(refused), false);
  assert.equal(canCreateCover(refused), false);
});

test('when the server asked for the reason although the preview did not say so, the reason box appears and is required', () => {
  const asked = { ...ready, reasonAsked: true };

  assert.equal(createNeedsReason(asked), true);
  assert.equal(canCreateCover(asked), false);
  assert.equal(canCreateCover({ ...asked, reason: 'A written reason for it' }), true);
});

test('Create waits for the choices, the preview and any blocking conflict', () => {
  assert.equal(canCreateCover({ ...ready, hasFields: false }), false);
  assert.equal(canCreateCover({ ...ready, blocked: true }), false);
  assert.equal(canCreateCover({ ...ready, checking: true }), false);
});

test('the two server answers the screens react to are recognised by their code', () => {
  assert.equal(isStaleCoverError({ code: 'TEMPORARY_REPLACEMENT_CHANGED' }), true);
  assert.equal(isStaleCoverError({ code: 'VALIDATION_ERROR' }), false);
  assert.equal(isStaleCoverError(null), false);
  assert.equal(isCorrectionReasonAsked({ code: 'CORRECTION_REASON_REQUIRED' }), true);
  assert.equal(isCorrectionReasonAsked(new Error('x')), false);
});

test('an Edit correction that reaches closed payroll needs its reason once; otherwise the ordinary reason', async () => {
  const { editChangeReason, editReasonOk } = await import('./replacementChange.ts');

  assert.equal(editReasonOk(true, '', 'The cover stopped on 25 September'), true, 'the closed-payroll reason alone is enough');
  assert.equal(editChangeReason(true, 'ignored text here', ' The cover stopped on 25 September '), 'The cover stopped on 25 September');
  assert.equal(editReasonOk(true, 'A long enough ordinary reason', 'short'), false, 'the ordinary reason does not stand in for it');
  assert.equal(editReasonOk(false, 'A long enough ordinary reason', ''), true);
  assert.equal(editReasonOk(false, 'short', 'The cover stopped on 25 September'), false);
});

test('every list calls a cover by its lifecycle, so an expired open cover is never shown as Active (B-12)', async () => {
  const { lifecycleTone, lifecycleWord } = await import('./replacementChange.ts');

  assert.equal(lifecycleWord({ lifecycle: 'expired', status: 'active' }), 'expired');
  assert.equal(lifecycleWord({ lifecycle: 'running', status: 'active' }), 'active');
  assert.equal(lifecycleWord({ lifecycle: 'scheduled', status: 'active' }), 'scheduled');
  assert.equal(lifecycleWord({ lifecycle: 'ended', status: 'ended' }), 'ended');
  assert.equal(lifecycleWord({ status: 'ended' }), 'ended', 'an older answer without a lifecycle falls back to the status');
  assert.equal(lifecycleWord({ status: 'active' }), 'active');
  assert.equal(lifecycleTone({ lifecycle: 'expired', status: 'active' }), 'danger');
  assert.equal(lifecycleTone({ lifecycle: 'running', status: 'active' }), 'warning');
  assert.equal(lifecycleTone({ lifecycle: 'scheduled', status: 'active' }), 'info');
  assert.equal(lifecycleTone({ lifecycle: 'ended', status: 'ended' }), 'neutral');
});
