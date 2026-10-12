// Pure helpers for the Super Admin status override (no React, no network, no `@/` imports) so they can be
// unit-tested by Node's native test runner (statusOverride.test.ts). The BACKEND is the real guard; these
// only give the user quick feedback before a request is sent.

export const OVERRIDE_MIN_LENGTH = 10;
export const OVERRIDE_MAX_LENGTH = 500;

export const EMPLOYEE_STATUSES = ['applicant', 'recruitment', 'practical', 'waiting', 'approved', 'active', 'inactive'] as const;
export type OverrideStatus = (typeof EMPLOYEE_STATUSES)[number];

/** Same clean-up the server applies: surrounding and repeated whitespace do not count towards the length. */
export function normalizeReason(reason: string): string {
  return reason.replace(/\s+/g, ' ').trim();
}

/** Null when the reason is acceptable, otherwise a plain-language problem. */
export function overrideReasonError(reason: string): string | null {
  const text = normalizeReason(reason);
  if (text.length < OVERRIDE_MIN_LENGTH) return `Write a reason of at least ${OVERRIDE_MIN_LENGTH} characters.`;
  if (text.length > OVERRIDE_MAX_LENGTH) return `The reason may not be longer than ${OVERRIDE_MAX_LENGTH} characters.`;
  return null;
}

/** Every status except the current one; changing to the status an employee already has is always refused. */
export function overrideTargets(current: string): OverrideStatus[] {
  return EMPLOYEE_STATUSES.filter((status) => status !== current);
}

export function canSubmitOverride(current: string, target: string, reason: string): boolean {
  return overrideTargets(current).includes(target as OverrideStatus) && overrideReasonError(reason) === null;
}
