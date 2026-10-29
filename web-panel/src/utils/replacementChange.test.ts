import test from 'node:test';
import assert from 'node:assert/strict';

import {
  canEndCoverage,
  centsToAmount,
  daysInclusive,
  defaultLastDay,
  describeEndChange,
  matchesStatusFilter,
  needsExtensionConfirmation,
  validChangeReason,
  validPeriod,
} from './replacementChange.ts';

test('both ends of a cover are counted: 1 to 10 November is ten days, one day is one day', () => {
  assert.equal(daysInclusive('2026-11-01', '2026-11-10'), 10);
  assert.equal(daysInclusive('2026-11-10', '2026-11-10'), 1);
  assert.equal(daysInclusive('2026-02-27', '2026-03-02'), 4, 'across the end of February');
  assert.ok(daysInclusive('2026-11-10', '2026-11-01') < 1, 'a reversed range is not a range');
});

test('End Coverage starts from the planned end date and never from today', () => {
  assert.equal(defaultLastDay('2026-11-10'), '2026-11-10');
  assert.equal(defaultLastDay(null), '', 'a cover with no end date makes the person type the last day');
  assert.equal(defaultLastDay('not a date'), '');
});

test('a last day later than the recorded end is an extension and says how many days it adds', () => {
  const change = describeEndChange('2026-11-01', '2026-11-10', '2026-11-12');
  assert.deepEqual(change, { kind: 'extension', daysDelta: 2 });
  assert.ok(needsExtensionConfirmation(change));
});

test('the planned end, an earlier day and the first end of an open cover need no confirmation', () => {
  assert.deepEqual(describeEndChange('2026-11-01', '2026-11-10', '2026-11-10'), { kind: 'same', daysDelta: 0 });
  assert.deepEqual(describeEndChange('2026-11-01', '2026-11-10', '2026-11-07'), { kind: 'shorter', daysDelta: -3 });
  assert.deepEqual(describeEndChange('2026-11-01', null, '2026-11-30'), { kind: 'first', daysDelta: 0 });
  for (const chosen of ['2026-11-10', '2026-11-07']) assert.equal(needsExtensionConfirmation(describeEndChange('2026-11-01', '2026-11-10', chosen)), false);
});

test('a missing, malformed or reversed last day is not a valid change', () => {
  assert.equal(describeEndChange('2026-11-01', '2026-11-10', '').kind, 'missing');
  assert.equal(describeEndChange('2026-11-01', '2026-11-10', '2026-10-31').kind, 'invalid', 'before the start');
  assert.equal(describeEndChange('2026-11-01', '2026-11-10', '2026-13-40').kind, 'invalid');
});

test('the save button of End Coverage waits for a valid day, the confirmation of an extension, and a Super Admin when closed payroll is touched', () => {
  const extension = describeEndChange('2026-11-01', '2026-11-10', '2026-11-12');
  const early = describeEndChange('2026-11-01', '2026-11-10', '2026-11-08');

  assert.equal(canEndCoverage(extension, false, false, false, ''), false, 'an extension must be confirmed');
  assert.equal(canEndCoverage(extension, true, false, false, ''), true);
  assert.equal(canEndCoverage(early, false, false, false, ''), true, 'ending early is free');
  assert.equal(canEndCoverage(early, false, true, false, ''), false, 'a conflict blocks');
  assert.equal(canEndCoverage(early, false, false, true, ''), false, 'closed payroll needs a Super Admin');
  assert.equal(canEndCoverage(describeEndChange('2026-11-01', '2026-11-10', ''), false, false, false, ''), false);
  assert.equal(canEndCoverage(early, false, false, false, 'short'), false, 'a closed-payroll reason, when given, must be a real sentence');
});

test('the Active filter shows running and not-yet-started covers; Expired shows only those past their end date', () => {
  assert.equal(matchesStatusFilter('active', 'running'), true);
  assert.equal(matchesStatusFilter('active', 'scheduled'), true);
  assert.equal(matchesStatusFilter('active', 'expired'), false);
  assert.equal(matchesStatusFilter('expired', 'expired'), true);
  assert.equal(matchesStatusFilter('expired', 'running'), false);
  assert.equal(matchesStatusFilter('ended', 'ended'), true);
  assert.equal(matchesStatusFilter(null, 'expired'), true);
});

test('amounts in cents are shown as plain money', () => {
  assert.equal(centsToAmount(20000), '200.00');
  assert.equal(centsToAmount(19355), '193.55');
  assert.equal(centsToAmount(-1005), '-10.05');
  assert.equal(centsToAmount(5), '0.05');
});

test('a correction needs a reason of ten characters and a valid period', () => {
  assert.equal(validChangeReason('too short'), false);
  assert.equal(validChangeReason('   typed the wrong date   '), true);
  assert.equal(validPeriod('2026-11-01', '2026-11-10'), true);
  assert.equal(validPeriod('2026-11-01', ''), true, 'the end date is optional');
  assert.equal(validPeriod('2026-11-10', '2026-11-01'), false);
  assert.equal(validPeriod('', ''), false);
});

// ---------------------------------------------------------------- wiring and wording guards (source level; the behaviour is checked above and in the browser)
import { readFileSync } from 'node:fs';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('End Coverage starts from the planned end date, never from today, and lives in its own dialog file', () => {
  const dialogs = read('../features/hrm/ReplacementChangeDialogs.tsx');
  const page = read('../features/hrm/TemporaryReplacementsPage.tsx');

  assert.ok(dialogs.includes('useState(defaultLastDay(replacement.end_date))'), 'the last day starts from the planned end date');
  assert.ok(!page.includes('function EndReplacementModal'), 'the old End Coverage form (which defaulted to today) is gone');
  assert.ok(dialogs.includes("change.kind === 'extension'") && dialogs.includes('confirm_extension'), 'an extension is warned about and must be confirmed');
  assert.ok(page.includes('matchesStatusFilter(') && page.includes('is_expired'), 'the list knows which covers are expired');
});

test('the wording never says that only verified days are charged (the cost follows the calendar days of the cover)', () => {
  const messages = read('../i18n/messages/pipeline.ts') + read('../i18n/messages/replacementFixes.ts');
  const stale = [/only for days you verify/i, /charged only after they are verified/i, /will no longer be charged/i, /It will not be charged/i, /not verified yet, so they are not charged/i, /kaliya marka la hubiyo/i, /maalmaha aad hubiso/i];

  for (const pattern of stale) assert.doesNotMatch(messages, pattern, `stale wording: ${pattern}`);
});
