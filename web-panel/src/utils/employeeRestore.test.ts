import test from 'node:test';
import assert from 'node:assert/strict';

import { MIN_DUPLICATE_REASON_LENGTH, restoreFailureKind, restoreIsReady, restoreLanding, restorePayload } from './employeeRestore.ts';

test('a restored person reappears in the list that matches the status they already have', () => {
  assert.deepEqual(restoreLanding({ status: 'waiting', pipeline_stage: null }), { label: 'Waiting', path: '/hr/waiting' });
  assert.deepEqual(restoreLanding({ status: 'inactive', pipeline_stage: null }), { label: 'Former Employees', path: '/hr/former-employees' });
  assert.deepEqual(restoreLanding({ status: 'applicant', pipeline_stage: 'need_training' }), { label: 'Need Training', path: '/hr/pipeline/training' });
  assert.deepEqual(restoreLanding({ status: 'applicant', pipeline_stage: 'practical_repeat' }), { label: 'Practical Repeat', path: '/hr/pipeline/practical-repeat' });
  // An applicant with no stage, an active person, or an unknown stage are all simply in the Employees list.
  for (const target of [
    { status: 'applicant', pipeline_stage: null },
    { status: 'active', pipeline_stage: null },
    { status: 'applicant', pipeline_stage: 'a_stage_that_does_not_exist' },
  ]) {
    assert.deepEqual(restoreLanding(target), { label: 'Employees', path: '/hr/employees' });
  }
});

test('every pipeline stage has its own landing queue', () => {
  const stages = ['damiin_needed', 'contract_pending', 'uniform_pending', 'need_training', 'need_practical', 'practical_repeat', 'rejected'];
  const paths = stages.map((stage) => restoreLanding({ status: 'applicant', pipeline_stage: stage }).path);
  assert.equal(new Set(paths).size, stages.length);
  assert.ok(paths.every((path) => path.startsWith('/hr/pipeline/')));
});

test('the server refusals are told apart', () => {
  assert.equal(restoreFailureKind('EMPLOYEE_NOT_ARCHIVED'), 'not_archived');
  assert.equal(restoreFailureKind('DUPLICATE_EMPLOYEE_PHONE'), 'duplicate_phone');
  assert.equal(restoreFailureKind('FORBIDDEN'), 'other');
  assert.equal(restoreFailureKind(undefined), 'other');
});

test('Restore waits for the confirmation and a written reason only when a current employee shares the phone number', () => {
  assert.equal(restoreIsReady(false, false, ''), true);
  assert.equal(restoreIsReady(true, false, 'A different person with a shared phone'), false, 'not confirmed');
  assert.equal(restoreIsReady(true, true, 'too short'), false, 'reason too short');
  assert.equal(restoreIsReady(true, true, '   '.repeat(10)), false, 'blanks are not a reason');
  assert.equal(restoreIsReady(true, true, 'x'.repeat(MIN_DUPLICATE_REASON_LENGTH)), true);
});

test('the request carries only what was filled in and never a status', () => {
  assert.deepEqual(restorePayload('', null), {});
  assert.deepEqual(restorePayload('  Returned after review  ', null), { reason: 'Returned after review' });
  assert.deepEqual(restorePayload('', { confirmed: false, reason: 'ignored while unconfirmed' }), {});
  assert.deepEqual(restorePayload('Note', { confirmed: true, reason: '  A different person who shares a phone  ' }), {
    reason: 'Note',
    confirm_duplicate: true,
    duplicate_reason: 'A different person who shares a phone',
  });
  for (const body of [restorePayload('x', { confirmed: true, reason: 'a reason that is long enough' }), restorePayload('', null)]) {
    assert.ok(!('status' in body) && !('pipeline_stage' in body));
  }
});
