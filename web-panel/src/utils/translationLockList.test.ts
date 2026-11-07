import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { applyOverrides, isLockedEntry } from '../i18n/overrides.ts';

// TRANSLATION-POLICY protection boundary: owner-approved wording (the 161 locked messages, the 86 Release 1C approvals and the two named approved additions)
// cannot be replaced by an override, a proposal or an approval. The server refuses it from a key list; this test keeps that list identical to the catalogue
// and to the two pinned baselines, and proves the panel's override layer ignores an override on a locked message.

const read = (path: string) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
const server: { count: number; keys: string[] } = read('../../../backend/resources/i18n/locked-translation-keys.json');
const baseline161: { messages: { key: string }[] } = read('../i18n/baseline/approved-161.json');
const baseline86: { messages: { key: string }[] } = read('../i18n/baseline/approved-r1c-86.json');

const approvedKeys = Object.entries(CATALOGUE).filter(([, entry]) => entry.review === 'approved').map(([key]) => key).sort();

test('the server list of locked keys is exactly the approved messages of the catalogue (249 = 161 + 86 + 2 named additions)', () => {
  assert.equal(server.count, server.keys.length);
  assert.deepEqual([...server.keys].sort(), approvedKeys, 'regenerate backend/resources/i18n/locked-translation-keys.json when an approval is added by the owner');
  assert.equal(server.keys.length, 249);
});

test('the list contains every message of both pinned baselines, and nothing outside them but the two named additions', () => {
  const pinned = new Set([...baseline161.messages.map((m) => m.key), ...baseline86.messages.map((m) => m.key)]);
  assert.equal(baseline161.messages.length, 161);
  assert.equal(baseline86.messages.length, 86);
  for (const key of pinned) assert.ok(server.keys.includes(key), `${key} is locked on the server`);
  assert.deepEqual(server.keys.filter((key) => !pinned.has(key)).sort(), ['warning.employees.typeChangeBlocked', 'warning.pipeline.coverOwnAssignmentOverlap']);
});

test('an override stored for a locked message is ignored by the panel, whoever wrote it; the same override on a proposed message still applies', () => {
  const locked = approvedKeys[0];
  const open = Object.entries(CATALOGUE).find(([, entry]) => entry.review === 'proposed')![0];
  const base = CATALOGUE as unknown as Parameters<typeof applyOverrides>[0];

  const merged = applyOverrides(base, { [locked]: { en: 'Replaced wording', so: 'Qoraal la beddelay' }, [open]: { en: 'Edited wording', so: 'Qoraal la tafatiray' } });

  assert.equal(merged[locked].so, CATALOGUE[locked as keyof typeof CATALOGUE].so, 'the locked Somali text is verbatim');
  assert.equal(merged[locked].en, CATALOGUE[locked as keyof typeof CATALOGUE].en);
  assert.equal(merged[open].so, 'Qoraal la tafatiray');
  assert.ok(isLockedEntry(CATALOGUE[locked as keyof typeof CATALOGUE]));
  assert.ok(!isLockedEntry(CATALOGUE[open as keyof typeof CATALOGUE]));
});

test('every one of the 249 locked messages keeps its text under an override that tries to replace all of them at once', () => {
  const base = CATALOGUE as unknown as Parameters<typeof applyOverrides>[0];
  const everything = Object.fromEntries(server.keys.map((key) => [key, { en: 'X', so: 'Y' }]));

  const merged = applyOverrides(base, everything);

  for (const key of server.keys) {
    assert.equal(merged[key].so, CATALOGUE[key as keyof typeof CATALOGUE].so, key);
    assert.equal(merged[key].en, CATALOGUE[key as keyof typeof CATALOGUE].en, key);
  }
});

test('the Translations page shows a locked message as Locked, with no Edit and no Propose button', () => {
  const page = readFileSync(new URL('../features/system/TranslationsPage.tsx', import.meta.url), 'utf8');
  assert.ok(page.includes('isLockedMessage(key) ? ('));
  assert.ok(page.includes("t('option.ownerDecisions1c.translationLocked')"));
  assert.ok(page.indexOf("t('option.ownerDecisions1c.translationLocked')") < page.indexOf("t('action.ownerDecisions1c.proposeChange')"), 'the locked badge replaces the button for a locked message');
});
