/**
 * Pure helpers of the Permanent -> Temporary rule (docs/HR_PAYROLL_RULES.md U1, final). The server refuses the change while the employee still holds
 * an Active assignment (their pay line would disappear); the form shows the same warning before sending and disables Save. Nothing is ended,
 * replaced or converted automatically. The warning text is the owner-approved message `warning.employees.typeChangeBlocked` of the catalogue
 * (Somali exactly as approved; English equivalent), shown in the language the user chose.
 */

export const TYPE_CHANGE_BLOCKED_CODE = 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT';

/** True when saving would change a Permanent employee to Temporary while an assignment is still active. */
export function isBlockedTypeChange(currentType: string | null | undefined, nextType: string, activeAssignments: number): boolean {
  return (currentType ?? 'permanent') !== 'temporary' && nextType === 'temporary' && activeAssignments > 0;
}

/** True when the server refused the change for this reason. */
export function isTypeChangeBlockedError(error: unknown): boolean {
  return (error as { code?: unknown } | null | undefined)?.code === TYPE_CHANGE_BLOCKED_CODE;
}
