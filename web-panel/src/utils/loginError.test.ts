import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { ApiClientError } from '../api/client.ts';
import { describeLoginFailure } from './loginError.ts';

afterEach(() => setActiveLanguage('en'));

const wrongCredentials = () => new ApiClientError(401, 'Invalid email or password.', 'INVALID_CREDENTIALS');
const NETWORK_SO = 'Server-ka lama gaari karo. Fiiri isku xirkaaga oo mar kale isku day.';

test('wrong credentials: English as the server says it, Somali as the catalogue says it', () => {
  assert.equal(describeLoginFailure(wrongCredentials()), 'Invalid email or password.');
  setActiveLanguage('so');
  assert.equal(describeLoginFailure(wrongCredentials()), 'Iimaylka ama erayga sirta ah ma saxna.');
});

test('the wrong-credentials message never says which of the two is wrong, in either language', () => {
  for (const language of ['en', 'so'] as const) {
    setActiveLanguage(language);
    const text = describeLoginFailure(wrongCredentials());
    assert.match(text, language === 'en' ? /email or password/i : /iimaylka ama erayga sirta/i);
    assert.doesNotMatch(text, /(unknown|not found|no account|ma jiro)/i);
  }
});

test('the other sign-in answers of the backend are translated; the sentence the server sent is the one recognised', () => {
  setActiveLanguage('so');
  assert.equal(describeLoginFailure(new ApiClientError(403, 'Admin account is inactive.', 'ADMIN_ACCOUNT_INACTIVE')), 'Akoonka maamulaha ma shaqeynayo.');
  assert.equal(describeLoginFailure(new ApiClientError(500, 'Login failed.', 'LOGIN_FAILED')), 'Galitaanku wuu fashilmay. Fadlan mar kale isku day.');
  assert.equal(describeLoginFailure(new ApiClientError(429, 'Too Many Attempts.')), 'Isku day aad u badan. Fadlan sug wax yar oo mar kale isku day.');
  setActiveLanguage('en');
  assert.equal(describeLoginFailure(new ApiClientError(429, 'Too Many Attempts.')), 'Too Many Attempts.');
});

test('missing or malformed sign-in fields show the first field message, translated in Somali', () => {
  const failure = () =>
    new ApiClientError(422, 'The given data was invalid.', 'VALIDATION_ERROR', {
      email: ['The email field must be a valid email address.'],
      password: ['The password field is required.'],
    });
  assert.equal(describeLoginFailure(failure()), 'The email field must be a valid email address.');
  setActiveLanguage('so');
  assert.equal(describeLoginFailure(failure()), 'Iimayl waa inuu noqdaa ciwaan iimayl oo sax ah.');
  // Laravel's own summary for a request that does not override the failure: the first message plus a count
  const summary = new ApiClientError(422, 'The email field is required. (and 1 more error)');
  assert.equal(describeLoginFailure(summary), 'Iimayl waa loo baahan yahay. (iyo 1 khalad oo kale)');
});

test('a network failure is a network message, never a validation message, in both languages', () => {
  const offline = new TypeError('Failed to fetch');
  assert.equal(describeLoginFailure(offline), 'Could not reach the server. Check your connection and try again.');
  setActiveLanguage('so');
  assert.equal(describeLoginFailure(offline), NETWORK_SO);
  assert.equal(describeLoginFailure(new TypeError('NetworkError when attempting to fetch resource.')), NETWORK_SO);
  assert.equal(describeLoginFailure(new TypeError('Load failed')), NETWORK_SO);
});

test('anything else is the generic message and never leaks details', () => {
  const generic = 'Something went wrong. Please try again.';
  assert.equal(describeLoginFailure(new Error('secret stack detail: SQLSTATE[08006]')), generic);
  assert.equal(describeLoginFailure(new TypeError("Cannot read properties of undefined (reading 'x')")), generic, 'a programming TypeError is not shown as a network error');
  assert.equal(describeLoginFailure('boom'), generic);
  assert.equal(describeLoginFailure(undefined), generic);
  setActiveLanguage('so');
  assert.notEqual(describeLoginFailure(new Error('secret')), generic);
  assert.doesNotMatch(describeLoginFailure(new Error('SQLSTATE secret')), /SQLSTATE|secret/);
});

test('an unknown server sentence is shown as sent, in both languages', () => {
  setActiveLanguage('so');
  assert.equal(describeLoginFailure(new ApiClientError(418, 'I am a teapot.')), 'I am a teapot.');
});
