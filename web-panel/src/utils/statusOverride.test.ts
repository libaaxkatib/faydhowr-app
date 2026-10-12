import test from 'node:test';
import assert from 'node:assert/strict';

import { canSubmitOverride, normalizeReason, overrideReasonError, overrideTargets, OVERRIDE_MAX_LENGTH } from './statusOverride.ts';

/** Run with: node --test src/utils/statusOverride.test.ts */

test('a reason shorter than ten characters is refused, whitespace does not count', () => {
  assert.match(overrideReasonError('') ?? '', /at least 10/);
  assert.match(overrideReasonError('   short   ') ?? '', /at least 10/);
  assert.match(overrideReasonError('a    b    c   d') ?? '', /at least 10/, 'runs of spaces count once');
  assert.equal(overrideReasonError('Completed all required processes.'), null);
  assert.equal(overrideReasonError('exactly 10'), null);
});

test('a reason over 500 characters is refused', () => {
  assert.equal(overrideReasonError('x'.repeat(OVERRIDE_MAX_LENGTH)), null);
  assert.match(overrideReasonError('x'.repeat(OVERRIDE_MAX_LENGTH + 1)) ?? '', /longer than 500/);
});

test('the reason is cleaned up the way the server does', () => {
  assert.equal(normalizeReason('  Completed   all\n required  processes. '), 'Completed all required processes.');
});

test('the current status is never offered as a target', () => {
  assert.ok(!overrideTargets('active').includes('active'));
  assert.equal(overrideTargets('active').length, 6);
  assert.ok(overrideTargets('applicant').includes('active'));
});

test('submitting needs a different status and an acceptable reason', () => {
  assert.equal(canSubmitOverride('applicant', 'active', 'Completed all required processes.'), true);
  assert.equal(canSubmitOverride('applicant', 'applicant', 'Completed all required processes.'), false);
  assert.equal(canSubmitOverride('applicant', 'nonsense', 'Completed all required processes.'), false);
  assert.equal(canSubmitOverride('applicant', 'active', 'too short'), false);
});
