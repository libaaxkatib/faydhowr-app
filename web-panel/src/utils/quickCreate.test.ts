import test from 'node:test';
import assert from 'node:assert/strict';

import { tidyName, validateNewName } from './quickCreate.ts';

test('names are tidied before they are sent', () => {
  assert.equal(tidyName('  Senior   Cleaner '), 'Senior Cleaner');
});

test('an empty, too long or already existing name is caught before the request', () => {
  const existing = [{ name: 'Cleaner' }, { name: 'Night Guard' }];
  assert.match(validateNewName('   ', existing, 'position') ?? '', /Enter the position name/);
  assert.match(validateNewName('x'.repeat(101), existing, 'position') ?? '', /too long/);
  assert.match(validateNewName('  night   GUARD ', existing, 'position') ?? '', /already exists/);
  assert.equal(validateNewName('Driver', existing, 'position'), null);
});
