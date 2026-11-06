import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { CATALOGUE } from '../i18n/messages/index.ts';

/**
 * Regression tests of the HR UI audit (package B). The screens are React components, so the rules they must keep are checked on their source, the same
 * way hrManagerCorrection.test.ts does; the pure rules have their own tests (replacementChange.create.test.ts, trainingBatch.test.ts).
 */
const hrm = (name: string): string => readFileSync(new URL(`../features/hrm/${name}`, import.meta.url), 'utf8');
const pages = {
  covers: hrm('TemporaryReplacementsPage.tsx'),
  coverDialogs: hrm('ReplacementChangeDialogs.tsx'),
  history: hrm('TemporaryReplacementHistoryPage.tsx'),
  leave: hrm('LeaveReportPage.tsx'),
  requests: hrm('WorkforceRequestsPage.tsx'),
  training: hrm('TrainingBatchesPage.tsx'),
  practical: hrm('PracticalBatchesPage.tsx'),
  attendance: hrm('AttendancePage.tsx'),
};

test('B-2: the daily rate label says the rate is the salary divided by 30, never by the days in the month', () => {
  const entry = CATALOGUE['option.uiAuditB.dailyRateLabel'];

  assert.match(entry.en, /÷ 30/);
  assert.match(entry.so, /÷ 30/);
  assert.ok(!/days in the month/i.test(entry.en));
  assert.ok(pages.covers.includes("t('option.uiAuditB.dailyRateLabel')"), 'the Workdays & cost screen uses the corrected label');
  assert.ok(!pages.covers.includes("t('option.pipeline.dailyRateLabel')"), 'and no longer the old one');
  // The old message is NOT edited (existing catalogue text is never changed): it stays as it was, unused by this screen.
  assert.match(CATALOGUE['option.pipeline.dailyRateLabel'].en, /days in the month/);
});

test('every new message has English and Somali and is only proposed', () => {
  const keys = Object.keys(CATALOGUE).filter((key) => key.includes('.uiAuditB.'));

  assert.ok(keys.length >= 10);
  for (const key of keys) {
    const entry = CATALOGUE[key as keyof typeof CATALOGUE];
    assert.equal(entry.review, 'proposed', key);
    assert.notEqual(entry.en, entry.so, key);
    assert.ok(entry.en.length > 0 && entry.so.length > 0, key);
  }
});

test('B-3: after a 409 the End and Edit dialogs refresh the list and close instead of retrying a stale version', () => {
  assert.equal(pages.coverDialogs.match(/isStaleCoverError\(err\)/g)?.length, 2, 'both the End and the Edit dialog handle it');
  assert.equal(pages.coverDialogs.match(/invalidateQueries\(\{ queryKey: \['temporary-replacements'\] \}\)/g)?.length, 2);
  assert.ok(pages.coverDialogs.includes("t('notify.uiAuditB.coverChangedReloaded')"));
});

test('B-4: End Coverage asks the preview the same question the save asks', () => {
  assert.match(pages.coverDialogs, /changePreview\(replacement\.id, \{ end_date: lastDay, for: 'end' \}\)/);
});

test('UG-3: the New Temporary Replacement form asks the server what saving would do and sends the written reason when it is needed', () => {
  assert.match(pages.covers, /hrApi\.temporaryReplacements\.createPreview\(/);
  assert.match(pages.covers, /correction_reason: needsReason \? closedReason\.trim\(\) : undefined/);
  assert.match(pages.covers, /disabled=\{!canCreateCover\(createState\)\}/);
  assert.match(pages.covers, /isCorrectionReasonAsked\(err\)/);
});

test('B-6: no screen of this package defaults a date from the UTC day (the day in Mogadishu starts three hours earlier)', () => {
  for (const [name, source] of Object.entries(pages)) {
    assert.ok(!/toISOString\(\)\.slice\(0, 10\)/.test(source), `${name} must use localToday()`);
  }
  assert.ok(pages.attendance.includes('useState(localToday())'));
  assert.ok(pages.requests.includes('useState(localToday())'));
  assert.ok(pages.training.includes('useState(localToday())'));
  assert.ok(pages.practical.includes('useState(localToday())'));
});

test('B-7: cancelling a workforce request is confirmed first; B-8: so is completing a training batch', () => {
  assert.match(pages.requests, /<ConfirmDialog[\s\S]*?cancelMutation\.mutate\(cancelling\.id\)/);
  assert.ok(!/onClick=\{\(\) => cancelMutation\.mutate\(/.test(pages.requests), 'no button cancels on the first click');
  assert.match(pages.training, /<ConfirmDialog[\s\S]*?completeMutation\.mutate\(/);
  assert.ok(!/onClick=\{\(\) => completeMutation\.mutate\(/.test(pages.training), 'no button completes on the first click');
});

test('B-9: the Need Training list is searchable and says when it is cut short', () => {
  assert.match(pages.training, /search: debouncedNeedSearch \|\| undefined/);
  assert.ok(pages.training.includes('need-training-truncated'));
  assert.ok(pages.training.includes('participantOptionLabel(e)'));
});

test('B-10: the screens show the server\'s own sentence, and a candidate who cannot be matched has a disabled button', () => {
  for (const name of ['requests', 'training', 'practical', 'attendance'] as const) {
    assert.ok(!/err instanceof Error \? err\.message/.test(pages[name]), `${name} must use friendlyErrorMessage`);
  }
  assert.ok(pages.requests.includes('candidate.eligible === false'));
});

test('B-12: the History and Leave report date range cannot be turned round; the History badge follows the lifecycle', () => {
  for (const source of [pages.history, pages.leave]) {
    assert.ok(source.includes('max={to || undefined}'));
    assert.ok(source.includes('min={from || undefined}'));
  }
  assert.ok(pages.history.includes('lifecycleWord(row.original)'));
});

test('B-13: the Attendance roster row wraps on a narrow screen so the name and the three mark buttons all stay visible', () => {
  assert.ok(pages.attendance.includes('flex flex-wrap items-center justify-between'));
  assert.ok(!pages.attendance.includes('shrink-0 items-center gap-2'));
});

test('B-11: saving a cover refreshes the dashboard and the employee lists it can change', () => {
  const created = pages.covers.slice(pages.covers.indexOf('onCreated={() => {'));

  for (const key of ['temporary-replacements', 'hr-dashboard', 'employees', 'employee']) {
    assert.ok(created.slice(0, 700).includes(`queryKey: ['${key}']`), key);
  }
});
