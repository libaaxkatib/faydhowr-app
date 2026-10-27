// What the sign-in page says when signing in fails. Pure (no React, no `@/` imports) so Node's test runner can import it.
//
// The page keeps the failure itself and calls this on every render, so a message that is already on screen follows the language when it is switched.

import { ApiClientError } from '../api/client.ts';
import { tNow } from '../i18n/active.ts';

/** `fetch` rejects with a TypeError when the server cannot be reached (Chrome "Failed to fetch", Firefox "NetworkError...", Safari "Load failed"). */
function isNetworkFailure(failure: unknown): boolean {
  return failure instanceof TypeError && /fetch|network|load failed/i.test(failure.message);
}

export function describeLoginFailure(failure: unknown): string {
  if (failure instanceof ApiClientError) {
    if (failure.isValidation && failure.fieldErrors) {
      return Object.values(failure.fieldErrors).flat()[0] ?? failure.message;
    }

    return failure.message;
  }
  if (isNetworkFailure(failure)) return tNow('notify.auth.networkError');

  return tNow('notify.shell.somethingWrongTryAgain');
}
