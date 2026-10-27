import test from 'node:test';
import assert from 'node:assert/strict';

import { defineMessages, message, placeholdersOf } from './catalog.ts';
import { CATALOGUE, translateCount, translateKey } from './messages/index.ts';
import { getActiveLanguage, setActiveLanguage, tNow, tNowCount } from './active.ts';
import { interpolate, lookup, pluralCategory, translate, translatePlural } from './translate.ts';

const sample = defineMessages({
  'action.greet': message('Hello {name}', 'Salaan {name}'),
  'action.only': message('Only English here', ''),
  'action.rows.one': message('{n} row', '{n} saf'),
  'action.rows.other': message('{n} rows', '{n} safaf'),
  'action.things.other': message('{n} things', '{n} wax'),
});

// ------------------------------------------------------------------ interpolation

test('placeholders are filled in, left alone when no value is given, and the variable names are never translated', () => {
  assert.equal(interpolate('Move {name} to {stage}', { name: 'Asha', stage: 'Waiting' }), 'Move Asha to Waiting');
  assert.equal(interpolate('Move {name} to {stage}', { name: 'Asha' }), 'Move Asha to {stage}');
  assert.equal(interpolate('Import {n} rows', { n: 3 }), 'Import 3 rows');
  assert.equal(interpolate('No placeholders', { n: 1 }), 'No placeholders');
  assert.equal(interpolate('Hello {name}'), 'Hello {name}');
  assert.equal(interpolate('{a}{a}', { a: 'x' }), 'xx');
});

test('a value is inserted as plain text: it cannot inject another placeholder or a replacement pattern', () => {
  assert.equal(interpolate('Hello {name}', { name: '{other}' }), 'Hello {other}');
  assert.equal(interpolate('Hello {name}', { name: '$& $1 $$' }), 'Hello $& $1 $$');
  assert.equal(interpolate('Hello {name}', { name: '<b>x</b>' }), 'Hello <b>x</b>', 'React escapes it when rendering; nothing is parsed here');
  assert.equal(interpolate('{constructor}', { name: 'x' }), '{constructor}', 'inherited object properties are not values');
});

// ------------------------------------------------------------------ fallback

test('a missing or empty Somali text falls back to the English text, an unknown key shows the key', () => {
  assert.equal(translate(sample, 'so', 'action.greet', { name: 'Asha' }), 'Salaan Asha');
  assert.equal(translate(sample, 'en', 'action.greet', { name: 'Asha' }), 'Hello Asha');
  assert.equal(translate(sample, 'so', 'action.only'), 'Only English here');
  assert.equal(translate(sample, 'so', 'action.missing'), 'action.missing');
  assert.equal(translate(sample, 'en', 'action.missing'), 'action.missing');
  assert.equal(lookup({ 'x.y': { en: 'E', so: '   ', review: 'proposed' } }, 'so', 'x.y'), 'E', 'blank counts as missing');
  assert.equal(lookup({ 'x.y': { en: 'E', review: 'proposed' } as never }, 'so', 'x.y'), 'E', 'absent counts as missing');
});

// ------------------------------------------------------------------ plurals

test('plural forms follow the count, fill {n}, and fall back to the .other form', () => {
  assert.equal(pluralCategory('en', 1), 'one');
  assert.equal(pluralCategory('en', 0), 'other');
  assert.equal(pluralCategory('so', 1), 'one');
  assert.equal(pluralCategory('so', 5), 'other');
  assert.equal(translatePlural(sample, 'en', 'action.rows', 1), '1 row');
  assert.equal(translatePlural(sample, 'en', 'action.rows', 4), '4 rows');
  assert.equal(translatePlural(sample, 'so', 'action.rows', 1), '1 saf');
  assert.equal(translatePlural(sample, 'so', 'action.rows', 4), '4 safaf');
  assert.equal(translatePlural(sample, 'en', 'action.things', 1), '1 things', 'no .one form: .other is used');
});

// ------------------------------------------------------------------ the real catalogue

test('the real catalogue translates, interpolates and keeps placeholders intact in both languages', () => {
  assert.equal(translateKey('en', 'action.advanceTo', { status: 'Waiting' }), 'Advance to Waiting');
  assert.equal(translateKey('so', 'action.advanceTo', { status: 'Shaqa sugayaal' }), 'U gudub Shaqa sugayaal');
  assert.equal(translateKey('en', 'action.advanceTo'), 'Advance to {status}', 'an unfilled placeholder stays visible');
  assert.equal(translateCount('en', 'action.importRows', 1), 'Import 1 ready row');
  assert.equal(translateCount('en', 'action.importRows', 12), 'Import 12 ready rows');
  assert.equal(translateCount('so', 'action.importRows', 12), 'Soo geli Excel (safafka diyaarka ah: 12)');
  assert.ok(Object.keys(CATALOGUE).length > 100);
});

// ------------------------------------------------------------------ the active language for non-component code

test('the active language starts as English, so utilities and every other test keep their English text', () => {
  assert.equal(getActiveLanguage(), 'en');
  assert.equal(tNow('action.save'), 'Save');
  assert.equal(tNowCount('action.importRows', 1), 'Import 1 ready row');
});

test('moving the active language to Somali changes tNow, and moving it back restores English', () => {
  try {
    setActiveLanguage('so');
    assert.equal(tNow('action.save'), 'Keydi');
    assert.equal(tNow('action.advanceTo', { status: 'X' }), 'U gudub X');
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(tNow('action.save'), 'Save');
});

test('placeholdersOf lists the names used in a text, sorted', () => {
  assert.deepEqual(placeholdersOf('Move {name} to {stage} ({n})'), ['n', 'name', 'stage']);
  assert.deepEqual(placeholdersOf('none'), []);
});
