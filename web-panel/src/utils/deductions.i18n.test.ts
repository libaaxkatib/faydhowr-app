import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

import { placeholdersOf } from '../i18n/catalog.ts';
import { AREAS, CATALOGUE } from '../i18n/messages/index.ts';
import { payrollRelease1b } from '../i18n/messages/payrollRelease1b.ts';
import { setActiveLanguage } from '../i18n/active.ts';
import { carryNote, centsText } from './deductions.ts';
import { translate } from '../i18n/translate.ts';

const KEYS = Object.keys(payrollRelease1b) as (keyof typeof payrollRelease1b)[];

function sourceTexts(directory: URL): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const child = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);
    if (entry.isDirectory()) return entry.name === 'i18n' ? [] : sourceTexts(child);

    return /\.(ts|tsx)$/.test(entry.name) && !/\.test\.ts$/.test(entry.name) ? [readFileSync(child, 'utf8')] : [];
  });
}

test('the Release 1B messages are registered as one area, every one a proposal with English and a different Somali text', () => {
  assert.ok(AREAS.payrollRelease1b === payrollRelease1b);
  assert.ok(KEYS.length >= 80);
  for (const key of KEYS) {
    const entry = CATALOGUE[key];
    assert.equal(entry.review, 'proposed', `${key} is a proposal, not owner-approved`);
    assert.ok(entry.en.trim() !== '' && entry.so.trim() !== '', `${key} has both languages`);
    assert.notEqual(entry.so, entry.en, `${key}: Somali differs from English`);
    assert.deepEqual(placeholdersOf(entry.so), placeholdersOf(entry.en), `${key}: the same {placeholders} in both languages`);
  }
});

test('no Release 1B key repeats a message of another area (it would silently replace it)', () => {
  for (const [area, messages] of Object.entries(AREAS)) {
    if (messages === payrollRelease1b) continue;
    for (const key of KEYS) assert.ok(!(key in messages), `${key} is also in ${area}`);
  }
});

test('every Release 1B message is used by a screen or a helper', () => {
  const sources = sourceTexts(new URL('../', import.meta.url));
  const unused = KEYS.filter((key) => !sources.some((text) => text.includes(`'${key}'`) || text.includes(`"${key}"`) || text.includes(`\`${key}\``)));
  // The server-error codes are looked up by `notify.error.${code}` in friendlyErrorMessage; their use is checked against the code list instead.
  const helper = readFileSync(new URL('./hrHelpers.ts', import.meta.url), 'utf8');
  const lookedUp = unused.filter((key) => key.startsWith('notify.error.')).filter((key) => !helper.includes(`'${key.replace('notify.error.', '')}'`));
  assert.deepEqual(unused.filter((key) => !key.startsWith('notify.error.')), []);
  assert.deepEqual(lookedUp, []);
});

test('a message with a count is written in Somali too, and the money in it is the same in both languages', () => {
  const balance = (language: 'en' | 'so') => {
    try {
      setActiveLanguage(language);

      return translate(CATALOGUE, language, 'option.deduction.totalsRow', { count: 3, amount: centsText(12345) });
    } finally {
      setActiveLanguage('en');
    }
  };
  assert.equal(balance('en'), 'Total: 3 record(s) · still owed $123.45');
  assert.ok(balance('so').includes('$123.45') && balance('so').includes('3'));
  const note = carryNote('penalty', { carried_in: true, registered_period: '2026-09', owed_cents: 100, deducted_cents: 40, not_deducted_cents: 60 });
  assert.equal(translate(CATALOGUE, 'en', 'warning.deduction.carriedFrom', { period: note?.carriedFrom ?? '' }), 'Carried forward from 2026-09.');
});

test('the explanation above Outstanding balances says nothing is forgiven and no debt is created', () => {
  assert.equal(
    CATALOGUE['option.deduction.balancesHelp'].en,
    "Amounts payroll could not deduct stay owed and are taken from the next months' pay. Nothing is forgiven automatically and no debt is created.",
  );
});
