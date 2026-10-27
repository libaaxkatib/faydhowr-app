import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { TYPE_CHANGE_BLOCKED_CODE, isBlockedTypeChange, isTypeChangeBlockedError } from './employmentTypeChange.ts';
import { translateKey } from '../i18n/messages/index.ts';

test('only Permanent to Temporary with an active assignment is blocked', () => {
  assert.equal(isBlockedTypeChange('permanent', 'temporary', 1), true);
  assert.equal(isBlockedTypeChange(null, 'temporary', 2), true, 'an unrecorded type counts as Permanent');
  assert.equal(isBlockedTypeChange('permanent', 'temporary', 0), false, 'nothing active: nothing to lose');
  assert.equal(isBlockedTypeChange('permanent', 'permanent', 3), false);
  assert.equal(isBlockedTypeChange('temporary', 'temporary', 3), false, 'not a change');
  assert.equal(isBlockedTypeChange('temporary', 'permanent', 3), false);
});

test('the owner-approved Somali warning is the catalogue text, word for word, and has an English equivalent', () => {
  const somali = translateKey('so', 'warning.employees.typeChangeBlocked');
  assert.match(somali, /^Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon\./);
  assert.match(somali, /Nidaamku si toos ah uma beddelayo shaqada hore\.$/);
  const english = translateKey('en', 'warning.employees.typeChangeBlocked');
  assert.match(english, /^This employee has a Permanent assignment that is still active\./);
  assert.notEqual(english, somali);
});

test('the Somali warning is identical in the catalogue and in the backend', () => {
  const backend = readFileSync(new URL('../../../backend/app/Support/Hr/EmploymentTypeChange.php', import.meta.url), 'utf8');
  assert.ok(backend.includes(translateKey('so', 'warning.employees.typeChangeBlocked')), 'EmploymentTypeChange::MESSAGE must equal the approved Somali text');
});

test('a refusal from the server is recognised by its code', () => {
  assert.equal(isTypeChangeBlockedError({ code: TYPE_CHANGE_BLOCKED_CODE, message: 'x' }), true);
  assert.equal(isTypeChangeBlockedError({ code: 'VALIDATION_ERROR', message: 'x' }), false);
  assert.equal(isTypeChangeBlockedError(null), false);
});
