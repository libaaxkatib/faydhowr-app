/**
 * Pure helpers of the Permanent -> Temporary rule (docs/HR_PAYROLL_RULES.md U1, final). The server refuses the change while the employee still holds
 * an Active assignment (their pay line would disappear); the form shows the same warning before sending and disables Save. Nothing is ended,
 * replaced or converted automatically.
 */

export const TYPE_CHANGE_BLOCKED_CODE = 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT';

/** The owner-approved Somali warning, word for word (the server sends the same text). */
export const TYPE_CHANGE_BLOCKED_SOMALI =
  'Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon. Si looga fogaado in xogta mushaharkiisu lunto, marka hore jooji shaqadii hore, qof kale u qoondee booskaas, kadibna samee assignment-ka cusub ee Temporary. Nidaamku si toos ah uma beddelayo shaqada hore.';

export const TYPE_CHANGE_BLOCKED_ENGLISH =
  'This employee still has an active assignment. End it first (Stop or End asks for the replacement), then change the type. Nothing is ended or replaced for you.';

/** True when saving would change a Permanent employee to Temporary while an assignment is still active. */
export function isBlockedTypeChange(currentType: string | null | undefined, nextType: string, activeAssignments: number): boolean {
  return (currentType ?? 'permanent') !== 'temporary' && nextType === 'temporary' && activeAssignments > 0;
}

/** The server's message when it refused the change for this reason, otherwise null. */
export function typeChangeBlockFromError(error: unknown): string | null {
  const e = error as { code?: unknown; message?: unknown } | null | undefined;
  if (e?.code !== TYPE_CHANGE_BLOCKED_CODE) return null;

  return typeof e.message === 'string' && e.message.length > 0 ? e.message : TYPE_CHANGE_BLOCKED_SOMALI;
}
