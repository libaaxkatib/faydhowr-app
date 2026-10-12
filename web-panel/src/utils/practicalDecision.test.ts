import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  buildPracticalDecisionPayload,
  isPracticalDecision,
  PRACTICAL_DECISIONS,
  submitPracticalDecision,
  type PracticalDecisionContext,
  type PracticalDecisionPayload,
} from './practicalDecision.ts';

/**
 * Run with: node --test src/utils/practicalDecision.test.ts
 * Regression tests for the Practical decision buttons: choosing Rejected must submit "rejected" on the
 * very first click and never a stale "approved" (the old code read the result from React state that had
 * not been updated yet).
 */

const context: PracticalDecisionContext = { assessmentDate: '2026-10-12', practicalBatchId: '7', notes: 'Needs more practice' };

/** A fake API that records every payload it receives, like a server would. */
function fakeApi(behaviour: (payload: PracticalDecisionPayload, call: number) => Promise<string> = async () => 'ok') {
  const received: PracticalDecisionPayload[] = [];
  const send = (payload: PracticalDecisionPayload) => {
    received.push(payload);
    return behaviour(payload, received.length);
  };
  return { send, received };
}

test('Rejected submits "rejected" on the first click, never the default "approved"', async () => {
  const api = fakeApi();
  await submitPracticalDecision(api.send, 'rejected', context);
  assert.equal(api.received.length, 1);
  assert.equal(api.received[0].result, 'rejected');
});

test('Approved submits "approved"', async () => {
  const api = fakeApi();
  await submitPracticalDecision(api.send, 'approved', context);
  assert.equal(api.received[0].result, 'approved');
});

test('Ku Celis Practical submits "ku_celis_practical"', async () => {
  const api = fakeApi();
  await submitPracticalDecision(api.send, 'ku_celis_practical', context);
  assert.equal(api.received[0].result, 'ku_celis_practical');
});

test('each click submits what that click selected, in any order (no stale state between clicks)', async () => {
  const api = fakeApi();
  await submitPracticalDecision(api.send, 'rejected', context);
  await submitPracticalDecision(api.send, 'approved', context);
  await submitPracticalDecision(api.send, 'rejected', context);
  assert.deepEqual(
    api.received.map((p) => p.result),
    ['rejected', 'approved', 'rejected'],
  );
});

test('the payload carries date, batch (as a number) and notes unchanged', () => {
  assert.deepEqual(buildPracticalDecisionPayload('rejected', context), {
    assessment_date: '2026-10-12',
    result: 'rejected',
    practical_batch_id: 7,
    notes: 'Needs more practice',
  });
});

test('an empty batch and empty notes are sent as null', () => {
  const payload = buildPracticalDecisionPayload('approved', { assessmentDate: '2026-10-12', practicalBatchId: '', notes: '' });
  assert.equal(payload.practical_batch_id, null);
  assert.equal(payload.notes, null);
});

test('a validation error (422) is re-thrown untouched and the next click still submits its own decision', async () => {
  const api = fakeApi(async (_payload, call) => {
    if (call === 1) throw new Error('The given data was invalid.');
    return 'ok';
  });

  await assert.rejects(() => submitPracticalDecision(api.send, 'rejected', context), /The given data was invalid\./);
  assert.equal(api.received[0].result, 'rejected');

  // The failed attempt must not leave a remembered/stale choice behind.
  await submitPracticalDecision(api.send, 'approved', context);
  assert.equal(api.received.length, 2);
  assert.equal(api.received[1].result, 'approved');
  // The caller's context (the user's notes and batch) is not consumed by a failure.
  assert.equal(context.notes, 'Needs more practice');
  assert.equal(context.practicalBatchId, '7');
});

test('an unknown or missing decision is refused before anything is sent', async () => {
  const api = fakeApi();
  // @ts-expect-error - deliberately invalid values
  assert.throws(() => buildPracticalDecisionPayload('pass', context), /Unknown practical decision/);
  // @ts-expect-error - deliberately invalid values
  assert.throws(() => submitPracticalDecision(api.send, undefined, context), /Unknown practical decision/);
  assert.equal(api.received.length, 0);
});

test('only the three canonical decisions are valid (legacy pass/fail/pending are not)', () => {
  assert.deepEqual([...PRACTICAL_DECISIONS].sort(), ['approved', 'ku_celis_practical', 'rejected']);
  for (const legacy of ['pass', 'fail', 'pending', '', null, undefined]) {
    assert.equal(isPracticalDecision(legacy), false);
  }
});

test('the detail page passes the clicked decision to the mutation and no longer keeps a stale decision state', () => {
  const source = readFileSync(new URL('../features/hrm/EmployeeDetailPage.tsx', import.meta.url), 'utf8');
  assert.equal(source.includes('setDecisionResult'), false, 'decision must not be stored in component state');
  assert.equal(source.includes('decisionResult'), false, 'decision must not be read from component state');
  assert.equal(/decisionMutation\.mutate\(\s*\)/.test(source), false, 'mutate() must always receive the clicked decision');
  assert.equal(source.includes('decisionMutation.mutate(option.value)'), true);
  assert.equal(source.includes('submitPracticalDecision('), true);
});
