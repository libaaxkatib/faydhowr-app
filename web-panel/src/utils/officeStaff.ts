// Pure helpers for the Office Staff registration form (no React, no network, no `@/` imports) so the
// validation, the "would this overwrite something?" check and the payload can be unit-tested by Node's native
// test runner (officeStaff.test.ts). The BACKEND is the real guard; these give quick, clear feedback first.

import { tNow, tValueNow } from '../i18n/active.ts';

export type OfficeStaffMode = 'existing' | 'new';

export interface OfficeStaffDocumentFile {
  id: number;
  file_name: string;
  verification_status: string;
  uploaded_at: string | null;
}

export interface OfficeStaffDocumentGroup {
  key: 'identity' | 'employment' | 'guarantor' | 'other';
  label: string;
  category: string;
  required: boolean;
  status: 'on_file' | 'missing';
  files: OfficeStaffDocumentFile[];
}

/** What the server returns about one employee in the Office Staff workflow. */
export interface OfficeStaffSummary {
  id: number;
  employee_number: string;
  full_name: string;
  phone: string | null;
  status: string;
  employee_category_id: number | null;
  category_name: string | null;
  position_id: number | null;
  position_name: string | null;
  emergency_contact: { name: string | null; phone: string | null };
  guarantor: { name: string; phone: string; relationship: string | null; verified: boolean } | null;
  current_salary: { amount: string; currency: string; frequency: string } | null;
  is_office_staff: boolean;
  active_assignments: { id: number; label: string; salary: string }[];
  can_add: boolean;
  cannot_add_reason: string | null;
  documents: OfficeStaffDocumentGroup[];
}

export interface OfficeStaffForm {
  // new employee only
  full_name: string;
  phone: string;
  // both paths
  position_id: string;
  monthly_salary: string;
  start_date: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  guarantor_name: string;
  guarantor_phone: string;
  guarantor_relationship: string;
}

export type OverwriteField = 'emergency_contact' | 'guarantor' | 'position';

/** Same pattern the server uses: at least six digits, only digits and the usual phone punctuation. */
export const PHONE_PATTERN = /^(?=(?:\D*\d){6,})[0-9+()\-\s.]{6,40}$/;

export function emptyForm(today: string): OfficeStaffForm {
  return {
    full_name: '', phone: '', position_id: '', monthly_salary: '', start_date: today,
    emergency_contact_name: '', emergency_contact_phone: '', guarantor_name: '', guarantor_phone: '', guarantor_relationship: '',
  };
}

/** A plain non-negative amount with at most two decimals ("0", "120", "350.50"). Zero is allowed; blank, text, negatives and 3 decimals are not. */
export function salaryError(value: string): string | null {
  const trimmed = value.trim();
  if (trimmed === '') return tNow('validation.employees.salaryRequired');
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return tNow('validation.employees.salaryInvalid');
  if (Number(trimmed) > 9999999.99) return tNow('validation.employees.salaryTooLarge');

  return null;
}

export function phoneError(value: string): string | null {
  const trimmed = value.trim();
  if (trimmed === '') return tNow('validation.employees.phoneRequired');

  return PHONE_PATTERN.test(trimmed) ? null : tNow('validation.employees.phoneInvalid');
}

const requiredText = (value: string, message: string): string | null => (value.trim() === '' ? message : null);

/** Field -> message for everything that blocks saving. An empty object means the form is complete. */
export function validateOfficeStaffForm(form: OfficeStaffForm, mode: OfficeStaffMode): Record<string, string> {
  const errors: Record<string, string | null> = {
    position_id: form.position_id === '' ? tNow('validation.employees.positionRequired') : null,
    monthly_salary: salaryError(form.monthly_salary),
    start_date: requiredText(form.start_date, tNow('validation.employees.startDateRequired')),
    emergency_contact_name: requiredText(form.emergency_contact_name, tNow('validation.employees.emergencyNameRequired')),
    emergency_contact_phone: phoneError(form.emergency_contact_phone),
    guarantor_name: requiredText(form.guarantor_name, tNow('validation.employees.guarantorNameRequired')),
    guarantor_phone: phoneError(form.guarantor_phone),
  };

  if (mode === 'new') {
    errors.full_name = requiredText(form.full_name, tNow('validation.employees.fullNameRequired'));
    errors.phone = phoneError(form.phone);
  }

  return Object.fromEntries(Object.entries(errors).filter((entry): entry is [string, string] => entry[1] !== null));
}

