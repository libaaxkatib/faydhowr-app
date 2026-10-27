import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { SOMALI_WORDING_CODES, additionalCompanyQuestion, assignmentLabel, friendlyErrorMessage } from './hrHelpers.ts';

const ALL_ERROR_KEYS = Object.keys(CATALOGUE).filter((key) => /^notify\.error\.[A-Z_]+$/.test(key));
const ERROR_KEYS = ALL_ERROR_KEYS.filter((key) => !SOMALI_WORDING_CODES.has(key.replace('notify.error.', '')));

test('every plain-language server-error message is English by default and Somali when an HR screen shows Somali', () => {
  assert.equal(ERROR_KEYS.length, 37);
  for (const key of ERROR_KEYS) {
    const code = key.replace('notify.error.', '');
    const entry = CATALOGUE[key as keyof typeof CATALOGUE];
    assert.equal(friendlyErrorMessage({ code }, 'fallback'), entry.en, `${code} in English`);
    try {
      setActiveLanguage('so');
      assert.equal(friendlyErrorMessage({ code }, 'fallback'), entry.so, `${code} in Somali`);
    } finally {
      setActiveLanguage('en');
    }
  }
});

test('the server\'s own message and the caller\'s fallback are never translated or replaced', () => {
  try {
    setActiveLanguage('so');
    assert.equal(friendlyErrorMessage({ code: 'SOME_NEW_CODE', message: 'Server says no.' }, 'fallback'), 'Server says no.');
    assert.equal(friendlyErrorMessage({}, 'Fallback text'), 'Fallback text');
    assert.equal(friendlyErrorMessage({ code: 'VALIDATION_ERROR', fieldErrors: { phone: ['The phone field is required.'] } }, 'x'), 'The phone field is required.');
    assert.equal(additionalCompanyQuestion({ code: 'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED', message: 'Asha is assigned to A. Assign to B as well?' }), 'Asha is assigned to A. Assign to B as well?');
  } finally {
    setActiveLanguage('en');
  }
});

test('the additional-company question falls back to a translated sentence only when the server sent none', () => {
  const error = { code: 'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED' };
  assert.equal(additionalCompanyQuestion(error), 'This employee is already assigned to another company. Do you want to assign them to this company as well?');
  try {
    setActiveLanguage('so');
    assert.equal(additionalCompanyQuestion(error), CATALOGUE['confirm.errors.additionalCompany'].so);
  } finally {
    setActiveLanguage('en');
  }
});

test('codes with server-worded English keep the server message in English and show the Somali sentence in Somali', () => {
  const codes = [...SOMALI_WORDING_CODES];
  assert.ok(codes.length >= 50);
  for (const code of codes) {
    const entry = CATALOGUE[`notify.error.${code}` as keyof typeof CATALOGUE];
    assert.ok(entry, `${code} has no catalogue entry`);
    assert.equal(friendlyErrorMessage({ code, message: 'Asha Warsame (E-1) is not allowed.' }, 'fallback'), 'Asha Warsame (E-1) is not allowed.', `${code} in English`);
    assert.equal(friendlyErrorMessage({ code }, 'fallback'), 'fallback', `${code} in English without a server message`);
    try {
      setActiveLanguage('so');
      assert.equal(friendlyErrorMessage({ code, message: 'Asha Warsame (E-1) is not allowed.' }, 'fallback'), entry.so, `${code} in Somali`);
    } finally {
      setActiveLanguage('en');
    }
  }
});

test('no code is both a plain-language code and a server-worded one', () => {
  const plain = ALL_ERROR_KEYS.map((key) => key.replace('notify.error.', '')).filter((code) => !SOMALI_WORDING_CODES.has(code));
  assert.equal(plain.length, 37);
  for (const code of SOMALI_WORDING_CODES) assert.ok(!plain.includes(code));
});

test('the Fayadhowr Office is shown in the language of the screen', () => {
  const office = { location_type: 'office' as const };
  assert.equal(assignmentLabel(office), 'Fayadhowr Office');
  try {
    setActiveLanguage('so');
    assert.equal(assignmentLabel(office), CATALOGUE['option.pipeline.fayadhowrOffice'].so);
  } finally {
    setActiveLanguage('en');
  }
});
