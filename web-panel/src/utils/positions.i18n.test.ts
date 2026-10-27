import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { decideCreatePosition, positionNameError } from './positions.ts';

test('the position name messages are English by default and Somali on a Somali HR screen', () => {
  assert.equal(positionNameError(''), 'Enter the position name.');
  assert.equal(positionNameError('x'.repeat(151)), 'The position name is too long (150 characters at most).');
  try {
    setActiveLanguage('so');
    assert.equal(positionNameError(''), CATALOGUE['validation.company.enterPositionName'].so);
    assert.deepEqual(decideCreatePosition([], ' '), { kind: 'invalid', message: CATALOGUE['validation.company.enterPositionName'].so });
    assert.equal(positionNameError('HR Officer'), null);
  } finally {
    setActiveLanguage('en');
  }
});
