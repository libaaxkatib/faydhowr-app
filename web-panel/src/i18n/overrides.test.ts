import test from 'node:test';
import assert from 'node:assert/strict';

import { defineMessages, message } from './catalog.ts';
import { CATALOGUE, translateKey } from './messages/index.ts';
import { applyOverrides, effectiveCatalogue, keepsPlaceholders, parseOverrides, setOverrides } from './overrides.ts';

const base = defineMessages({
  'action.test.hello': message('Hello', 'Salaan'),
  'warning.test.count': message('{n} items for {name}', '{n} shay oo {name} ah'),
});

test('with no overrides the built-in catalogue is returned unchanged', () => {
  assert.equal(applyOverrides(base, {}), base);
});

test('an override replaces the language it carries and keeps the other built-in text', () => {
  const merged = applyOverrides(base, { 'action.test.hello': { so: 'Nabad' } });
  assert.equal(merged['action.test.hello'].so, 'Nabad');
  assert.equal(merged['action.test.hello'].en, 'Hello');
  assert.equal(base['action.test.hello'].so, 'Salaan', 'the built-in object is never mutated');

  const english = applyOverrides(base, { 'action.test.hello': { en: 'Hi' } });
  assert.equal(english['action.test.hello'].en, 'Hi');
  assert.equal(english['action.test.hello'].so, 'Salaan');
});

test('an override for a key the catalogue does not have is ignored', () => {
  const merged = applyOverrides(base, { 'action.test.unknown': { so: 'Waxba' } });
  assert.equal(merged['action.test.unknown'], undefined);
});

test('an override that changes the placeholders is ignored for that language, so a screen can never lose its count or name', () => {
  const merged = applyOverrides(base, { 'warning.test.count': { so: 'shay badan', en: '{n} things for {name}' } });
  assert.equal(merged['warning.test.count'].so, '{n} shay oo {name} ah');
  assert.equal(merged['warning.test.count'].en, '{n} things for {name}');
  assert.ok(keepsPlaceholders('{n} items', 'Waa {n}'));
  assert.ok(!keepsPlaceholders('{n} items', 'Waa {m}'));
  assert.ok(!keepsPlaceholders('{n} items', 'Waa'));
});

test('a blank override text falls back to the built-in wording', () => {
  const merged = applyOverrides(base, { 'action.test.hello': { so: '   ', en: '' } });
  assert.equal(merged['action.test.hello'].so, 'Salaan');
  assert.equal(merged['action.test.hello'].en, 'Hello');
});

test('the response of the API is read defensively', () => {
  assert.deepEqual(parseOverrides(null), {});
  assert.deepEqual(parseOverrides([]), {});
  assert.deepEqual(parseOverrides({ a: 'text', b: null, c: { so: 5 }, d: { so: 'Haa', en: ' ' } }), { d: { so: 'Haa' } });
});

test('the panel translates with the edited wording and goes back to the built-in text when the override is removed', () => {
  const key = 'nav.hrGroup';
  const built = CATALOGUE[key];
  try {
    setOverrides({ [key]: { so: 'Maamulka Shaqaalaha Cusub', en: 'People' } });
    assert.equal(translateKey('so', key), 'Maamulka Shaqaalaha Cusub');
    assert.equal(translateKey('en', key), 'People');
    assert.equal(effectiveCatalogue(CATALOGUE)[key].review, built.review, 'only the text changes');
  } finally {
    setOverrides({});
  }
  assert.equal(translateKey('so', key), built.so);
  assert.equal(translateKey('en', key), built.en);
  assert.equal(effectiveCatalogue(CATALOGUE), CATALOGUE);
});
