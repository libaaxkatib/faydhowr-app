// Pure helpers for the Excel Imports screen: no React, no network and no `@/` alias imports, so they can be
// unit-tested directly by Node's native test runner (hrImports.test.ts).

import { tNow } from '../i18n/active.ts';
import type { MessageKey } from '../i18n/messages/index.ts';
import type { ImportBatch, ImportBatchStatus, ImportRowStatus, ImportType } from '../types/hrImport.ts';

/** A code-to-text table whose texts are read when asked for, so they follow the language the screen is showing. */
function translatedTable(keys: Record<string, MessageKey>): Record<string, string> {
  const table: Record<string, string> = {};
  for (const [code, key] of Object.entries(keys)) Object.defineProperty(table, code, { get: () => tNow(key), enumerable: true });

  return table;
}

export interface ImportTypeInfo {
  value: ImportType;
  label: string;
  summary: string;
  /** Column headers the file needs (the first row of the sheet). */
  required: string[];
  optional: string[];
  /** A preview can never be confirmed or change data. */
  previewOnly: boolean;
}

export const IMPORT_TYPES: ImportTypeInfo[] = [
  {
    value: 'companies',
    get label() {
      return tNow('option.imports.typeCompanies');
    },
    get summary() {
      return tNow('warning.imports.summaryCompanies');
    },
    required: ['name', 'category (company or guri)'],
    optional: ['aliases (other spellings separated by |)'],
    previewOnly: false,
  },
  {
    value: 'employee_registrations',
    get label() {
      return tNow('option.imports.typeEmployeeRegistrations');
    },
    get summary() {
      return tNow('warning.imports.summaryEmployeeRegistrations');
    },
    required: ['full_name', 'phone (registration phone)', 'gender', 'location', 'category', 'application_date'],
    optional: ['alternate_phone', 'payment_account_number (never used for matching)', 'age', 'marital_status', 'lives_with', 'reference_name', 'secondary_contact_name', 'secondary_contact_phone', 'department', 'position', 'experience', 'source', 'notes'],
    previewOnly: false,
  },
  {
    value: 'waiting_list',
    get label() {
      return tNow('option.imports.typeWaitingList');
    },
    get summary() {
      return tNow('warning.imports.summaryWaitingList');
    },
    required: ['full_name', 'phone (registration phone)'],
    optional: ['notes'],
    previewOnly: false,
  },
  {
    value: 'activation_preview',
    get label() {
      return tNow('option.imports.typeActivationPreview');
    },
    get summary() {
      return tNow('warning.imports.summaryActivationPreview');
    },
    required: ['employee_id', 'employee_number', 'name_key'],
    optional: ['source_row', 'expected_status', 'company', 'salary', 'start_date'],
    previewOnly: true,
  },
];

export function importTypeInfo(type: ImportType): ImportTypeInfo {
  return IMPORT_TYPES.find((t) => t.value === type) ?? IMPORT_TYPES[0];
}

export const MAX_IMPORT_BYTES = 5 * 1024 * 1024;

