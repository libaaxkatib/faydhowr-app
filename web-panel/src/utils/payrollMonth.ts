// The payroll month a screen opens on (owner decision 2026-11-04): ALWAYS the previous calendar month, because payroll is run, finalized and paid for the
// month that has just ended (in November 2026 the screens open on October 2026; in January 2027, on December 2026). The person can still pick any other
// month; this is only the starting value. Pure (no React, no network) so Node's test runner can test it; every payroll screen uses this one function, so the
// payroll summary, the company payroll list, the payment pages and the Excel downloads - which all read the month from their screen - agree.

/** The previous calendar month as YYYY-MM, in the user's own time zone. */
export function previousMonth(now: Date = new Date()): string {
  // Day 1 of the previous month: day arithmetic (31 -> a shorter month) can never skip a month.
  const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);

  return `${first.getFullYear()}-${String(first.getMonth() + 1).padStart(2, '0')}`;
}

/** The month a payroll screen starts on. */
export const defaultPayrollMonth = previousMonth;
