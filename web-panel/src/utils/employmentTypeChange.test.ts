import test from 'node:test';
import assert from 'node:assert/strict';

import { TYPE_CHANGE_BLOCKED_CODE, TYPE_CHANGE_BLOCKED_SOMALI, isBlockedTypeChange, typeChangeBlockFromError } from './employmentTypeChange.ts';

test('only Permanent to Temporary with an active assignment is blocked', () => {
  assert.equal(isBlockedTypeChange('permanent', 'temporary', 1), true);
  assert.equal(isBlockedTypeChange(null, 'temporary', 2), true, 'an unrecorded type counts as Permanent');
  assert.equal(isBlockedTypeChange('permanent', 'temporary', 0), false, 'nothing active: nothing to lose');
  assert.equal(isBlockedTypeChange('permanent', 'permanent', 3), false);
  assert.equal(isBlockedTypeChange('temporary', 'temporary', 3), false, 'not a change');
  assert.equal(isBlockedTypeChange('temporary', 'permanent', 3), false);
});

test('the Somali warning is the approved text', () => {
  assert.match(TYPE_CHANGE_BLOCKED_SOMALI, /^Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon\./);
  assert.match(TYPE_CHANGE_BLOCKED_SOMALI, /Nidaamku si toos ah uma beddelayo shaqada hore\.$/);
});

test('a refusal from the server is recognised by its code and keeps the server wording', () => {
  assert.equal(typeChangeBlockFromError({ code: TYPE_CHANGE_BLOCKED_CODE, message: 'Shaqaalahaan ...' }), 'Shaqaalahaan ...');
  assert.equal(typeChangeBlockFromError({ code: TYPE_CHANGE_BLOCKED_CODE }), TYPE_CHANGE_BLOCKED_SOMALI);
  assert.equal(typeChangeBlockFromError({ code: 'VALIDATION_ERROR', message: 'x' }), null);
  assert.equal(typeChangeBlockFromError(null), null);
});
