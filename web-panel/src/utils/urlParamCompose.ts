// Pure core of hooks/useUrlParamUpdater (no React, no `@/` imports) so Node's test runner can exercise it.
// Several changes made in one event must each start from the result of the one before, until the router has rendered the outcome.

export type PendingChange = { from: string; next: URLSearchParams };

/** The parameters the next change starts from: the pending result when it was made from the address that is still shown, otherwise the address shown. */
export function composeBase(shown: URLSearchParams, pending: PendingChange | null): URLSearchParams {
  return pending !== null && pending.from === shown.toString() ? pending.next : shown;
}

/** Applies one change on top of any pending one and returns the new pending change (its `next` is what to navigate to). */
export function applyChange(
  shown: URLSearchParams,
  pending: PendingChange | null,
  change: (previous: URLSearchParams) => URLSearchParams,
): PendingChange {
  return { from: shown.toString(), next: change(composeBase(shown, pending)) };
}