const normaliseText = (value: string | null | undefined): string => (value ?? '').replace(/\s+/g, ' ').trim().toLowerCase();

/** Last nine digits, the same key the server uses to compare phones ("+252 615 333 444" equals "0615333444"). */
export function phoneKey(value: string | null | undefined): string {
  const digits = (value ?? '').replace(/\D/g, '');

  return digits.length >= 9 ? digits.slice(-9) : digits;
}

/**
 * Which stored details would this form REPLACE? Only a stored value that differs from what was typed counts.
 * Each one must be confirmed individually before the form can be saved, mirroring the server.
 */
export function overwriteConflicts(summary: OfficeStaffSummary, form: OfficeStaffForm): OverwriteField[] {
  const conflicts: OverwriteField[] = [];
  const contact = summary.emergency_contact;

  if (
    (normaliseText(contact.name) !== '' || phoneKey(contact.phone) !== '') &&
    (normaliseText(contact.name) !== normaliseText(form.emergency_contact_name) || phoneKey(contact.phone) !== phoneKey(form.emergency_contact_phone))
  ) {
    conflicts.push('emergency_contact');
  }

  if (
    summary.guarantor !== null &&
    (normaliseText(summary.guarantor.name) !== normaliseText(form.guarantor_name) || phoneKey(summary.guarantor.phone) !== phoneKey(form.guarantor_phone))
  ) {
    conflicts.push('guarantor');
  }

  if (summary.position_id !== null && form.position_id !== '' && String(summary.position_id) !== form.position_id) {
    conflicts.push('position');
  }

  return conflicts;
}

/** Form prefilled from what is already recorded, so nothing existing is hidden and nothing has to be retyped. */
export function formFromSummary(summary: OfficeStaffSummary, today: string): OfficeStaffForm {
  return {
    ...emptyForm(today),
    position_id: summary.position_id === null ? '' : String(summary.position_id),
    monthly_salary: summary.current_salary?.amount ?? '',
    emergency_contact_name: summary.emergency_contact.name ?? '',
    emergency_contact_phone: summary.emergency_contact.phone ?? '',
    guarantor_name: summary.guarantor?.name ?? '',
    guarantor_phone: summary.guarantor?.phone ?? '',
    guarantor_relationship: summary.guarantor?.relationship ?? '',
  };
}

export interface OfficeStaffPayloadOptions {
  confirmOverwrite?: OverwriteField[];
  confirmAdditionalAssignment?: boolean;
  confirmDuplicate?: { reason: string } | null;
}

/** The JSON body for the server. Blank optional fields are left out so they can never blank an existing value. */
export function buildPayload(mode: OfficeStaffMode, form: OfficeStaffForm, employeeId: number | null, options: OfficeStaffPayloadOptions = {}): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    position_id: Number(form.position_id),
    monthly_salary: form.monthly_salary.trim(),
    start_date: form.start_date,
    emergency_contact_name: form.emergency_contact_name.trim(),
    emergency_contact_phone: form.emergency_contact_phone.trim(),
    guarantor_name: form.guarantor_name.trim(),
    guarantor_phone: form.guarantor_phone.trim(),
  };

  if (form.guarantor_relationship.trim() !== '') payload.guarantor_relationship = form.guarantor_relationship.trim();
  if (options.confirmOverwrite && options.confirmOverwrite.length > 0) payload.confirm_overwrite = options.confirmOverwrite;
  if (options.confirmAdditionalAssignment) payload.confirm_additional_assignment = true;

  if (mode === 'existing') {
    payload.employee_id = employeeId;
  } else {
    payload.full_name = form.full_name.trim();
    payload.phone = form.phone.trim();
    if (options.confirmDuplicate) {
      payload.confirm_duplicate = true;
      payload.duplicate_reason = options.confirmDuplicate.reason.trim();
    }
  }

  return payload;
}

