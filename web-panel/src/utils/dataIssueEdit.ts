// Pure rules of the Data Issue edit dialog (no React, no `@/` imports) so Node's test runner can test them.
//
// Editing a CLOSED issue (Resolved / Accepted Difference / Cannot Resolve) must not silently reopen it: the server treats any move to Open or Investigating
// as a reopen and clears who resolved it and when. So the dialog starts on the issue's own status, and a status is sent only when the person really
// chose a different one.

export type IssueStatus = 'open' | 'investigating' | 'resolved' | 'accepted_difference' | 'cannot_resolve';

const CLOSING: readonly IssueStatus[] = ['resolved', 'accepted_difference', 'cannot_resolve'];

export function isClosedStatus(status: IssueStatus): boolean {
  return CLOSING.includes(status);
}

/**
 * The status to put in the update, or undefined to leave it out. Only a move to Open or Investigating can be sent (closing goes through Resolve), and
 * only when it differs from what the issue has now.
 */
export function statusToSend(current: IssueStatus, chosen: IssueStatus): 'open' | 'investigating' | undefined {
  if (chosen === current) return undefined;

  return chosen === 'open' || chosen === 'investigating' ? chosen : undefined;
}

/** The choices of the status box: Open and Investigating, plus the issue's own closed status so it can be kept. */
export function statusChoices<T extends { value: IssueStatus }>(all: readonly T[], current: IssueStatus): T[] {
  return all.filter((option) => option.value === 'open' || option.value === 'investigating' || option.value === current);
}

/** Choosing Open or Investigating on a closed issue is a deliberate reopen; the dialog says so. */
export function isReopening(current: IssueStatus, chosen: IssueStatus): boolean {
  return isClosedStatus(current) && (chosen === 'open' || chosen === 'investigating');
}
