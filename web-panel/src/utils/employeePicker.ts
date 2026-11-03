// Pure helpers of the employee pickers of the Temporary Replacement forms (no React, no network, no `@/` imports) so Node's test runner can test them.
//
// SEARCHING and BEING ABLE TO COVER are two different questions:
//  - With nothing typed, the picker lists the people who can be chosen: Active people of the picker's type (Temporary for the person doing the cover,
//    Permanent for the person being covered), a page at a time ("Show more").
//  - As soon as something is typed, the server searches EVERY employee (name, employee number, phone, database ID) - no status or type filter - so the person
//    being looked for is always found. Whoever cannot be chosen is shown greyed out with the reason; only people who meet the rules can be selected.
// The rules themselves are the server's (docs/HR_PAYROLL_RULES.md A5/A13, CreateTemporaryReplacementAction::assertEmployeeCanCover) and are still enforced
// on save: nothing here makes anybody eligible, converts a type, or bypasses a check.
//
// The NEW-cover form (owner decision 2026-11-03) also accepts a Waiting or Applicant person (`allowNotYetActive`): choosing them changes nothing; saving the
// cover is what makes them Active. Every other picker (the covered person, correcting a cover) is unchanged: it still needs an Active person.

export type PickerEmploymentType = 'permanent' | 'temporary';

/** Page size of the picker; more people are reached with "Show more" or by typing a name, number or ID. */
export const PICKER_PAGE_SIZE = 50;

export interface PickerParams {
  /** Only without a search: the list of people who can be chosen. A search looks at everyone. */
  status?: 'active';
  /** Only without a search, in the new-cover form: Active, Waiting and Applicant people together (comma separated). */
  statuses?: string;
  employment_type?: PickerEmploymentType;
  search?: string;
  search_by_id?: boolean;
  per_page: number;
  page: number;
}

/**
 * The exact query the picker sends for one page.
 * Blank search: the eligible pool (Active, of this type). Typed search: every employee that matches, whatever the status or type.
 */
export function pickerParams(employmentType: PickerEmploymentType, search: string, page: number, allowNotYetActive = false): PickerParams {
  const term = search.trim();

  if (term !== '') return { search: term, search_by_id: true, per_page: PICKER_PAGE_SIZE, page };

  return allowNotYetActive
    ? { statuses: NEW_COVER_STATUSES.join(','), employment_type: employmentType, per_page: PICKER_PAGE_SIZE, page }
    : { status: 'active', employment_type: employmentType, per_page: PICKER_PAGE_SIZE, page };
}

/** The statuses of a person who may be chosen in the new-cover form: Active, or Waiting / Applicant (made Active when the cover is saved). */
export const NEW_COVER_STATUSES = ['active', 'waiting', 'applicant'] as const;

/** True for a status that is made Active by saving the cover. */
export const isNotYetActive = (status: string | null | undefined): boolean => status === 'waiting' || status === 'applicant';

/** The part of an employee row the picker reads. */
export interface PickerEmployee {
  id: number;
  full_name: string;
  employee_number: string;
  status?: string | null;
  employment_type?: string | null;
  registration_workflow?: { eligible_for_waiting_and_company: boolean; missing: string[] };
}

/** Why a person cannot be chosen in this picker (null = can be chosen). */
export type NotChoosable =
  | { reason: 'covered_person' }
  | { reason: 'not_active'; status: string }
  | { reason: 'not_temporary' }
  | { reason: 'not_permanent' }
  | { reason: 'registration_incomplete' };

/**
 * The same questions the server asks (the person doing the cover: Active, Temporary, registration complete, not the covered person; the person being
 * covered: a Permanent employee who is Active). The first failing one is the reason shown; the server stays the authority and re-checks on save.
 */
export function notChoosable(employee: PickerEmployee, employmentType: PickerEmploymentType, excludeId?: string, allowNotYetActive = false): NotChoosable | null {
  if (String(employee.id) === excludeId) return { reason: 'covered_person' };

  const notYetActive = allowNotYetActive && isNotYetActive(employee.status);
  if (!notYetActive && employee.status !== undefined && employee.status !== null && employee.status !== 'active') return { reason: 'not_active', status: employee.status };

  // The type: a row without a type is Permanent (the default of the employee record).
  const isTemporary = employee.employment_type === 'temporary';
  if (employmentType === 'temporary') {
    // The type is the more basic reason (a Permanent person's registration is irrelevant to covering), so it is said first.
    if (!isTemporary) return { reason: 'not_temporary' };
    // A Waiting / Applicant person is not held back here: saving the cover activates them, as a recorded exception when the registration steps are not complete.
    if (!notYetActive && employee.registration_workflow?.eligible_for_waiting_and_company === false) return { reason: 'registration_incomplete' };
  } else if (isTemporary) {
    return { reason: 'not_permanent' };
  }

  return null;
}

export interface PickerOption {
  value: string;
  label: string;
  /** The person's status when they will be made Active by saving the cover (Waiting / Applicant); the form says so. */
  becomesActive?: string;
  /** Shown but not selectable: the person was found but does not meet the rules (the label says why). */
  disabled?: boolean;
}

/** "Name (EMP-000123 · ID 45)": the number and the ID tell two people with the same name apart. */
export function pickerLabel(employee: PickerEmployee): string {
  return `${employee.full_name} (${employee.employee_number} · ID ${employee.id})`;
}

/**
 * The options of one picker. A person who cannot be chosen is listed - so a search always shows who was found - but disabled, with the reason written
 * after the name. People who can be chosen come first within the page. Nobody is listed twice.
 */
export function pickerOptions(
  employees: readonly PickerEmployee[],
  {
    employmentType,
    excludeId,
    reasonText,
    allowNotYetActive = false,
    activeOnSaveText,
  }: {
    employmentType: PickerEmploymentType;
    excludeId?: string;
    reasonText: (why: NotChoosable) => string;
    allowNotYetActive?: boolean;
    /** Written after the name of a Waiting / Applicant person who can be chosen ("Waiting - becomes Active when saved"). */
    activeOnSaveText?: (status: string) => string;
  },
): PickerOption[] {
  const seen = new Set<number>();
  const choosable: PickerOption[] = [];
  const others: PickerOption[] = [];

  for (const employee of employees) {
    if (seen.has(employee.id)) continue;
    seen.add(employee.id);

    const why = notChoosable(employee, employmentType, excludeId, allowNotYetActive);
    const pending = allowNotYetActive && isNotYetActive(employee.status) ? String(employee.status) : undefined;
    if (why === null) {
      choosable.push(
        pending === undefined
          ? { value: String(employee.id), label: pickerLabel(employee) }
          : { value: String(employee.id), label: `${pickerLabel(employee)} — ${activeOnSaveText ? activeOnSaveText(pending) : pending}`, becomesActive: pending },
      );
    }
    else others.push({ value: String(employee.id), label: `${pickerLabel(employee)} — ${reasonText(why)}`, disabled: true });
  }

  return [...choosable, ...others];
}

/** True when more people match than are listed: the picker then offers "Show more". */
export function hasMore(shown: number, total: number): boolean {
  return total > shown;
}

/** True when people were found but none of them can be chosen (the screen then says so, since the list itself only shows greyed-out names). */
export function foundButNoneChoosable(options: readonly PickerOption[]): boolean {
  return options.length > 0 && options.every((option) => option.disabled === true);
}

/** True when the list is the result of a typed search (not the eligible pool): the screen then says that everyone was searched. */
export function isSearching(search: string): boolean {
  return search.trim() !== '';
}
