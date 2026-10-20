import test from 'node:test';
import assert from 'node:assert/strict';

import {
  advanceBlockedByWorkflow,
  canOfferStageMove,
  canSubmitStageMove,
  currentStageValue,
  normalizeStageReason,
  stageMoveOptions,
  stageMoveReasonError,
  STAGE_MOVE_MAX_LENGTH,
  STAGE_MOVE_TARGETS,
} from './registrationWorkflow.ts';

/** Run with: node --test src/utils/registrationWorkflow.test.ts */

test('the stage move is offered only to a Super Admin or HR Manager, only for existing records that are not Active or Inactive', () => {
  assert.equal(canOfferStageMove('super_admin', 'existing', 'waiting'), true);
  assert.equal(canOfferStageMove('hr_manager', 'existing', 'applicant'), true);
  for (const role of ['marketing_manager', 'hr_employee', 'mobile_app_manager', 'sales', undefined, null]) {
    assert.equal(canOfferStageMove(role, 'existing', 'waiting'), false, `${String(role)} must not see it`);
  }
  assert.equal(canOfferStageMove('hr_manager', 'new', 'applicant'), false, 'a new registration follows the workflow');
  assert.equal(canOfferStageMove('hr_manager', 'office_staff', 'applicant'), false);
  assert.equal(canOfferStageMove('hr_manager', 'existing', 'active'), false);
  assert.equal(canOfferStageMove('hr_manager', 'existing', 'inactive'), false);
});

test('Active is never a target, and the stage the person is in is not offered again', () => {
  assert.equal(STAGE_MOVE_TARGETS.some((t) => (t.value as string) === 'active'), false);
  assert.equal(STAGE_MOVE_TARGETS.some((t) => (t.value as string) === 'rejected'), false);
  assert.equal(STAGE_MOVE_TARGETS.length, 7);
  assert.equal(currentStageValue('waiting', null), 'waiting');
  assert.equal(currentStageValue('applicant', 'need_training'), 'need_training');
  assert.equal(stageMoveOptions('waiting', null).some((t) => t.value === 'waiting'), false);
  assert.equal(stageMoveOptions('applicant', 'need_training').some((t) => t.value === 'need_training'), false);
  assert.equal(stageMoveOptions('applicant', 'need_training').length, 6);
});

test('a reason shorter than ten characters is refused, whitespace does not count', () => {
  assert.match(stageMoveReasonError('') ?? '', /at least 10/);
  assert.match(stageMoveReasonError('   short   ') ?? '', /at least 10/);
  assert.equal(stageMoveReasonError('Owner reviewed the file.'), null);
  assert.match(stageMoveReasonError('x'.repeat(STAGE_MOVE_MAX_LENGTH + 1)) ?? '', /longer than 500/);
  assert.equal(normalizeStageReason('  a   b\n c '), 'a b c');
});

test('the move can be submitted only with a valid different stage and a real reason', () => {
  assert.equal(canSubmitStageMove('waiting', null, 'need_training', 'Training is still outstanding'), true);
  assert.equal(canSubmitStageMove('waiting', null, 'waiting', 'Training is still outstanding'), false);
  assert.equal(canSubmitStageMove('waiting', null, 'active', 'Training is still outstanding'), false);
  assert.equal(canSubmitStageMove('waiting', null, 'need_training', 'short'), false);
  assert.equal(canSubmitStageMove('waiting', null, '', 'Training is still outstanding'), false);
});

test('advancing a new registration to Waiting / Approved / Active is blocked until the steps are done or an override is recorded', () => {
  const incomplete = { applies: true, complete: false, override_recorded: false, missing: ['Guarantor verified'] };
  assert.equal(advanceBlockedByWorkflow(incomplete, 'waiting'), true);
  assert.equal(advanceBlockedByWorkflow(incomplete, 'active'), true);
  assert.equal(advanceBlockedByWorkflow(incomplete, 'practical'), false, 'earlier statuses are not held back');
  assert.equal(advanceBlockedByWorkflow({ ...incomplete, complete: true, missing: [] }, 'active'), false);
  assert.equal(advanceBlockedByWorkflow({ ...incomplete, override_recorded: true }, 'active'), false);
  assert.equal(advanceBlockedByWorkflow({ ...incomplete, applies: false }, 'active'), false, 'existing records are never held back');
  assert.equal(advanceBlockedByWorkflow(undefined, 'active'), false);
});
