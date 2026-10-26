import test from 'node:test';
import assert from 'node:assert/strict';

import { differenceText, overrideWarning, paymentDiffers, toCents } from './temporaryPayment.ts';

test('amounts compare in whole cents, so float noise never raises a false warning', () => {
  assert.equal(toCents('200.00'), 20000);
  assert.equal(toCents(0.1 + 0.2), 30);
  assert.equal(toCents(''), null);
  assert.equal(toCents('abc'), null);
  assert.equal(toCents(-5), null);
  assert.equal(paymentDiffers('200.00', 200), false);
  assert.equal(paymentDiffers('0.30', 0.1 + 0.2), false);
});

test('a different amount is flagged, with the direction and size of the difference', () => {
  assert.equal(paymentDiffers('200.00', '150'), true);
  assert.equal(differenceText('200.00', '150'), '50.00 less');
  assert.equal(differenceText('200.00', 260), '60.00 more');
  assert.equal(differenceText('200.00', '200'), null);
});

test('nothing is flagged until both the calculated and the entered amount are known', () => {
  assert.equal(paymentDiffers(null, 50), false);
  assert.equal(paymentDiffers('200.00', ''), false);
  assert.equal(differenceText(undefined, 10), null);
});

test('the warning shows both figures and says the entered amount is still recorded', () => {
  const text = overrideWarning('200.00', '150');
  assert.match(text, /calculated amount is 200\.00 USD/);
  assert.match(text, /you entered 150\.00 USD/);
  assert.match(text, /50\.00 less/);
  assert.match(text, /recorded as you typed it/);
});
