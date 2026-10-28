import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../../i18n/messages/index.ts';
import { placeholdersOf } from '../../i18n/catalog.ts';
import { downloadQuery, needsPreview } from '../../utils/paymentListFilter.ts';

/**
 * The check shown before the payment list is downloaded for one company or guri. The figures come from the server (tested against the real Excel
 * in MonthlyPaymentListPreviewTest); these checks cover what the panel decides: when the preview appears, what it asks for, that every word is in
 * the language catalogue (Somali and English) and that it uses only theme colours (so Light and Dark both work).
 */

const dialog = readFileSync(new URL('./PaymentListPreviewDialog.tsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('./PayrollRunsPage.tsx', import.meta.url), 'utf8');
const api = readFileSync(new URL('../../api/hr.ts', import.meta.url), 'utf8');

const KEYS = [
  'confirm.payroll.listPreviewTitle', 'option.payroll.previewMonth', 'option.payroll.previewRows', 'option.payroll.previewPermanentTotal',
  'option.payroll.previewTemporaryTotal', 'option.payroll.previewCombinedTotal', 'option.payroll.previewMissingNumbers', 'option.payroll.loadingPreview',
  'warning.payroll.previewOnly', 'warning.payroll.previewEstimate', 'warning.payroll.previewMissingNumbers', 'warning.payroll.previewNoRecords',
  'notify.payroll.previewFailed',
] as const;

test('a preview is shown for one company or guri; All keeps downloading directly', () => {
  assert.equal(needsPreview('company'), true);
  assert.equal(needsPreview('guri'), true);
  assert.equal(needsPreview('all'), false);
  assert.match(page, /needsPreview\(listScope\) \? setIsListPreviewOpen\(true\) : startPaymentListDownload\(\)/);
});

test('the preview asks for the same month, scope and record id as the download, never a name', () => {
  assert.match(api, /monthly-payment-list\/preview/);
  assert.match(api, /paymentListPreview[\s\S]{0,260}query: downloadQuery\(period, scope, selected \? String\(selected\.id\) : ''\)/);
  assert.deepEqual(downloadQuery('2026-10', 'company', '3'), { period: '2026-10', scope: 'company', client_company_id: '3' });
});

test('the Excel is only requested after the person confirms, and the dialog cannot be closed or confirmed twice while it downloads', () => {
  assert.match(page, /onConfirm=\{startPaymentListDownload\}/);
  assert.match(page, /if \(paymentListLock\.current\) return;\s+paymentListLock\.current = true;\s+paymentListMutation\.mutate\(\);/, 'one request at a time, even for two clicks in the same instant');
  assert.match(page, /onSettled: \(\) => \{\s+paymentListLock\.current = false;/, 'the lock is released when the download ends, successfully or not');
  assert.equal([...page.matchAll(/paymentListMutation\.mutate\(\)/g)].length, 1, 'the download is started from one place only');
  assert.match(dialog, /isLoading=\{isDownloading\}/);
  assert.match(dialog, /onClose=\{isDownloading \? \(\) => undefined : onClose\}/);
  assert.match(dialog, /disabled=\{isDownloading\}/, 'Cancel is disabled while downloading');
  assert.match(dialog, /const canConfirm = data !== undefined && !error && !isFetching && !isDownloading/);
  assert.match(dialog, /gcTime: 0/, 'a preview is never reused from an earlier opening');
});

test('loading, empty and error states exist, and an empty result cannot be confirmed', () => {
  assert.match(dialog, /isLoading && <LoadingState/);
  assert.match(dialog, /error\.code === 'NO_PAYMENT_RECORDS'/);
  assert.match(dialog, /friendlyErrorMessage\(error, t\('notify\.payroll\.previewFailed'\)\)/);
  assert.match(dialog, /t\('action\.common\.tryAgain'\)/, 'a failed preview can be retried');
  assert.match(dialog, /disabled=\{!canConfirm\}/);
});

test('every word of the preview is a catalogue message with English and Somali, written as a proposal', () => {
  for (const key of KEYS) {
    const entry = CATALOGUE[key];
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.notEqual(entry.en, entry.so, `${key}: Somali differs from English`);
    assert.equal(entry.review, 'proposed', `${key}: new wording is a proposal, not owner-approved`);
    assert.deepEqual(placeholdersOf(entry.en), placeholdersOf(entry.so), `${key}: the same placeholders in both languages`);
    assert.ok(dialog.includes(`'${key}'`) || page.includes(`'${key}'`), `${key} is used`);
  }
  assert.deepEqual(placeholdersOf(CATALOGUE['warning.payroll.previewNoRecords'].en), ['month', 'name']);
});

test('the preview reuses the approved Cancel and the existing Download Excel wording instead of adding new ones', () => {
  assert.match(dialog, /t\('action\.cancel'\)/);
  assert.match(dialog, /t\('action\.payroll\.downloadExcel'\)/);
  assert.equal(CATALOGUE['action.cancel'].review, 'approved');
});

test('the dialog writes no English text and no colour of its own (theme tokens only, so Light and Dark both work)', () => {
  const jsxText = [...dialog.matchAll(/>\s*([A-Z][A-Za-z ,.'-]{3,})\s*</g)].map((match) => match[1]);
  assert.deepEqual(jsxText, [], 'no English words between tags');
  assert.ok(!/aria-label="[A-Za-z]/.test(dialog) && !/title="[A-Za-z]/.test(dialog));
  assert.ok(!/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\b(?:bg|text|border)-(?:gray|red|green|blue|yellow|amber|white|black|slate)/.test(dialog));
  for (const token of ['text-ink', 'text-ink-muted', 'bg-warning-soft', 'border-border']) assert.ok(dialog.includes(token), token);
});

test('amounts are shown from the server\'s decimal strings and are never added up in the panel', () => {
  assert.match(dialog, /amount\(data\.permanent_total\)/);
  assert.match(dialog, /amount\(data\.temporary_total\)/);
  assert.match(dialog, /amount\(data\.combined_total\)/);
  assert.ok(!/permanent_total\)\s*\+|\+\s*Number\(data/.test(dialog), 'no arithmetic on the totals');
});