/** Document groups that still have no file on record - the ones worth prompting for. */
export function missingDocumentGroups(groups: OfficeStaffDocumentGroup[]): OfficeStaffDocumentGroup[] {
  return groups.filter((group) => group.status === 'missing' && group.required);
}

/** One-line status such as "2 of 3 required documents on file". */
export function documentSummary(groups: OfficeStaffDocumentGroup[]): string {
  const required = groups.filter((group) => group.required);
  const onFile = required.filter((group) => group.status === 'on_file').length;

  return tNow('status.employees.documentSummary', { onFile, required: required.length });
}

/**
 * Staged files (chosen before the employee exists) -> the uploads to send afterwards, one per group, in order.
 * A group with no chosen file produces nothing, so no empty or duplicate upload is ever made.
 */
export function uploadPlan(staged: Partial<Record<OfficeStaffDocumentGroup['key'], File | null>>, categoryIdByName: Record<string, number>, groups: Pick<OfficeStaffDocumentGroup, 'key' | 'category'>[]): { key: string; file: File; categoryId: number }[] {
  return groups.flatMap((group) => {
    const file = staged[group.key];
    const categoryId = categoryIdByName[group.category];

    return file && categoryId ? [{ key: group.key, file, categoryId }] : [];
  });
}

export const DEFAULT_DOCUMENT_GROUPS: Pick<OfficeStaffDocumentGroup, 'key' | 'label' | 'category' | 'required'>[] = [
  { key: 'identity', label: 'Identification document', category: 'Identity Documents', required: true },
  { key: 'employment', label: 'Employment / appointment document', category: 'Employment Documents', required: true },
  { key: 'guarantor', label: 'Guarantor documents', category: 'Guarantor Documents', required: true },
  { key: 'other', label: 'Other supporting documents', category: 'Other HR Documents', required: false },
];

/** The label of a document group in the language of the screen; a group without a translation keeps the label it came with. */
export function documentGroupLabel(group: Pick<OfficeStaffDocumentGroup, 'key' | 'label'>): string {
  return tValueNow('option.employees.documentGroup', group.key) ?? group.label;
}

/** Same limits as the server's employee document rule, checked before upload so the user gets instant feedback. */
export const ALLOWED_DOCUMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
export const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

export function documentFileError(file: { name: string; size: number }): string | null {
  const extension = file.name.includes('.') ? (file.name.split('.').pop() ?? '').toLowerCase() : '';
  if (!ALLOWED_DOCUMENT_EXTENSIONS.includes(extension)) return tNow('validation.employees.fileTypeNotAllowed', { name: file.name, allowed: ALLOWED_DOCUMENT_EXTENSIONS.join(', ') });
  if (file.size > MAX_DOCUMENT_BYTES) return tNow('validation.employees.fileTooLarge', { name: file.name });
  if (file.size === 0) return tNow('validation.employees.fileEmpty', { name: file.name });

  return null;
}

interface ErrorLike {
  code?: string;
  fieldErrors?: Record<string, unknown>;
}

/** The server's "these stored details differ, confirm each one" answer, as the list of fields to confirm; null for any other error. */
export function overwriteConflictsFromError(error: unknown): OverwriteField[] | null {
  const e = error as ErrorLike;
  if (e?.code !== 'OVERWRITE_NOT_CONFIRMED') return null;
  const conflicts = e.fieldErrors?.conflicts;
  if (typeof conflicts !== 'object' || conflicts === null) return [];

  return (['emergency_contact', 'guarantor', 'position'] as const).filter((field) => field in (conflicts as Record<string, unknown>));
}

/** True when the server wants an explicit "yes, add a second assignment" because the person is already assigned elsewhere. */
export function needsAdditionalAssignmentConfirmation(error: unknown): boolean {
  return (error as ErrorLike)?.code === 'ADDITIONAL_ASSIGNMENT_NOT_CONFIRMED';
}
