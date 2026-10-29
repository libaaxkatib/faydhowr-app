// Pure rule (no React, no `@/` imports) so Node's test runner can import it.

/**
 * Who is shown the "Allow one employee to work for more than one company" settings card. Owner decision 2026-10-29: the HR Manager does not see it
 * (state, "last changed by" and the number of employees it concerns are all part of the card); the Super Admin does and manages it. The server
 * enforces the same rule: it returns nothing for the HR Manager and refuses the HR Manager's change. This only decides what the screen shows.
 */
export function multiCompanyPolicyCardVisible(role: string | undefined): boolean {
  return role !== undefined && role !== 'hr_manager';
}
