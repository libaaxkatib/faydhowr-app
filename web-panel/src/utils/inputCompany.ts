import type { WorkAssignment } from '@/types/employee';

/**
 * Every company has its own payroll, so a penalty, an advance, an unpaid leave or an absence belongs to ONE company. An employee who works for a
 * single company needs no choice (the server uses that company); one who works for several must say which company the record belongs to.
 */
export interface CompanyChoice {
  /** The company id; null = the Fayadhowr office (an assignment that names no company). */
  id: number | null;
  name: string;
}

type AssignmentLike = Pick<WorkAssignment, 'client_company_id' | 'client_company_name' | 'status'>;

/** The distinct companies of the employee's assignments (cancelled ones excluded), in the order they first appear. */
export function companyChoices(assignments: readonly AssignmentLike[] | undefined, officeName: string): CompanyChoice[] {
  const seen = new Set<string>();
  const choices: CompanyChoice[] = [];

  for (const assignment of assignments ?? []) {
    if (assignment.status === 'cancelled') continue;

    const key = assignment.client_company_id === null ? 'none' : String(assignment.client_company_id);
    if (seen.has(key)) continue;

    seen.add(key);
    choices.push({ id: assignment.client_company_id, name: assignment.client_company_name ?? officeName });
  }

  return choices;
}

/** The select value of a choice ('none' = the office), and back. */
export const choiceValue = (choice: CompanyChoice): string => (choice.id === null ? 'none' : String(choice.id));

/** The part of a payload that names the company: only when the employee has to choose, and only once they have. */
export function companyPayload(choices: readonly CompanyChoice[], selected: string): { client_company_id?: number | null } {
  if (choices.length < 2 || selected === '') return {};

  return { client_company_id: selected === 'none' ? null : Number(selected) };
}

/** A record cannot be sent while the employee works for several companies and none is chosen. */
export const companyMissing = (choices: readonly CompanyChoice[], selected: string): boolean => choices.length > 1 && selected === '';
