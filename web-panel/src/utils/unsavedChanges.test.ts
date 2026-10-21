import test from 'node:test';
import assert from 'node:assert/strict';

import { decideClose, isDirty } from './unsavedChanges.ts';

test('a form only asks before closing when it is protected and was actually changed', () => {
  assert.equal(decideClose({ protectedForm: true, touched: true }), 'ask');
  assert.equal(decideClose({ protectedForm: true, touched: false }), 'close');
  assert.equal(decideClose({ protectedForm: false, touched: true }), 'close');
  assert.equal(decideClose({ protectedForm: false, touched: false }), 'close');
});

test('dirty detection compares values, ignores key order and notices a reverted edit as clean', () => {
  const start = { name: 'Alpha', tags: ['a', 'b'], nested: { x: 1, y: 2 } };
  assert.equal(isDirty(start, { nested: { y: 2, x: 1 }, tags: ['a', 'b'], name: 'Alpha' }), false);
  assert.equal(isDirty(start, { ...start, name: 'Alphas' }), true);
  assert.equal(isDirty(start, { ...start, tags: ['b', 'a'] }), true, 'order inside a list is a change');
  const edited = { ...start, name: 'Changed' };
  assert.equal(isDirty(start, { ...edited, name: 'Alpha' }), false);
});
