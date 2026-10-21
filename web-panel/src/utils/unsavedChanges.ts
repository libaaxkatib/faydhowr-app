// Pure rules of the "unsaved changes" protection (no React, no `@/` imports) so Node's test runner can import them.

export type CloseDecision = 'close' | 'ask';

/**
 * What to do when someone tries to close a form (cross, Escape, outside click, Cancel) or leave the page. A form only asks when it is
 * protected AND the person actually changed something; a form nobody touched, or one that was just saved, closes silently.
 */
export function decideClose(opts: { protectedForm: boolean; touched: boolean }): CloseDecision {
  return opts.protectedForm && opts.touched ? 'ask' : 'close';
}

/** True when the current values differ from the values the form started with (order of keys does not matter). */
export function isDirty(initial: unknown, current: unknown): boolean {
  return stable(initial) !== stable(current);
}

function stable(value: unknown): string {
  return JSON.stringify(value, (_key, v) => (v && typeof v === 'object' && !Array.isArray(v) ? Object.fromEntries(Object.entries(v as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b))) : v));
}

export const DISCARD_TITLE = 'Discard your changes?';
export const DISCARD_TEXT = 'You have unsaved changes. If you leave now they will be lost.';
