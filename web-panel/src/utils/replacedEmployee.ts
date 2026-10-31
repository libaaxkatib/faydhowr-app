// What the company employee list shows under a Replacement: the Permanent employee whose position it took over (no React, no `@/` imports, so Node can test it).
//
// Only the REAL link is used: the API's `replaces` is the assignment named by `replaces_assignment_id` and its employee. Nothing is guessed from a name,
// a position or a date, and nothing is shown for a row that is not a Replacement.

export interface ReplacesLink {
  assignment_id: number;
  employee_id: number;
  employee_number: string | null;
  full_name: string | null;
}

export type ReplacedEmployeeDisplay =
  /** Not a Replacement: nothing to show. */
  | { kind: 'none' }
  /** A Replacement whose replaced employee is recorded. `name` is null only if the record has no name (then the ID is shown). */
  | { kind: 'linked'; employeeId: number; name: string | null; number: string | null }
  /** A Replacement with no recorded link (it replaces nobody on record): said plainly, never filled in. */
  | { kind: 'missing' };

export function replacedEmployeeDisplay(row: { assignment_type: string | null | undefined; replaces: ReplacesLink | null | undefined }): ReplacedEmployeeDisplay {
  if (row.assignment_type !== 'replacement') return { kind: 'none' };

  const link = row.replaces;
  if (!link || !Number.isInteger(link.employee_id) || link.employee_id <= 0) return { kind: 'missing' };

  return { kind: 'linked', employeeId: link.employee_id, name: link.full_name?.trim() ? link.full_name : null, number: link.employee_number?.trim() ? link.employee_number : null };
}

/** "Name (EMP-000001)" - the number is left out when it is not recorded, and the ID stands in for a missing name. */
export function replacedEmployeeText(display: Extract<ReplacedEmployeeDisplay, { kind: 'linked' }>): string {
  const name = display.name ?? `ID ${display.employeeId}`;
  return display.number === null ? name : `${name} (${display.number})`;
}

/**
 * The employee detail's assignments carry the recorded link twice: `replaces_assignment_id` (always) and `replaces` (the replaced employee, when the server
 * loaded it). "Not recorded" is said only when the assignment itself says it replaces nobody (`replaces_assignment_id` is null); a link whose details did
 * not arrive (an older server) says nothing at all - it is never turned into a claim either way.
 */
export function assignmentReplacesDisplay(assignment: {
  assignment_type?: string | null;
  replaces_assignment_id?: number | null;
  replaces?: ReplacesLink | null;
}): ReplacedEmployeeDisplay {
  if (assignment.assignment_type !== 'replacement') return { kind: 'none' };
  if (assignment.replaces) return replacedEmployeeDisplay({ assignment_type: 'replacement', replaces: assignment.replaces });

  return assignment.replaces_assignment_id === null ? { kind: 'missing' } : { kind: 'none' };
}
