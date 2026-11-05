import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';

import { CATALOGUE } from './messages/index.ts';

/**
 * THE 161 LOCKED MESSAGES. On 2026-10-27 the owner declared the 161 existing owner-approved Somali messages FINAL, APPROVED AND LOCKED.
 *
 * These tests compare the live catalogue with a fixed, committed baseline (`baseline/approved-161.json`, built once from the catalogue as committed in
 * 2a4ecc2 and cross-checked against docs/HR_SOMALI_TRANSLATION_REGISTER.md). The baseline is never regenerated from the catalogue: its SHA-256 is pinned
 * in this file, so changing the baseline to match a changed catalogue also fails. They fail if
 *   - an approved Somali text changes in any way (spelling, capitals, punctuation, spacing),
 *   - an approved message, its key, its owner id or its English text disappears or changes,
 *   - another message is labelled "approved" without being in the baseline (or in the two named additions below),
 *   - an approved message is no longer used by one of the screens that used it (for example because a merge conflict removed its use).
 * The 23 owner terms still awaiting review and the 1,841 proposals keep their own statuses (checked at the end).
 */

const BASELINE_SHA256 = 'ad500a9affadf2bee600acd67b4827c579bbf7b9a2209e54dde33b5ecdf2cecc';
const AWAITING_SHA256 = '81e1723c3ff3412020fe423d011bc33c3ef94861d55a254cdba8776b3b522cba';

/**
 * THE 86 R1C APPROVALS (owner decision of 2026-11-05). Exactly 86 Somali texts were approved: the 50 corrections listed in
 * docs/HR_SOMALI_CORRECTIONS_20261105.md and the 36 messages of the R1C Freshness Guard (messages/payrollRelease1c.ts). They carry the ids OWN-R1C-001 ..
 * OWN-R1C-086 and are pinned by their own committed baseline (`baseline/approved-r1c-86.json`, SHA-256 below) exactly like the 161: changing a word, an id or
 * the baseline itself fails. They are NOT among the 161 locked messages (that baseline is untouched), and the 90 messages the same review left alone stay
 * proposals (listed in the baseline, checked below). Nothing else may become approved.
 */
const R1C_SHA256 = '0359fdc52eef7ccae9210c9c1d386c93e99452906fd8dab420abe3a2aa0197ec';

/** The two warnings the owner supplied later (decisions U1 of 2026-10-27). They are approved, but they are not among the 161 locked messages. */
const OWNER_APPROVED_ADDITIONS: Record<string, { id: string; so: string }> = {
  'warning.employees.typeChangeBlocked': {
    id: 'OWN-U1-001',
    so: 'Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon. Si looga fogaado in xogta mushaharkiisu lunto, marka hore jooji shaqadii hore, qof kale u qoondee booskaas, kadibna samee assignment-ka cusub ee Temporary. Nidaamku si toos ah uma beddelayo shaqada hore.',
  },
  'warning.pipeline.coverOwnAssignmentOverlap': {
    id: 'OWN-U1-002',
    so: 'Shaqaalahaan wuxuu hore ugu jiraa shaqo Permanent ah oo waqtigeedu isku dhacayo shaqadan. Haddii aad rabto inaad shaqadan Temporary ahaan ugu wareejiso, marka hore jooji/cancel garee shaqadii hore, kadibna qof kale u qoondee booskii hore. Shift-yo kala duwan waa la oggol yahay haddii waqtigoodu isma saarna.',
  },
};

/**
 * Owner decision 2026-11-04: the Monthly Approve button and the company Approve step were REMOVED (approval is now payment: Approve Paid / Approve
 * Partial). These two approved messages were the words of those buttons, so no screen shows them any more. Their wording stays exactly as locked (key,
 * owner id, English and Somali are still pinned by the baseline checks above); only the "still shown on its screen" checks skip them. Nothing else is exempt.
 */
const RETIRED_FROM_SCREEN = new Set(['action.approvePayroll', 'action.approvePayrollButton']);

/**
 * Owner decision O-1 (2026-11-05): the month-level Reopen button and its dialog were REMOVED from the Payroll page (a company is reopened only from its own
 * row). Its two approved messages are still used by the company-level reopen screens, so only this ONE screen is exempt for them, and only for the check
 * that they are still shown there. Their wording stays exactly as locked (pinned by the baseline checks above); every other screen is still checked.
 */
const RETIRED_FROM_ONE_SCREEN = new Set(['action.reopen@PayrollRunsPage.tsx', 'action.reopenPayroll@PayrollRunsPage.tsx']);

interface BaselineMessage {
  key: string;
  id: string;
  en: string;
  so: string;
  soSha256: string;
  screens: string[];
}

const sha256 = (text: string) => createHash('sha256').update(text, 'utf8').digest('hex');
/** Line endings are not part of the baseline: a Windows checkout may turn LF into CRLF. */
const readFixture = (name: string) => readFileSync(new URL(`./baseline/${name}`, import.meta.url), 'utf8').replace(/\r\n/g, '\n');

