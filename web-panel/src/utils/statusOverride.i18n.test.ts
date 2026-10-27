import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { overrideReasonError } from './statusOverride.ts';

test('the override reason messages follow the active language', () => {
  try {
    setActiveLanguage('so');
    assert.equal(overrideReasonError('short'), 'Qor sabab ugu yaraan 10 xaraf ah.');
    assert.equal(overrideReasonError('x'.repeat(501)), 'Sababtu ma dheeraan karto 500 xaraf.');
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(overrideReasonError('x'.repeat(501)), 'The reason may not be longer than 500 characters.');
});
