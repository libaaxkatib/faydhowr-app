// Pure helpers for the Temporary Replacement screens (no React, no network, no `@/` imports) so Node's test runner can test them.
// Dates are plain 'YYYY-MM-DD' calendar days and are compared as such: both ends of a cover are INCLUSIVE (docs/HR_PAYROLL_RULES.md U8).

export type ReplacementLifecycle = 'scheduled' | 'running' | 'expired' | 'ended';

export type EndChangeKind = 'invalid' | 'missing' | 'extension' | 'shorter' | 'same' | 'first';

const ISO = /^\d{4}-\d{2}-\d{2}$/;

export function isIsoDate(value: string): boolean {
  if (!ISO.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);

  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/** Calendar days from `from` to `to`, both included (a one-day cover is 1). Negative when `to` is before `from`. */
export function daysInclusive(from: string, to: string): number {
  const a = Date.parse(`${from}T00:00:00Z`);
  const b = Date.parse(`${to}T00:00:00Z`);

  return Math.round((b - a) / 86_400_000) + 1;
}

/**
 * The last day the End Coverage form starts with. It is the planned end date when the cover has one, and NOTHING otherwise: the user has to type the
 * day the cover really stopped. It is never today's date, which is how a late click used to lengthen a cover without anyone noticing.
 */
export function defaultLastDay(endDate: string | null): string {
  return endDate !== null && isIsoDate(endDate) ? endDate : '';
}

export interface EndChange {
  kind: EndChangeKind;
  /** Days added (> 0) or removed (< 0) compared with the end date recorded now; 0 when nothing changes. */
  daysDelta: number;
}

/** How a chosen last day compares with the cover as it is recorded now. `currentEnd` null = a cover with no end date yet. */
export function describeEndChange(start: string, currentEnd: string | null, chosen: string): EndChange {
  if (chosen === '') return { kind: 'missing', daysDelta: 0 };
  if (!isIsoDate(chosen) || !isIsoDate(start) || chosen < start) return { kind: 'invalid', daysDelta: 0 };
  if (currentEnd === null) return { kind: 'first', daysDelta: 0 };
  if (chosen === currentEnd) return { kind: 'same', daysDelta: 0 };

  const delta = daysInclusive(currentEnd, chosen) - 1;

  return chosen > currentEnd ? { kind: 'extension', daysDelta: delta } : { kind: 'shorter', daysDelta: delta };
}

/** An extension must be confirmed by the person before the save is allowed; every other case is free to go ahead. */
export function needsExtensionConfirmation(change: EndChange): boolean {
  return change.kind === 'extension';
}

/** The save button of End Coverage: a valid last day, and the extension confirmed when there is one. */
export function canEndCoverage(change: EndChange, confirmed: boolean, hasBlockingConflict: boolean, needsSuperAdmin: boolean, reason: string): boolean {
  if (change.kind === 'missing' || change.kind === 'invalid' || hasBlockingConflict || needsSuperAdmin) return false;
  if (needsExtensionConfirmation(change) && !confirmed) return false;

  return reason.trim() === '' || reason.trim().length >= 10;
}

/** The list filter (?status=...) against a cover's computed lifecycle: "active" = running or not started yet, "expired" = past its end date and still open. */
export function matchesStatusFilter(filter: 'active' | 'ended' | 'expired' | null, lifecycle: ReplacementLifecycle): boolean {
  if (filter === null) return true;
  if (filter === 'active') return lifecycle === 'running' || lifecycle === 'scheduled';

  return filter === lifecycle;
}

/** 12345 cents -> "123.45" (the app shows plain amounts, USD is added by the caller). */
export function centsToAmount(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(Math.round(cents));

  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** The correction form can only be sent with a written reason of at least 10 characters (the server enforces the same). */
export const MIN_CHANGE_REASON = 10;

export function validChangeReason(reason: string): boolean {
  return reason.trim().length >= MIN_CHANGE_REASON;
}

/** Start and end of a correction as typed: ok when the start is a date and the end (optional) is a date that is not before it. */
export function validPeriod(start: string, end: string): boolean {
  if (!isIsoDate(start)) return false;
  if (end === '') return true;

  return isIsoDate(end) && end >= start;
}

/**
 * The server refused a save because the cover was changed by someone else since the screen loaded it (409). The screen then has to REFRESH the cover - the
 * version it holds can never succeed again - not just show the message.
 */
export function isStaleCoverError(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { code?: unknown }).code === 'TEMPORARY_REPLACEMENT_CHANGED';
}

/** The server asks for the written reason of a closed-payroll correction (422 CORRECTION_REASON_REQUIRED) when the preview did not already say so. */
export function isCorrectionReasonAsked(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { code?: unknown }).code === 'CORRECTION_REASON_REQUIRED';
}

