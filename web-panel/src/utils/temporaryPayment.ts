/**
 * Pure helpers of the Temporary payment dialog (docs/HR_PAYROLL_RULES.md N5): the amount is prefilled with the calculated balance, and a different
 * amount only warns - it is never blocked. The server calculates and stores the figures; these decide what the form shows.
 */

/** Dollars (or any currency unit) to whole cents, tolerant of float noise such as 0.1 + 0.2. Null for anything that is not a non-negative number. */
export function toCents(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null;
  const n = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(n) || n < 0) return null;
  return Math.round(n * 100);
}

/** True when both amounts are known and differ by at least one cent. */
export function paymentDiffers(calculated: string | null | undefined, entered: string | number | null | undefined): boolean {
  const a = toCents(calculated);
  const b = toCents(entered);
  return a !== null && b !== null && a !== b;
}

/** "50.00 less" / "50.00 more" than the calculated amount, or null when they match or either is unknown. */
export function differenceText(calculated: string | null | undefined, entered: string | number | null | undefined): string | null {
  const a = toCents(calculated);
  const b = toCents(entered);
  if (a === null || b === null || a === b) return null;
  return `${(Math.abs(b - a) / 100).toFixed(2)} ${b > a ? 'more' : 'less'}`;
}

/** The text of the warning shown beside the amount: both figures, and that the entered amount is still recorded as typed. */
export function overrideWarning(calculated: string, entered: string | number, currency = 'USD'): string {
  const typed = (toCents(entered) ?? 0) / 100;
  return `The calculated amount is ${calculated} ${currency}; you entered ${typed.toFixed(2)} ${currency} (${differenceText(calculated, entered)}). It will be recorded as you typed it, together with the calculated amount.`;
}
