import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { translateKey } from '../i18n/messages/index.ts';
import { stageMoveReasonError, STAGE_MOVE_TARGETS } from './registrationWorkflow.ts';

test('the stage-move reason message and the target labels follow the active language', () => {
  try {
    setActiveLanguage('so');
    assert.equal(stageMoveReasonError('short'), 'Qor sabab ugu yaraan 10 xaraf ah.');
    assert.equal(stageMoveReasonError('x'.repeat(501)), 'Sababtu ma dheeraan karto 500 xaraf.');
    assert.deepEqual(
      STAGE_MOVE_TARGETS.map((target) => translateKey('so', target.labelKey)),
      ['Damiin Loo Baahan Yahay', 'Hishiiska Shaqaalaha', 'Uniformka laga sugayo', 'Ubaahan Tababar', 'Ubaahan Baraatiko', 'KU celis Baraatiko', 'Liiska Sugitaanka'],
    );
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(stageMoveReasonError('short'), 'Write a reason of at least 10 characters.');
  assert.deepEqual(
    STAGE_MOVE_TARGETS.map((target) => translateKey('en', target.labelKey)),
    STAGE_MOVE_TARGETS.map((target) => target.label),
  );
});