/** What the New Temporary Replacement form knows when the person presses Create (owner decision UG-3). */
export interface CreateCoverState {
  /** The covered position, the Temporary employee and a start date are chosen. */
  hasFields: boolean;
  /** An overlap, an invalid period or any other error the preview or a refused save reported. */
  blocked: boolean;
  /** The server's preview says this cover reaches an approved or finalized payroll month. */
  reachesClosedPayroll: boolean;
  /** The server asked for a reason on a save although the preview had not said so. */
  reasonAsked: boolean;
  /** The role may not correct closed payroll (anyone but a Super Admin or an HR Manager). */
  roleMayNotCorrect: boolean;
  reason: string;
  checking: boolean;
}

/** The reason box shows when the preview or a refused save said the cover reaches closed payroll and the role may correct it. */
export function createNeedsReason(state: Pick<CreateCoverState, 'reachesClosedPayroll' | 'reasonAsked' | 'roleMayNotCorrect'>): boolean {
  return (state.reachesClosedPayroll || state.reasonAsked) && !state.roleMayNotCorrect;
}

/** The Create button: the choices are made, nothing blocks, the preview has finished, and a closed-payroll cover has its written reason (10+ characters). */
export function canCreateCover(state: CreateCoverState): boolean {
  if (!state.hasFields || state.blocked || state.checking) return false;
  if (state.roleMayNotCorrect && state.reachesClosedPayroll) return false;

  return !createNeedsReason(state) || validChangeReason(state.reason);
}

/**
 * The single written reason an Edit correction sends. When the change reaches approved or finalized payroll the closed-payroll reason IS the correction
 * reason (the person writes it once); otherwise it is the "why are you correcting this" text.
 */
export function editChangeReason(reachesClosedPayroll: boolean, whyChanged: string, closedReason: string): string {
  return (reachesClosedPayroll ? closedReason : whyChanged).trim();
}

/** The reason the Edit button waits for: the closed-payroll reason when the change reaches closed payroll, the ordinary one otherwise. */
export function editReasonOk(reachesClosedPayroll: boolean, whyChanged: string, closedReason: string): boolean {
  return validChangeReason(editChangeReason(reachesClosedPayroll, whyChanged, closedReason));
}

/** The word a cover's badge uses in every list ("status.pipeline.replacement.<word>"): the server's lifecycle, with a running cover called "active". */
export function lifecycleWord(cover: { lifecycle?: ReplacementLifecycle; status: string }): 'active' | 'scheduled' | 'expired' | 'ended' {
  const lifecycle = cover.lifecycle ?? (cover.status === 'ended' ? 'ended' : 'running');

  return lifecycle === 'running' ? 'active' : lifecycle;
}

/** The colour of that badge: an expired cover is a problem, a running one is open work, a scheduled one is information, an ended one is quiet. */
export function lifecycleTone(cover: { lifecycle?: ReplacementLifecycle; status: string }): 'danger' | 'warning' | 'info' | 'neutral' {
  const word = lifecycleWord(cover);

  return word === 'expired' ? 'danger' : word === 'active' ? 'warning' : word === 'scheduled' ? 'info' : 'neutral';
}