const baselineText = readFixture('approved-161.json');
const baseline = JSON.parse(baselineText) as { count: number; messages: BaselineMessage[] };
const entries = CATALOGUE as unknown as Record<string, { en: string; so: string; review: string; id?: string }>;

const r1cText = readFixture('approved-r1c-86.json');
const r1c = JSON.parse(r1cText) as { count: number; corrected: number; guard: number; messages: Omit<BaselineMessage, 'screens'>[]; stillProposed90: string[] };

test('the baseline file is the one the owner locked: pinned hash, 161 distinct messages', () => {
  assert.equal(sha256(baselineText), BASELINE_SHA256, 'baseline/approved-161.json was changed - it must never be edited or regenerated');
  assert.equal(baseline.count, 161);
  assert.equal(baseline.messages.length, 161);
  assert.equal(new Set(baseline.messages.map((m) => m.key)).size, 161, 'keys are unique');
  for (const m of baseline.messages) assert.equal(sha256(m.so), m.soSha256, `${m.key}: the baseline's own Somali hash`);
});

test('every locked message is in the catalogue with its exact Somali, English, id and approved status', () => {
  const problems: string[] = [];
  for (const m of baseline.messages) {
    const entry = entries[m.key];
    if (!entry) {
      problems.push(`${m.key}: MISSING from the catalogue`);
      continue;
    }
    if (entry.so !== m.so) problems.push(`${m.key}: Somali changed\n    locked : ${m.so}\n    current: ${entry.so}`);
    if (sha256(entry.so) !== m.soSha256) problems.push(`${m.key}: Somali hash differs`);
    if (entry.en !== m.en) problems.push(`${m.key}: English changed\n    locked : ${m.en}\n    current: ${entry.en}`);
    if (entry.id !== m.id) problems.push(`${m.key}: owner id changed (${m.id} -> ${entry.id})`);
    if (entry.review !== 'approved') problems.push(`${m.key}: status is ${entry.review}, must stay approved`);
  }
  assert.deepEqual(problems, [], `Locked messages were altered:\n${problems.join('\n')}`);
});

test('the R1C baseline is the one the owner approved: pinned hash, exactly 86 distinct messages (50 corrections + 36 guard messages)', () => {
  assert.equal(sha256(r1cText), R1C_SHA256, 'baseline/approved-r1c-86.json was changed - it must never be edited or regenerated');
  assert.equal(r1c.count, 86);
  assert.equal(r1c.messages.length, 86);
  assert.equal(r1c.corrected, 50);
  assert.equal(r1c.guard, 36);
  assert.equal(new Set(r1c.messages.map((m) => m.key)).size, 86, 'keys are unique');
  assert.deepEqual(r1c.messages.map((m) => m.id), Array.from({ length: 86 }, (_, i) => `OWN-R1C-${String(i + 1).padStart(3, '0')}`), 'ids run 001..086 without a gap');
  for (const m of r1c.messages) assert.equal(sha256(m.so), m.soSha256, `${m.key}: the baseline's own Somali hash`);
  const locked = new Set(baseline.messages.map((m) => m.key));
  assert.deepEqual(r1c.messages.filter((m) => locked.has(m.key)), [], 'none of the 86 is one of the 161 locked messages');
});

test('every one of the 86 is in the catalogue with its exact Somali, English and id, marked approved', () => {
  const problems: string[] = [];
  for (const m of r1c.messages) {
    const entry = entries[m.key];
    if (!entry) {
      problems.push(`${m.key}: MISSING from the catalogue`);
      continue;
    }
    if (entry.so !== m.so) problems.push(`${m.key}: Somali differs\n    approved: ${m.so}\n    current : ${entry.so}`);
    if (sha256(entry.so) !== m.soSha256) problems.push(`${m.key}: Somali hash differs`);
    if (entry.en !== m.en) problems.push(`${m.key}: English differs`);
    if (entry.id !== m.id) problems.push(`${m.key}: id ${entry.id} (expected ${m.id})`);
    if (entry.review !== 'approved') problems.push(`${m.key}: status is ${entry.review}, must be approved`);
  }
  assert.deepEqual(problems, [], `The R1C approvals were altered:\n${problems.join('\n')}`);
});

test('the other 90 messages of the same review were NOT approved: they are still proposals without an owner id', () => {
  assert.equal(r1c.stillProposed90.length, 90);
  assert.equal(new Set(r1c.stillProposed90).size, 90);
  const approvedNow = new Set(r1c.messages.map((m) => m.key));
  for (const key of r1c.stillProposed90) {
    assert.ok(!approvedNow.has(key), `${key} must not be among the 86`);
    assert.equal(entries[key]?.review, 'proposed', `${key} must stay a proposal`);
    assert.equal(entries[key].id, undefined, `${key} must not carry an owner id`);
  }
});

