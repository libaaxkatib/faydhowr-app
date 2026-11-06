import assert from 'node:assert/strict';
import test from 'node:test';

import { completionSummary, needTrainingTruncated, participantOptionLabel } from './trainingBatch.ts';

test('completing a batch moves everyone who is not ticked absent forward and keeps the absent in Need Training', () => {
  const people = [{ employee_id: 1 }, { employee_id: 2 }, { employee_id: 3 }];

  assert.deepEqual(completionSummary(people, undefined), { go: 3, absent: 0 });
  assert.deepEqual(completionSummary(people, new Set([2])), { go: 2, absent: 1 });
  assert.deepEqual(completionSummary(people, new Set([1, 2, 3])), { go: 0, absent: 3 });
  assert.deepEqual(completionSummary(people, new Set([99])), { go: 3, absent: 0 }, 'a ticked id that is no longer a participant is not counted');
});

test('two people with the same name are told apart by their number', () => {
  assert.equal(participantOptionLabel({ full_name: 'Sample Person', employee_number: 'EMP-000123' }), 'Sample Person (EMP-000123)');
});

test('the list says it is cut short when more people are in Need Training than are shown', () => {
  assert.equal(needTrainingTruncated(100, 140), true);
  assert.equal(needTrainingTruncated(40, 40), false);
});
