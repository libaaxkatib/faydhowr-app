import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { validateNewName } from './quickCreate.ts';

test('the new-name messages put the noun in the screen language and keep an unknown noun as given', () => {
  assert.equal(validateNewName('', [], 'position'), 'Enter the position name.');
  assert.equal(validateNewName('Driver', [{ name: 'driver' }], 'category'), 'A category called "Driver" already exists. Pick it from the list instead.');
  try {
    setActiveLanguage('so');
    assert.equal(validateNewName('', [], 'position'), 'Geli magaca jagada.');
    assert.equal(validateNewName('x'.repeat(101), [], 'category'), 'Magaca qaybta aad buu u dheer yahay (ugu badnaan 100 xaraf).');
    assert.equal(validateNewName('Driver', [{ name: 'driver' }], 'position'), 'Horey ayaa u jira jagada la yiraahdo "Driver". Ka dooro liiska.');
    assert.equal(validateNewName('', [], 'team'), 'Geli magaca team.');
    assert.equal(validateNewName('New', [], 'position'), null);
  } finally {
    setActiveLanguage('en');
  }
});
