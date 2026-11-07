// Pure client-side checks for small Marketing forms (no React, no `@/` imports; messages are looked up through tNow). They only save a round trip: the
// server validates everything again and stays the authority.

import { tNow } from '../i18n/active.ts';

export function validateTeamName(name: string): string | undefined {
  return name.trim() === '' ? tNow('validation.marketing.enterTeamName') : undefined;
}

/** A commission rate value: a number of 0 or more. The server (min 0) is the authority; no upper limit is invented here. */
export function validateRateValue(value: string): string | undefined {
  if (value.trim() === '' || Number.isNaN(Number(value)) || Number(value) < 0) return tNow('validation.marketing.enterRateValue');
  return undefined;
}

export function validateCommissionEntry(form: { admin_id: string; reference_date: string; amount: string }): Partial<Record<'admin_id' | 'reference_date' | 'amount', string>> {
  const errors: Partial<Record<'admin_id' | 'reference_date' | 'amount', string>> = {};
  if (form.admin_id === '') errors.admin_id = tNow('validation.marketing.chooseEmployee');
  if (form.reference_date === '') errors.reference_date = tNow('validation.marketing.enterReferenceDate');
  if (form.amount.trim() !== '' && (Number.isNaN(Number(form.amount)) || Number(form.amount) < 0)) errors.amount = tNow('validation.marketing.enterAmount');
  return errors;
}

/** The follow-up "Update status" editor may save only when the chosen status differs from the record's saved one. */
export function canSaveStatusChange(chosen: string, saved: string | undefined): boolean {
  return chosen !== '' && chosen !== saved;
}

/** The feedback editor may save only when the text is not empty and differs from the saved feedback. */
export function canSaveFeedback(text: string): boolean {
  return text.trim() !== '';
}

/**
 * Owner decision E-7: new feedback goes after the feedback already saved (the server's FeedbackLog::append does the same, blank line between entries),
 * so an edit of a record sends the saved text followed by the new entry and the earlier feedback is never replaced.
 */
export function appendFeedback(saved: string | null | undefined, added: string): string {
  const before = (saved ?? '').replace(/\r\n/g, '\n').trim();
  const entry = added.replace(/\r\n/g, '\n').trim();

  return before === '' ? entry : `${before}\n\n${entry}`;
}