test('nothing else is labelled approved: exactly the 161 locked messages, the two named owner warnings and the 86 R1C approvals', () => {
  const approved = Object.entries(entries).filter(([, e]) => e.review === 'approved').map(([k]) => k).sort();
  const expected = [...baseline.messages.map((m) => m.key), ...Object.keys(OWNER_APPROVED_ADDITIONS), ...r1c.messages.map((m) => m.key)].sort();
  assert.deepEqual(approved, expected);
  assert.equal(approved.length, 161 + 2 + 86);
  for (const [key, expectedEntry] of Object.entries(OWNER_APPROVED_ADDITIONS)) {
    assert.equal(entries[key].id, expectedEntry.id, key);
    assert.equal(entries[key].so, expectedEntry.so, `${key}: the owner's Somali wording`);
  }
});

// ------------------------------------------------------------------ the locked messages must stay on their screens

function sourceFiles(directory: URL): { path: string; text: string }[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const child = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);
    if (entry.isDirectory()) return entry.name === 'baseline' || entry.name === 'messages' ? [] : sourceFiles(child);
    if (!/\.(ts|tsx)$/.test(entry.name) || /\.test\.ts$/.test(entry.name)) return [];

    return [{ path: child.pathname.split('/src/')[1], text: readFileSync(child, 'utf8') }];
  });
}

const SRC = new URL('../', import.meta.url);
const files = sourceFiles(SRC);

/** True when the text refers to the key: by its literal, by the base of a plural pair, or through a `tValue('prefix', ...)` / `prefix.${...}` lookup. */
function refersTo(text: string, key: string): boolean {
  if (text.includes(`'${key}'`) || text.includes(`"${key}"`) || text.includes(`\`${key}\``)) return true;
  const base = key.replace(/\.(one|other)$/, '');
  if (base !== key && (text.includes(`'${base}'`) || text.includes(`"${base}"`))) return true;
  for (const match of text.matchAll(/tValue(?:Now)?\(\s*['"`]([A-Za-z0-9_.]+)['"`]/g)) if (key.startsWith(`${match[1]}.`)) return true;
  for (const match of text.matchAll(/[`'"]([a-z]+\.[A-Za-z0-9_.]+\.)\$\{/g)) if (key.startsWith(match[1])) return true;

  return false;
}

test('every locked message is used by the code at all', () => {
  const unused = baseline.messages.filter((m) => !RETIRED_FROM_SCREEN.has(m.key) && !files.some((f) => refersTo(f.text, m.key))).map((m) => m.key);
  assert.deepEqual(unused, [], `These approved messages are no longer used by any screen:\n${unused.join('\n')}`);
});

test('every locked message is still used by each screen that used it when it was approved', () => {
  const lost: string[] = [];
  for (const m of baseline.messages) {
    if (RETIRED_FROM_SCREEN.has(m.key)) continue;
    for (const screen of m.screens) {
      if (RETIRED_FROM_ONE_SCREEN.has(`${m.key}@${screen}`)) continue;
      const candidates = files.filter((f) => f.path.endsWith(`/${screen}`) || f.path === screen);
      if (candidates.length === 0) {
        lost.push(`${m.key} (${m.id}): screen file ${screen} no longer exists`);
        continue;
      }
      if (!candidates.some((f) => refersTo(f.text, m.key))) lost.push(`${m.key} (${m.id}): no longer used by ${screen}`);
    }
  }
  assert.deepEqual(lost, [], `Approved wording disappeared from screens:\n${lost.join('\n')}`);
});

// ------------------------------------------------------------------ the other categories keep their own statuses

test('the 23 owner terms awaiting review stay in that category, unchanged', () => {
  const text = readFixture('awaiting-review-23.json');
  assert.equal(sha256(text), AWAITING_SHA256, 'baseline/awaiting-review-23.json was changed');
  const awaiting = JSON.parse(text) as { count: number; messages: BaselineMessage[] };
  assert.equal(awaiting.count, 23);
  for (const m of awaiting.messages) {
    assert.equal(entries[m.key]?.review, 'owner-term', `${m.key} must stay "owner-term" (awaiting the owner's review), not become approved or proposed`);
    assert.equal(entries[m.key].so, m.so, `${m.key}: wording`);
    assert.equal(entries[m.key].id, m.id, `${m.key}: id`);
  }
  const all = Object.entries(entries).filter(([, e]) => e.review === 'owner-term').map(([k]) => k).sort();
  assert.deepEqual(all, awaiting.messages.map((m) => m.key).sort(), 'no message was added to or removed from the awaiting-review category');
});

test('every other message is a proposal and none carries an owner id', () => {
  const locked = new Set([...baseline.messages.map((m) => m.key), ...Object.keys(OWNER_APPROVED_ADDITIONS), ...r1c.messages.map((m) => m.key)]);
  const awaiting = new Set((JSON.parse(readFixture('awaiting-review-23.json')) as { messages: BaselineMessage[] }).messages.map((m) => m.key));
  for (const [key, entry] of Object.entries(entries)) {
    if (locked.has(key) || awaiting.has(key)) continue;
    assert.equal(entry.review, 'proposed', `${key} is neither locked nor awaiting review, so it must be a proposal`);
    assert.equal(entry.id, undefined, `${key} must not carry an owner review id`);
  }
});
