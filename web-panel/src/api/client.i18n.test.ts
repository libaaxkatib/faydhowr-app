import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { ApiClientError } from './client.ts';

afterEach(() => setActiveLanguage('en'));

const validation = () =>
  new ApiClientError(422, 'The given data was invalid.', 'VALIDATION_ERROR', {
    full_name: ['The full name field is required.'],
    phone: ['The phone field must be at least 9 characters.', 'Duplicate of Qa Employee One (EMP-000001).'],
  });

test('in English the error is exactly what the server sent', () => {
  const error = validation();
  assert.equal(error.message, 'The given data was invalid.');
  assert.deepEqual(error.fieldErrors, error.rawFieldErrors);
  assert.equal(error.rawMessage, 'The given data was invalid.');
});

test('in Somali the message and the field messages are translated; the status, code, keys and counts are unchanged', () => {
  setActiveLanguage('so');
  const error = validation();
  assert.equal(error.message, 'Xogta la geliyay ma saxna.');
  assert.equal(error.fieldErrors?.full_name?.[0], 'Magaca oo dhan waa loo baahan yahay.');
  assert.equal(error.fieldErrors?.phone?.[0], 'Taleefan waa inuu ahaadaa ugu yaraan 9 xaraf.');
  assert.equal(error.fieldErrors?.phone?.[1], 'Duplicate of Qa Employee One (EMP-000001).', 'a sentence we do not know keeps its names and numbers');
  assert.equal(error.status, 422);
  assert.equal(error.code, 'VALIDATION_ERROR');
  assert.equal(error.isValidation, true);
  assert.deepEqual(Object.keys(error.fieldErrors ?? {}), ['full_name', 'phone']);
  assert.equal(error.rawMessage, 'The given data was invalid.', 'the original is kept');
});

test('an error that is already on screen follows the language when it is switched', () => {
  const error = validation();
  assert.equal(error.fieldErrors?.full_name?.[0], 'The full name field is required.');
  setActiveLanguage('so');
  assert.equal(error.fieldErrors?.full_name?.[0], 'Magaca oo dhan waa loo baahan yahay.');
  setActiveLanguage('en');
  assert.equal(error.fieldErrors?.full_name?.[0], 'The full name field is required.');
});

test('it is still an Error with the same shape: instanceof, name, and an error without field errors', () => {
  setActiveLanguage('so');
  const error = new ApiClientError(500, 'Something the catalogue does not know.');
  assert.ok(error instanceof Error);
  assert.ok(error instanceof ApiClientError);
  assert.equal(error.name, 'ApiClientError');
  assert.equal(error.message, 'Something the catalogue does not know.');
  assert.equal(error.fieldErrors, undefined);
  assert.equal(error.isUnauthenticated, false);
  assert.equal(new ApiClientError(401, 'Unauthenticated.', 'UNAUTHENTICATED').isUnauthenticated, true);
  assert.equal(new ApiClientError(403, 'x').isForbidden, true);
});
