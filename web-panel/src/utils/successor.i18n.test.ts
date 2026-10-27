import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { EMPTY_SUCCESSOR, toSuccessorPayload, validateSuccessor, type SuccessorDraft } from './successor.ts';

const temporary: SuccessorDraft = { ...EMPTY_SUCCESSOR, type: 'temporary', employeeId: 7 };

test('replacement-choice messages are English by default, with the same wording as before', () => {
  assert.equal(validateSuccessor(EMPTY_SUCCESSOR), 'Choose who takes over this Permanent position: a Temporary employee who has been covering it, or a person from the HR Waiting List.');
  assert.equal(validateSuccessor({ ...temporary, effectiveDate: '2026-13-45x' }), 'Enter the effective date of the replacement as a valid date.');
  assert.equal(
    validateSuccessor({ ...temporary, effectiveDate: '2026-10-20' }, '2026-10-25'),
    'The effective date (2026-10-20) must be after the last paid day (2026-10-25). Both days are included, so one day cannot be paid to two people.',
  );
  assert.equal(validateSuccessor({ ...EMPTY_SUCCESSOR, type: 'waiting_list', employeeId: 9 }), 'Enter the salary agreed for the person from the Waiting List.');
  assert.equal(
    validateSuccessor({ ...EMPTY_SUCCESSOR, type: 'waiting_list', employeeId: 9, salaryAmount: '500', salaryCurrency: 'US' }),
    'Enter the salary currency (for example USD).',
  );
});

test('replacement-choice messages are Somali while an HR screen shows Somali, and the request payload is identical', () => {
  const before = JSON.stringify(toSuccessorPayload({ ...temporary, effectiveDate: '2026-10-26' }, '2026-10-25'));
  try {
    setActiveLanguage('so');
    assert.equal(validateSuccessor(EMPTY_SUCCESSOR), CATALOGUE['validation.hr.successorChoose'].so);
    assert.equal(
      validateSuccessor({ ...temporary, effectiveDate: '2026-10-20' }, '2026-10-25'),
      'Taariikhda bilowga (2026-10-20) waa inay ka dambeysaa maalinta ugu dambeysa ee la bixiyey (2026-10-25). Labada maalmood waa la daray, sidaas darteed hal maalin laba qof lagu bixin karo.',
    );
    assert.equal(validateSuccessor({ ...EMPTY_SUCCESSOR, type: 'waiting_list', employeeId: 9 }), CATALOGUE['validation.hr.successorSalary'].so);
    assert.equal(JSON.stringify(toSuccessorPayload({ ...temporary, effectiveDate: '2026-10-26' }, '2026-10-25')), before);
    assert.equal(validateSuccessor({ ...temporary, effectiveDate: '2026-10-26' }, '2026-10-25'), null);
  } finally {
    setActiveLanguage('en');
  }
});
