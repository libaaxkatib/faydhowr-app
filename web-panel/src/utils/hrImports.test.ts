import test from 'node:test';
import assert from 'node:assert/strict';

import {
  ACTIVATION_PATH_TEXT,
  batchStage,
  canCancel,
  canConfirm,
  checkImportFile,
  daysUntilDeletion,
  describeImportError,
  IMPORT_TYPES,
  importTypeInfo,
  progressPercent,
  ROW_STATUS,
  rowCodeText,
} from './hrImports.ts';
import type { ImportCounts } from '../types/hrImport.ts';

/**
 * Run with: node --test src/utils/hrImports.test.ts
 * Covers the pure logic behind the Excel Imports screen: file pre-checks, which actions a batch offers,
 * progress, the retention countdown and plain-language messages.
 */

const counts = (over: Partial<ImportCounts> = {}): ImportCounts => ({ total: 10, valid: 8, invalid: 1, duplicate: 1, review: 0, imported: 0, skipped: 0, failed: 0, ...over });

test('only .xlsx and .csv files up to 5 MB pass the pre-check', () => {
  assert.equal(checkImportFile({ name: 'plan.xlsx', size: 1000 }), null);
  assert.equal(checkImportFile({ name: 'PLAN.CSV', size: 1000 }), null);
  assert.match(checkImportFile({ name: 'plan.xls', size: 1000 }) ?? '', /\.xlsx or \.csv/);
  assert.match(checkImportFile({ name: 'plan.pdf', size: 1000 }) ?? '', /\.xlsx or \.csv/);
  assert.match(checkImportFile({ name: 'plan', size: 1000 }) ?? '', /\.xlsx or \.csv/);
  assert.match(checkImportFile({ name: 'plan.xlsx', size: 0 }) ?? '', /empty/);
  assert.match(checkImportFile({ name: 'plan.xlsx', size: 5 * 1024 * 1024 + 1 }) ?? '', /5 MB/);
});

test('there are exactly four import types and only the activation preview is read-only', () => {
  assert.deepEqual(IMPORT_TYPES.map((t) => t.value), ['companies', 'employee_registrations', 'waiting_list', 'activation_preview']);
  assert.deepEqual(IMPORT_TYPES.filter((t) => t.previewOnly).map((t) => t.value), ['activation_preview']);
  assert.equal(importTypeInfo('waiting_list').label, 'Waiting list');
});

test('the registration import requires the registration fields and offers the payment account only as optional', () => {
  const info = importTypeInfo('employee_registrations');
  assert.equal(info.required.length, 6);
  assert.ok(info.required.join(' ').includes('application_date'));
  assert.ok(!info.required.join(' ').toLowerCase().includes('account'), 'the payment account is never required');
  assert.ok(info.optional.join(' ').includes('payment_account_number'));
  assert.match(info.optional.join(' '), /never used for matching/);
});

test('the waiting list and the activation preview never take a payment account', () => {
  for (const type of ['waiting_list', 'activation_preview'] as const) {
    const info = importTypeInfo(type);
    assert.ok(![...info.required, ...info.optional].join(' ').toLowerCase().includes('account'));
  }
});

test('a batch offers the right actions for its stage', () => {
  assert.equal(batchStage({ status: 'staged', preview_only: false }), 'review');
  assert.equal(batchStage({ status: 'processing', preview_only: false }), 'importing');
  assert.equal(batchStage({ status: 'completed', preview_only: false }), 'finished');
  assert.equal(batchStage({ status: 'cancelled', preview_only: false }), 'cancelled');
  assert.equal(batchStage({ status: 'staged', preview_only: true }), 'preview');
});

test('confirming is offered only for a staged, importable, non-preview batch that still has its details', () => {
  const base = { status: 'staged' as const, preview_only: false, details_deleted: false, counts: counts() };
  assert.equal(canConfirm(base), true);
  assert.equal(canConfirm({ ...base, preview_only: true }), false, 'a preview can never be confirmed');
  assert.equal(canConfirm({ ...base, status: 'processing' }), false);
  assert.equal(canConfirm({ ...base, status: 'completed' }), false);
  assert.equal(canConfirm({ ...base, details_deleted: true }), false);
  assert.equal(canConfirm({ ...base, counts: counts({ valid: 0 }) }), false, 'nothing to import');
});

test('only an unconfirmed batch can be cancelled', () => {
  assert.equal(canCancel({ status: 'staged', details_deleted: false }), true);
  assert.equal(canCancel({ status: 'processing', details_deleted: false }), false);
  assert.equal(canCancel({ status: 'completed', details_deleted: false }), false);
});

test('progress is the share of ready rows already handled', () => {
  assert.equal(progressPercent({ status: 'processing', counts: counts({ valid: 200, imported: 100 }) }), 50);
  assert.equal(progressPercent({ status: 'processing', counts: counts({ valid: 200, imported: 90, skipped: 5, failed: 5 }) }), 50);
  assert.equal(progressPercent({ status: 'processing', counts: counts({ valid: 0 }) }), 0);
  assert.equal(progressPercent({ status: 'completed', counts: counts({ valid: 200, imported: 100 }) }), 100);
  assert.equal(progressPercent({ status: 'processing', counts: counts({ valid: 10, imported: 50 }) }), 100, 'never above 100');
});

test('the retention countdown is in whole days and never negative', () => {
  const now = new Date('2026-10-12T08:00:00Z');
  assert.equal(daysUntilDeletion('2026-11-11T08:00:00Z', now), 30);
  assert.equal(daysUntilDeletion('2026-10-13T07:00:00Z', now), 1);
  assert.equal(daysUntilDeletion('2026-10-01T08:00:00Z', now), 0);
  assert.equal(daysUntilDeletion(null, now), null);
  assert.equal(daysUntilDeletion('not a date', now), null);
});

test('error codes become plain language and unknown codes keep the server message', () => {
  assert.match(describeImportError('DUPLICATE_IMPORT', 'x'), /already uploaded/);
  assert.match(describeImportError('PREVIEW_ONLY', 'x'), /read-only preview/);
  assert.equal(describeImportError('SOMETHING_NEW', 'Server said this'), 'Server said this');
  assert.equal(describeImportError(undefined, 'Fallback'), 'Fallback');
});

test('every row status has a label and a tone, and review is a warning, not a failure', () => {
  for (const status of ['valid', 'invalid', 'duplicate', 'review', 'imported', 'skipped', 'failed'] as const) {
    assert.ok(ROW_STATUS[status].label.length > 0);
  }
  assert.equal(ROW_STATUS.review.tone, 'warning');
  assert.equal(ROW_STATUS.duplicate.tone, 'neutral');
});

test('row codes read as sentences and unknown codes are shown as-is', () => {
  assert.equal(rowCodeText('NAME_CONFLICT'), 'Phone matches, name differs');
  assert.equal(rowCodeText('NO_MATCH'), 'No matching employee');
  assert.equal(rowCodeText('BRAND_NEW_CODE'), 'BRAND_NEW_CODE');
  assert.equal(rowCodeText(null), '');
});

test('the activation paths are described, including the override', () => {
  assert.match(ACTIVATION_PATH_TEXT.super_admin_override_required, /Super Admin override/);
  assert.match(ACTIVATION_PATH_TEXT.rehire, /Rehire/);
});
