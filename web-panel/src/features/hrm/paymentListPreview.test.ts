import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../../i18n/messages/index.ts';
import { placeholdersOf } from '../../i18n/catalog.ts';
import { cellClasses, downloadQuery } from '../../utils/paymentListFilter.ts';

/**
 * The full workbook preview shown before the payment-list Excel is downloaded. The workbook itself, its cells and the hash are tested against the real
 * Excel file in MonthlyPaymentWorkbookPreviewTest (backend); these checks cover what the panel decides: that EVERY list is previewed first, what it asks
 * for, that all worksheets, rows and columns are shown without a cut-off, that the download names the preview it follows, that a changed list is
 * handled, that every word is in the language catalogue (Somali and English) and that it uses only theme colours (so Light and Dark both work).
 */

const dialog = readFileSync(new URL('./PaymentListPreviewDialog.tsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('./PayrollRunsPage.tsx', import.meta.url), 'utf8');
const api = readFileSync(new URL('../../api/hr.ts', import.meta.url), 'utf8');

const KEYS = [
  'confirm.payroll.listPreviewTitle', 'option.payroll.previewMonth', 'option.payroll.previewRows', 'option.payroll.previewPermanentTotal',
  'option.payroll.previewTemporaryTotal', 'option.payroll.previewCombinedTotal', 'option.payroll.previewMissingNumbers', 'option.payroll.loadingPreview',
  'warning.payroll.previewOnly', 'warning.payroll.previewEstimate', 'warning.payroll.previewMissingNumbers', 'warning.payroll.previewNoRecords',
  'notify.payroll.previewFailed', 'option.payroll.previewSheets', 'option.payroll.previewSheetSize', 'option.payroll.previewFreezeNote',
  'warning.payroll.previewChanged',
] as const;

test('every list is previewed before it is downloaded: all records, one company and one guri', () => {
  assert.match(page, /onClick=\{openPaymentListPreview\}/, 'the button opens the preview');
  assert.ok(!/needsPreview/.test(page), 'there is no scope that skips the preview');
  assert.ok(!/startPaymentListDownload\(\)/.test(page), 'the download is never started without a preview');
  assert.match(page, /isOpen=\{isListPreviewOpen && \(listScope === 'all' \|\| listSelected !== null\)\}/);
  assert.match(dialog, /enabled: isOpen && \(scope === 'all' \|\| selected !== null\)/);
});

test('the preview asks for the same month, scope and record id as the download, never a name', () => {
  assert.match(api, /monthly-payment-list\/workbook-preview/);
  assert.match(api, /paymentListWorkbookPreview[\s\S]{0,320}query: downloadQuery\(period, scope, selected \? String\(selected\.id\) : ''\)/);
  assert.deepEqual(downloadQuery('2026-10', 'company', '3'), { period: '2026-10', scope: 'company', client_company_id: '3' });
  assert.deepEqual(downloadQuery('2026-10', 'all', ''), { period: '2026-10', scope: 'all' });
});

test('the Excel is only requested after the person confirms, for exactly the preview that was shown', () => {
  assert.match(page, /onConfirm=\{startPaymentListDownload\}/);
  assert.match(dialog, /onConfirm\(\{ content_hash: data\.content_hash, generated_at: data\.generated_at \}\)/, 'the download names the preview by its hash and stamp');
  assert.match(api, /preview_hash: previewed\.content_hash, generated_at: previewed\.generated_at/);
  assert.match(page, /hrApi\.payrollRuns\.downloadPaymentList\(period, listScope, listSelected, previewed\)/);
  assert.match(page, /if \(paymentListLock\.current\) return;\s+paymentListLock\.current = true;/, 'one request at a time, even for two clicks in the same instant');
  assert.match(page, /onSettled: \(\) => \{\s+paymentListLock\.current = false;/);
  assert.equal([...page.matchAll(/paymentListMutation\.mutate\(/g)].length, 1, 'the download is started from one place only');
});

test('the dialog cannot be closed or confirmed twice while it downloads, and cannot be confirmed without a current preview', () => {
  assert.match(dialog, /isLoading=\{isDownloading\}/);
  assert.match(dialog, /onClose=\{isDownloading \? \(\) => undefined : onClose\}/);
  assert.match(dialog, /disabled=\{isDownloading\}/, 'Cancel is disabled while downloading');
  assert.match(dialog, /const canConfirm = data !== undefined && !error && !isFetching && !isDownloading/);
  assert.match(dialog, /disabled=\{!canConfirm\}/);
  assert.match(dialog, /gcTime: 0/, 'a preview is never reused from an earlier opening');
});

test('loading, empty and error states exist, and an empty result cannot be confirmed', () => {
  assert.match(dialog, /isLoading && <LoadingState/);
  assert.match(dialog, /error\.code === 'NO_PAYMENT_RECORDS'/);
  assert.match(dialog, /friendlyErrorMessage\(error, t\('notify\.payroll\.previewFailed'\)\)/);
  assert.match(dialog, /t\('action\.common\.tryAgain'\)/, 'a failed preview can be retried');
});

test('a list that changed after the preview is refused by the server, the preview is fetched again and the person is told', () => {
  assert.match(page, /err\.code === 'PREVIEW_OUT_OF_DATE'/);
  assert.match(page, /setListChangedAfterPreview\(true\);\s+void queryClient\.invalidateQueries\(\{ queryKey: \['payment-list-preview'\] \}\);/);
  assert.match(page, /changedAfterPreview=\{listChangedAfterPreview\}/);
  assert.match(dialog, /changedAfterPreview && \([\s\S]{0,200}warning\.payroll\.previewChanged/);
});

test('every worksheet is reachable and every row and column of it is shown, with no cut-off', () => {
  assert.match(dialog, /role="tablist"/);
  assert.match(dialog, /role="tab"/);
  assert.match(dialog, /sheets\.map\(\(sheet, position\)/, 'one tab per worksheet of the workbook, in its order');
  assert.match(dialog, /ArrowRight/);
  assert.match(dialog, /ArrowLeft/);
  assert.match(dialog, /sheet\.rows\.map\(\(row, rowIndex\)/, 'every row');
  assert.match(dialog, /Array\.from\(\{ length: sheet\.column_count \}/, 'every column');
  const table = dialog.slice(dialog.indexOf('function SheetTable'));
  assert.ok(!/\.slice\(|\.filter\(|MAX_ROWS|LIMIT/.test(table), 'the table shows all rows: no slice, filter or row limit');
  assert.match(dialog, /sticky top-0/, 'the header row of the table stays in view while scrolling');
});

test('the workbook\'s highlights are shown as theme colours, never as the file\'s raw colours', () => {
  assert.match(cellClasses({ fill: '1F3864' }), /bg-primary text-white/);
  assert.match(cellClasses({ fill: 'FFF2CC' }), /bg-warning-soft/, 'a missing payment number');
  assert.match(cellClasses({ fill: 'EAF1DD' }), /bg-success-soft/, 'a Temporary row');
  assert.match(cellClasses({ fill: 'ABCDEF' }), /bg-surface-muted/, 'any other highlight');
  assert.match(cellClasses({ color: 'C00000', bold: true }), /text-danger font-semibold/, 'MISSING in red and bold');
  assert.match(cellClasses({ number: true }), /text-right/, 'numbers are right aligned');
  assert.match(cellClasses({ indent: 1 }), /pl-5/);
  assert.equal(cellClasses(undefined), '');
  assert.equal(cellClasses({}), '');
  for (const style of [{ fill: '1F3864' }, { fill: 'FFF2CC' }, { fill: 'EAF1DD' }, { color: 'C00000' }]) assert.ok(!/#|rgb|[0-9A-F]{6}/.test(cellClasses(style)), 'no raw colour code');
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
  assert.deepEqual(placeholdersOf(CATALOGUE['option.payroll.previewSheetSize'].en), ['columns', 'rows']);
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
  assert.ok(!/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\b(?:bg|text|border)-(?:gray|red|green|blue|yellow|amber|white|black|slate)/.test(dialog.replace(/text-white/g, '')));
  for (const token of ['text-ink', 'text-ink-muted', 'bg-warning-soft', 'border-border']) assert.ok(dialog.includes(token), token);
});

test('amounts are shown from the server\'s decimal strings and are never added up in the panel', () => {
  assert.match(dialog, /amount\(data\.permanent_total\)/);
  assert.match(dialog, /amount\(data\.temporary_total\)/);
  assert.match(dialog, /amount\(data\.combined_total\)/);
  assert.ok(!/permanent_total\)\s*\+|\+\s*Number\(data/.test(dialog), 'no arithmetic on the totals');
});
