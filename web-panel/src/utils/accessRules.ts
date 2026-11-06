// Pure access rules shared by the guards (no React, no `@/` imports) so Node's test runner can test them.

/**
 * What a failed "who am I" call means for the stored sign-in. Only an answer that says the sign-in is not valid - 401 (not signed in) or 403 (account
 * switched off) - ends the session. A network failure or a server error says nothing about the sign-in: it is kept and a retry is offered, so a short
 * outage never signs everybody out.
 */
export function sessionOutcomeAfterFailure(error: unknown): 'signed_out' | 'unavailable' {
  const status = (error as { status?: unknown } | null | undefined)?.status;

  return status === 401 || status === 403 ? 'signed_out' : 'unavailable';
}

/**
 * True when the list of permissions could not be fetched AND none was ever loaded. That is "we could not check", never "no permissions": the screen
 * shows a retry instead of "Access restricted". The Super Admin needs no list, so it is never affected.
 */
export function permissionsUnavailable(state: { isError: boolean; hasData: boolean; isSuperAdmin: boolean }): boolean {
  return state.isError && !state.hasData && !state.isSuperAdmin;
}

/** A page the backend opens by role: the Super Admin always passes, anybody else only with one of the listed roles. */
export function roleAllowed(roles: readonly string[], role: string | undefined, isSuperAdmin: boolean): boolean {
  return isSuperAdmin || (role !== undefined && roles.includes(role));
}

/** Closing or reopening a company is the HR Manager's and the Super Admin's (the backend checks the role: CorrectionAuthority::mayCloseCompany), not every holder of hr.manage. */
export function canCloseOrReopenCompany(role: string | null | undefined): boolean {
  return role === 'super_admin' || role === 'hr_manager';
}
