/**
 * The screens outside the payroll page that show figures a payroll action changes: the HR dashboard's payroll cards, the financial ledger, an employee's
 * payroll summary and card (penalty / advance deducted so far) and the payroll roll-up. A Generate, Review, Finalize, Reopen, payment, void or correction on
 * the payroll page marks them stale at once, instead of leaving them as they were until the 30 seconds of the default cache run out. Pure (no React), so Node's
 * test runner can check it.
 */

/** The query keys (prefixes) of the screens that depend on payroll results. */
export const PAYROLL_DEPENDENT_QUERY_KEYS: readonly (readonly string[])[] = [
  ['hr-dashboard'],
  ['financial-ledger'],
  ['payroll-summary'],
  ['payroll-rollup'],
  ['deduction-balances'],
  ['employee'],
];

interface InvalidatingClient {
  invalidateQueries: (filters: { queryKey: readonly string[] }) => unknown;
}

/** Marks every screen that shows payroll results stale; the ones currently on screen load again, the others on their next visit. */
export function invalidatePayrollDependents(client: InvalidatingClient): void {
  for (const queryKey of PAYROLL_DEPENDENT_QUERY_KEYS) void client.invalidateQueries({ queryKey });
}
