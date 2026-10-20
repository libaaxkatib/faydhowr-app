// Pure helpers for the Excel Imports screen: no React, no network and no `@/` alias imports, so they can be
// unit-tested directly by Node's native test runner (hrImports.test.ts).

import type { ImportBatch, ImportBatchStatus, ImportRowStatus, ImportType } from '../types/hrImport.ts';

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
    label: 'Companies',
    summary: 'Registers client companies and their other spellings. A company that already exists is skipped, never changed.',
    required: ['name', 'category (company or guri)'],
    optional: ['aliases (other spellings separated by |)'],
    previewOnly: false,
  },
  {
    value: 'employee_registrations',
    label: 'New employee registrations',
    summary: 'Registers NEW people at the Application stage. An employee who already exists is skipped, never updated.',
    required: ['full_name', 'phone (registration phone)', 'gender', 'location', 'category', 'application_date'],
    optional: ['alternate_phone', 'payment_account_number (never used for matching)', 'age', 'marital_status', 'lives_with', 'reference_name', 'secondary_contact_name', 'secondary_contact_phone', 'department', 'position', 'experience', 'source', 'notes'],
    previewOnly: false,
  },
  {
    value: 'waiting_list',
    label: 'Waiting list',
    summary: 'Moves EXISTING employees to Waiting when their name and registration phone both match. Anything unsure goes to manual review. Nobody is created or changed otherwise.',
    required: ['full_name', 'phone (registration phone)'],
    optional: ['notes'],
    previewOnly: false,
  },
  {
    value: 'activation_preview',
    label: 'Activation preview (read-only)',
    summary: 'Checks the approved employee plan and shows how each person would become Active. It never activates anyone.',
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
  if (!['xlsx', 'csv'].includes(extension)) return 'Choose an .xlsx or .csv file.';
  if (file.size <= 0) return 'The file is empty.';
  if (file.size > MAX_IMPORT_BYTES) return 'The file is larger than 5 MB.';
  return null;
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export const ROW_STATUS: Record<ImportRowStatus, { label: string; tone: Tone }> = {
  valid: { label: 'Ready', tone: 'success' },
  invalid: { label: 'Invalid', tone: 'danger' },
  duplicate: { label: 'Already exists', tone: 'neutral' },
  review: { label: 'Needs review', tone: 'warning' },
  imported: { label: 'Imported', tone: 'success' },
  skipped: { label: 'Skipped', tone: 'neutral' },
  failed: { label: 'Failed', tone: 'danger' },
};

export const BATCH_STATUS: Record<ImportBatchStatus, { label: string; tone: Tone }> = {
  staged: { label: 'Waiting for confirmation', tone: 'warning' },
  processing: { label: 'Importing', tone: 'info' },
  completed: { label: 'Completed', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
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

const ERROR_TEXT: Record<string, string> = {
  FILE_TYPE_NOT_ALLOWED: 'Only .xlsx and .csv files can be imported.',
  FILE_CONTENT_MISMATCH: 'This file is not what its name says. Save the sheet again as .xlsx or .csv.',
  FILE_TOO_LARGE: 'The file is larger than 5 MB. Split it into smaller files.',
  FILE_EMPTY: 'The file has no data rows.',
  FILE_UNREADABLE: 'The file could not be read. Save it again as .xlsx or .csv and retry.',
  HEADER_NOT_FOUND: 'No header row was found. The first row must name the columns.',
  MISSING_COLUMNS: 'The file is missing a required column.',
  DUPLICATE_COLUMN: 'A column appears more than once in the header row.',
  TOO_MANY_ROWS: 'The file has too many rows. Split it into smaller files (2,000 rows at most).',
  DUPLICATE_IMPORT: 'This exact file was already uploaded. Open its existing import instead.',
  IMPORT_BUSY: 'This import is already being processed. Wait a moment.',
  IMPORT_NOT_CONFIRMED: 'Confirm the import first.',
  IMPORT_CANCELLED: 'This import was cancelled.',
  IMPORT_EXPIRED: 'The details of this import were deleted. Upload the file again.',
  PREVIEW_ONLY: 'This is a read-only preview. It cannot be confirmed.',
  FORBIDDEN: 'Imports are reserved for a Super Admin or an HR Manager who holds the Import HR Data permission.',
};

/** Plain-language text for an API error code; falls back to the server message. */
export function describeImportError(code: string | undefined, fallback: string): string {
  return (code && ERROR_TEXT[code]) || fallback;
}

/** Plain-language names for the row codes shown in the preview and the report. */
export const ROW_CODE_TEXT: Record<string, string> = {
  NAME_REQUIRED: 'Name missing',
  PHONE_INVALID: 'Phone is not a full number',
  GENDER_INVALID: 'Gender must be male or female',
  LOCATION_INVALID: 'Location missing',
  CATEGORY_INVALID: 'Category must be company or guri',
  CATEGORY_UNKNOWN: 'Unknown employee category',
  DATE_INVALID: 'Date not understood',
  DATE_IN_FUTURE: 'Date is in the future',
  COMPANY_EXISTS: 'Company already exists',
  ALIAS_EXISTS: 'Already a known spelling of a company',
  EMPLOYEE_EXISTS: 'Employee already registered',
  DUPLICATE_IN_FILE: 'Repeated earlier in the file',
  AMBIGUOUS_MATCH: 'Matches more than one company',
  ALIAS_BELONGS_TO_OTHER_COMPANY: 'Spelling belongs to another company',
  ALIAS_SHARED_IN_FILE: 'Spelling shared with another row',
  PHONE_SHARED_DIFFERENT_NAME: 'Phone belongs to someone with another name',
  SAME_NAME_DIFFERENT_PHONE: 'Same name, different phone',
  NAME_CONFLICT: 'Phone matches, name differs',
  NAME_ONLY_MATCH: 'Name matches, phone differs',
  NO_MATCH: 'No matching employee',
  MULTIPLE_PHONE_MATCHES: 'Several employees share this phone',
  ALTERNATE_PHONE_ONLY_MATCH: 'Only matches an alternate phone',
  ARCHIVED_MATCH: 'Only matches an archived record',
  ALREADY_WAITING: 'Already on the waiting list',
  TRANSITION_NOT_ALLOWED: 'Status cannot move to Waiting',
  REGISTRATION_WORKFLOW_INCOMPLETE: 'New registration - the guarantor, contract, uniform, training and practical steps are not complete',
  ALREADY_ACTIVE: 'Already Active',
  IDENTITY_MISMATCH: 'Employee number or name does not match the plan',
  EMPLOYEE_NOT_FOUND: 'Employee not found',
  EMPLOYEE_ARCHIVED: 'Employee is archived',
  STATUS_CHANGED_SINCE_PLAN: 'Status changed since the plan was made',
  EMPLOYEE_NUMBER_CONFLICT: 'Plan number belongs to a different employee record',
  SALARY_OR_START_MISSING: 'Salary or start date missing',
  RETENTION_EXPIRED: 'Not imported before the details were deleted',
  MATCH_CHANGED: 'Employee changed after the preview',
  MATCH_LOST: 'Matched employee no longer exists',
  NEEDS_REVIEW_SINCE_PREVIEW: 'A similar employee appeared after the preview',
  IMPORTED: 'Imported',
  UNEXPECTED_ERROR: 'Unexpected error (rolled back)',
};

export function rowCodeText(code: string | null): string {
  if (!code) return '';
  return ROW_CODE_TEXT[code] ?? code;
}

export const ACTIVATION_PATH_TEXT: Record<string, string> = {
  normal_step: 'Normal step (Waiting to Active)',
  rehire: 'Rehire (Inactive to Active)',
  super_admin_override_required: 'Needs Super Admin override with a reason',
  already_active: 'Already Active',
};
