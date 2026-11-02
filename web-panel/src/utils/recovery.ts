import type { MutatingWorkflowId, RecoveryParams, RecoveryPreview } from '@/types/recovery';

export const MIN_REASON_LENGTH = 10;

/** The inputs each workflow needs, in the order they are shown. */
export const WORKFLOW_FIELDS: Record<MutatingWorkflowId, (keyof RecoveryParams)[]> = {
  reopen_assignment: [],
  restore_cancelled_assignment: [],
  correct_assignment_dates: ['month', 'start_date', 'end_date'],
  correct_absence: ['month', 'absence_id', 'last_working_day', 'returned_on'],
};

/** What must be entered before a preview can be asked for (the server still decides whether it is allowed). */
export function isReadyToPreview(workflow: MutatingWorkflowId, params: RecoveryParams): boolean {
  if (workflow === 'reopen_assignment' || workflow === 'restore_cancelled_assignment') return true;
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(params.month ?? '')) return false;
  if (workflow === 'correct_assignment_dates') return Boolean(params.start_date || params.end_date);
  return Boolean(params.absence_id) && Boolean(params.last_working_day || params.returned_on);
}

/** Only the fields the workflow uses, and none that are blank: this is exactly what the server previews and then applies. */
export function cleanParams(workflow: MutatingWorkflowId, params: RecoveryParams): RecoveryParams {
  const out: RecoveryParams = {};

  for (const field of WORKFLOW_FIELDS[workflow]) {
    const value = params[field]?.trim();
    if (value) out[field] = value;
  }

  return out;
}

/** A correction can be applied only when the server's preview allowed it, a reason was written and the person confirmed. */
export function canApply(preview: RecoveryPreview | null, reason: string, confirmed: boolean): boolean {
  return preview !== null && preview.can_apply && preview.token !== null && reason.trim().length >= MIN_REASON_LENGTH && confirmed;
}

/** One key per preview: pressing Apply twice (or the network repeating the request) can then only ever apply the correction once. */
export function newIdempotencyKey(): string {
  const random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

  return `rc-${random}`.slice(0, 80);
}

/** The first day of a month input ("2026-10") and the day after its last, for the date inputs' min / max. */
export function monthBounds(month: string): { min: string; max: string } | null {
  const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(month);
  if (match === null) return null;
  const year = Number(match[1]);
  const m = Number(match[2]);
  const last = new Date(Date.UTC(year, m, 0)).getUTCDate();

  return { min: `${month}-01`, max: `${month}-${String(last).padStart(2, '0')}` };
}

/** "+$110.00" / "-$20.00" / "$0.00" for a difference in cents. */
export function signedMoney(cents: number, format: (cents: number) => string): string {
  if (cents === 0) return format(0);

  return `${cents > 0 ? '+' : '-'}${format(Math.abs(cents))}`;
}
