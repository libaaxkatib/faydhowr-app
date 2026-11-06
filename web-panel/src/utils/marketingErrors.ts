// Pure helper for the message a Marketing screen shows when a request fails (no React, no `@/` imports).
//
// A 422 from the server carries the generic sentence "The given data was invalid." plus the real reasons per field. A toast that shows only the generic
// sentence tells the person nothing, so a validation failure shows the first real reason instead; every other failure keeps its own message.

interface ErrorLike {
  message?: string;
  isValidation?: boolean;
  fieldErrors?: Record<string, string[]> | undefined;
}

export function firstFieldError(fieldErrors: Record<string, string[]> | undefined): string | undefined {
  for (const messages of Object.values(fieldErrors ?? {})) {
    if (messages.length > 0 && messages[0].trim() !== '') return messages[0];
  }
  return undefined;
}

export function marketingErrorMessage(error: unknown, fallback: string): string {
  if (typeof error !== 'object' || error === null) return fallback;
  const e = error as ErrorLike;
  if (e.isValidation === true) {
    const reason = firstFieldError(e.fieldErrors);
    if (reason) return reason;
  }
  return typeof e.message === 'string' && e.message !== '' ? e.message : fallback;
}