/** Quick client-side check before uploading; the server re-checks the real file content. */
export function checkImportFile(file: { name: string; size: number }): string | null {
  const extension = file.name.toLowerCase().split('.').pop() ?? '';
  if (!['xlsx', 'csv'].includes(extension)) return tNow('validation.imports.fileType');
  if (file.size <= 0) return tNow('validation.imports.fileEmpty');
  if (file.size > MAX_IMPORT_BYTES) return tNow('validation.imports.fileLarge');
  return null;
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export const ROW_STATUS: Record<ImportRowStatus, { label: string; tone: Tone }> = {
  valid: {
    get label() {
      return tNow('option.importRow.valid');
    },
    tone: 'success',
  },
  invalid: {
    get label() {
      return tNow('option.importRow.invalid');
    },
    tone: 'danger',
  },
  duplicate: {
    get label() {
      return tNow('option.importRow.duplicateRow');
    },
    tone: 'neutral',
  },
  review: {
    get label() {
      return tNow('option.importRow.review');
    },
    tone: 'warning',
  },
  imported: {
    get label() {
      return tNow('option.importRow.imported');
    },
    tone: 'success',
  },
  skipped: {
    get label() {
      return tNow('option.importRow.skipped');
    },
    tone: 'neutral',
  },
  failed: {
    get label() {
      return tNow('option.importRow.failed');
    },
    tone: 'danger',
  },
};

export const BATCH_STATUS: Record<ImportBatchStatus, { label: string; tone: Tone }> = {
  staged: {
    get label() {
      return tNow('status.imports.staged');
    },
    tone: 'warning',
  },
  processing: {
    get label() {
      return tNow('status.imports.processing');
    },
    tone: 'info',
  },
  completed: {
    get label() {
      return tNow('status.imports.completed');
    },
    tone: 'success',
  },
  cancelled: {
    get label() {
      return tNow('status.imports.cancelled');
    },
    tone: 'neutral',
  },
};

export type BatchStage = 'review' | 'importing' | 'finished' | 'preview' | 'cancelled';

/** Which part of the workflow a batch is in, which decides the actions the screen offers. */
export function batchStage(batch: Pick<ImportBatch, 'status' | 'preview_only'>): BatchStage {
  if (batch.status === 'cancelled') return 'cancelled';
  if (batch.preview_only) return 'preview';
  if (batch.status === 'staged') return 'review';
  if (batch.status === 'processing') return 'importing';
  return 'finished';
}

export function canConfirm(batch: Pick<ImportBatch, 'status' | 'preview_only' | 'details_deleted' | 'counts'>): boolean {
  return batch.status === 'staged' && !batch.preview_only && !batch.details_deleted && batch.counts.valid > 0;
}

export function canCancel(batch: Pick<ImportBatch, 'status' | 'details_deleted'>): boolean {
  return batch.status === 'staged';
}

/** 0-100 while importing: rows handled so far over the rows that were ready. */
export function progressPercent(batch: Pick<ImportBatch, 'status' | 'counts'>): number {
  if (batch.status === 'completed') return 100;
  const toImport = batch.counts.valid;
  if (toImport <= 0) return batch.status === 'processing' ? 0 : 100;
  const handled = batch.counts.imported + batch.counts.skipped + batch.counts.failed;
  return Math.max(0, Math.min(100, Math.round((handled / toImport) * 100)));
}

/** Whole days left before the personal details of an import are deleted (never negative). */
export function daysUntilDeletion(retentionExpiresAt: string | null, now: Date = new Date()): number | null {
  if (!retentionExpiresAt) return null;
  const ms = new Date(retentionExpiresAt).getTime() - now.getTime();
  if (Number.isNaN(ms)) return null;
  return Math.max(0, Math.ceil(ms / 86_400_000));
}

const ERROR_TEXT = translatedTable({
  FILE_TYPE_NOT_ALLOWED: 'notify.imports.errorFileTypeNotAllowed',
  FILE_CONTENT_MISMATCH: 'notify.imports.errorFileContentMismatch',
  FILE_TOO_LARGE: 'notify.imports.errorFileTooLarge',
  FILE_EMPTY: 'notify.imports.errorFileEmpty',
  FILE_UNREADABLE: 'notify.imports.errorFileUnreadable',
  HEADER_NOT_FOUND: 'notify.imports.errorHeaderNotFound',
  MISSING_COLUMNS: 'notify.imports.errorMissingColumns',
  DUPLICATE_COLUMN: 'notify.imports.errorDuplicateColumn',
  TOO_MANY_ROWS: 'notify.imports.errorTooManyRows',
  DUPLICATE_IMPORT: 'notify.imports.errorDuplicateImport',
  IMPORT_BUSY: 'notify.imports.errorImportBusy',
  IMPORT_NOT_CONFIRMED: 'notify.imports.errorImportNotConfirmed',
  IMPORT_CANCELLED: 'notify.imports.errorImportCancelled',
  IMPORT_EXPIRED: 'notify.imports.errorImportExpired',
  PREVIEW_ONLY: 'notify.imports.errorPreviewOnly',
  FORBIDDEN: 'notify.imports.errorForbidden',
});

/** Plain-language text for an API error code; falls back to the server message. */
export function describeImportError(code: string | undefined, fallback: string): string {
  return (code && ERROR_TEXT[code]) || fallback;
}

/** Plain-language names for the row codes shown in the preview and the report. */
export const ROW_CODE_TEXT = translatedTable({
  NAME_REQUIRED: 'validation.imports.rowNameRequired',
  PHONE_INVALID: 'validation.imports.rowPhoneInvalid',
  GENDER_INVALID: 'validation.imports.rowGenderInvalid',
  LOCATION_INVALID: 'validation.imports.rowLocationInvalid',
  CATEGORY_INVALID: 'validation.imports.rowCategoryInvalid',
  CATEGORY_UNKNOWN: 'validation.imports.rowCategoryUnknown',
  DATE_INVALID: 'validation.imports.rowDateInvalid',
  DATE_IN_FUTURE: 'validation.imports.rowDateInFuture',
  COMPANY_EXISTS: 'validation.imports.rowCompanyExists',
  ALIAS_EXISTS: 'validation.imports.rowAliasExists',
  EMPLOYEE_EXISTS: 'validation.imports.rowEmployeeExists',
  DUPLICATE_IN_FILE: 'validation.imports.rowDuplicateInFile',
  AMBIGUOUS_MATCH: 'validation.imports.rowAmbiguousMatch',
  ALIAS_BELONGS_TO_OTHER_COMPANY: 'validation.imports.rowAliasBelongsToOtherCompany',
  ALIAS_SHARED_IN_FILE: 'validation.imports.rowAliasSharedInFile',
  PHONE_SHARED_DIFFERENT_NAME: 'validation.imports.rowPhoneSharedDifferentName',
  SAME_NAME_DIFFERENT_PHONE: 'validation.imports.rowSameNameDifferentPhone',
  NAME_CONFLICT: 'validation.imports.rowNameConflict',
  NAME_ONLY_MATCH: 'validation.imports.rowNameOnlyMatch',
  NO_MATCH: 'validation.imports.rowNoMatch',
  MULTIPLE_PHONE_MATCHES: 'validation.imports.rowMultiplePhoneMatches',
  ALTERNATE_PHONE_ONLY_MATCH: 'validation.imports.rowAlternatePhoneOnlyMatch',
  ARCHIVED_MATCH: 'validation.imports.rowArchivedMatch',
  ALREADY_WAITING: 'validation.imports.rowAlreadyWaiting',
  TRANSITION_NOT_ALLOWED: 'validation.imports.rowTransitionNotAllowed',
  REGISTRATION_WORKFLOW_INCOMPLETE: 'validation.imports.rowRegistrationWorkflowIncomplete',
  ALREADY_ACTIVE: 'validation.imports.alreadyActive',
  IDENTITY_MISMATCH: 'validation.imports.rowIdentityMismatch',
  EMPLOYEE_NOT_FOUND: 'validation.imports.rowEmployeeNotFound',
  EMPLOYEE_ARCHIVED: 'validation.imports.rowEmployeeArchived',
  STATUS_CHANGED_SINCE_PLAN: 'validation.imports.rowStatusChangedSincePlan',
  EMPLOYEE_NUMBER_CONFLICT: 'validation.imports.rowEmployeeNumberConflict',
  SALARY_OR_START_MISSING: 'validation.imports.rowSalaryOrStartMissing',
  RETENTION_EXPIRED: 'validation.imports.rowRetentionExpired',
  MATCH_CHANGED: 'validation.imports.rowMatchChanged',
  MATCH_LOST: 'validation.imports.rowMatchLost',
  NEEDS_REVIEW_SINCE_PREVIEW: 'validation.imports.rowNeedsReviewSincePreview',
  IMPORTED: 'option.importRow.imported',
  UNEXPECTED_ERROR: 'validation.imports.rowUnexpectedError',
});

export function rowCodeText(code: string | null): string {
  if (!code) return '';
  return ROW_CODE_TEXT[code] ?? code;
}

export const ACTIVATION_PATH_TEXT = translatedTable({
  normal_step: 'option.imports.pathNormalStep',
  rehire: 'option.imports.pathRehire',
  super_admin_override_required: 'option.imports.pathSuperAdminOverride',
  already_active: 'validation.imports.alreadyActive',
});
