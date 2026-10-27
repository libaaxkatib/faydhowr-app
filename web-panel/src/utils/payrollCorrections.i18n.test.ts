import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { correctionStatusLabel, validateSettlementAmount, validateWriteOffReason } from './payrollCorrections.ts';

test('the correction validation messages and status labels are Somali while an HR screen shows Somali, and unchanged English otherwise', () => {
  assert.equal(validateWriteOffReason('no'), 'Give the reason for writing this debt off (at least 5 characters).');
  assert.equal(validateSettlementAmount('0', 10000), 'Enter an amount greater than zero.');
  assert.equal(validateSettlementAmount('100.01', 10000), 'The amount is more than what is still open (100.00).');
  assert.equal(correctionStatusLabel('open'), 'Still open');
  assert.equal(correctionStatusLabel('unknown_status'), 'unknown_status');

  setActiveLanguage('so');
  try {
    assert.equal(validateWriteOffReason('no'), CATALOGUE['validation.payroll.writeOffReasonMissing'].so);
    assert.equal(validateWriteOffReason('Employee left the country'), null);
    assert.equal(validateSettlementAmount('', 10000), CATALOGUE['validation.payroll.amountPositive'].so);
    assert.equal(validateSettlementAmount('100.01', 10000), 'Qaddarku wuu ka badan yahay waxa weli furan (100.00).');
    assert.equal(correctionStatusLabel('written_off'), 'La cafiyey');
    assert.equal(correctionStatusLabel('unknown_status'), 'unknown_status');
  } finally {
    setActiveLanguage('en');
  }
});
