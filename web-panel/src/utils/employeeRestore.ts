// Pure helpers behind the "Restore" action for an archived employee (no React, no network, no `@/` imports) so Node's test runner can import them.
//
// Restoring changes only the archive mark: the person returns with the status and pipeline stage they already have, so the screen can say
// exactly where they will reappear. The server decides everything; this only words the dialog and shapes the request.

export interface RestoreTarget {
  status: string;
  pipeline_stage: string | null;
}

export interface RestoreLanding {
  label: string;
  path: string;
}

const STAGE_LANDING: Readonly<Record<string, RestoreLanding>> = {
  damiin_needed: { label: 'Damiin Needed', path: '/hr/pipeline/damiin' },
  contract_pending: { label: 'Contract Pending', path: '/hr/pipeline/contracts' },
  uniform_pending: { label: 'Uniform Pending', path: '/hr/pipeline/uniform' },
  need_training: { label: 'Need Training', path: '/hr/pipeline/training' },
  need_practical: { label: 'Need Practical', path: '/hr/pipeline/practical' },
  practical_repeat: { label: 'Practical Repeat', path: '/hr/pipeline/practical-repeat' },
  rejected: { label: 'Rejected', path: '/hr/pipeline/rejected' },
};

/** The list a restored person appears in: the one that matches the status (and stage) they already have. Everyone is also in Employees. */
export function restoreLanding(target: RestoreTarget): RestoreLanding {
  if (target.status === 'waiting') return { label: 'Waiting', path: '/hr/waiting' };
  if (target.status === 'inactive') return { label: 'Former Employees', path: '/hr/former-employees' };
  if (target.status === 'applicant' && target.pipeline_stage !== null && STAGE_LANDING[target.pipeline_stage] !== undefined) {
    return STAGE_LANDING[target.pipeline_stage];
  }

  return { label: 'Employees', path: '/hr/employees' };
}

export type RestoreFailure = 'not_archived' | 'duplicate_phone' | 'other';

/** What kind of refusal the server gave, from its error code. */
export function restoreFailureKind(code: string | undefined): RestoreFailure {
  if (code === 'EMPLOYEE_NOT_ARCHIVED') return 'not_archived';
  if (code === 'DUPLICATE_EMPLOYEE_PHONE') return 'duplicate_phone';

  return 'other';
}

/** The same minimum as the registration and edit forms for the written reason behind a shared phone number. */
export const MIN_DUPLICATE_REASON_LENGTH = 10;

/** The Restore button is enabled unless a shared phone number still needs its confirmation and reason. */
export function restoreIsReady(needsDuplicateConfirmation: boolean, confirmed: boolean, duplicateReason: string): boolean {
  if (!needsDuplicateConfirmation) return true;

  return confirmed && duplicateReason.trim().length >= MIN_DUPLICATE_REASON_LENGTH;
}

export interface RestorePayload {
  reason?: string;
  confirm_duplicate?: boolean;
  duplicate_reason?: string;
}

/** The request body: only what was filled in. Nothing here can name a status. */
export function restorePayload(note: string, duplicate: { confirmed: boolean; reason: string } | null): RestorePayload {
  const payload: RestorePayload = {};
  if (note.trim() !== '') payload.reason = note.trim();
  if (duplicate !== null && duplicate.confirmed) {
    payload.confirm_duplicate = true;
    payload.duplicate_reason = duplicate.reason.trim();
  }

  return payload;
}
