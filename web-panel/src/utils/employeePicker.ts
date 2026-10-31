// Pure helpers of the employee pickers of the Temporary Replacement forms (no React, no network, no `@/` imports) so Node's test runner can test them.
//
// The pickers used to fetch the first 100 Active people and filter their type in the browser, so a person beyond the first 100 was never offered. The type is
// now sent to the server (`employment_type`), results are paged ("Show more"), and a typed number also finds the database ID (`search_by_id`).

export type PickerEmploymentType = 'permanent' | 'temporary';

/** Page size of the picker; more people are reached with "Show more" or by typing a name, number or ID. */
export const PICKER_PAGE_SIZE = 50;

export interface PickerParams {
  status: 'active';
  employment_type: PickerEmploymentType;
  search?: string;
  search_by_id?: boolean;
  per_page: number;
  page: number;
}

/** The exact query the picker sends for one page. A blank search sends neither `search` nor `search_by_id`. */
export function pickerParams(employmentType: PickerEmploymentType, search: string, page: number): PickerParams {
  const term = search.trim();
  return {
    status: 'active',
    employment_type: employmentType,
    ...(term === '' ? {} : { search: term, search_by_id: true }),
    per_page: PICKER_PAGE_SIZE,
    page,
  };
}

/** The part of an employee row the picker reads. */
export interface PickerEmployee {
  id: number;
  full_name: string;
  employee_number: string;
  employment_type?: string | null;
  registration_workflow?: { eligible_for_waiting_and_company: boolean; missing: string[] };
}

export interface PickerOption {
  value: string;
  label: string;
  /** Shown but not selectable: the server would refuse this person (a new registration that has not completed the registration workflow). */
  disabled?: boolean;
}

/** "Name (EMP-000123 · ID 45)": the number and the ID tell two people with the same name apart. */
export function pickerLabel(employee: PickerEmployee): string {
  return `${employee.full_name} (${employee.employee_number} · ID ${employee.id})`;
}

/**
 * The options of one picker. The covered (Permanent) person is never offered as their own replacement (`excludeId`). A Temporary person whose
 * registration is not complete is listed but disabled with the given reason: the server refuses them (EMPLOYEE_REGISTRATION_INCOMPLETE), so HR sees
 * why a person cannot be chosen instead of not finding them. Nothing is hidden by type here: the server already filtered it.
 */
export function pickerOptions(
  employees: readonly PickerEmployee[],
  { employmentType, excludeId, notReadyLabel }: { employmentType: PickerEmploymentType; excludeId?: string; notReadyLabel: (missing: string[]) => string },
): PickerOption[] {
  const seen = new Set<number>();
  const options: PickerOption[] = [];

  for (const employee of employees) {
    if (seen.has(employee.id) || String(employee.id) === excludeId) continue;
    seen.add(employee.id);

    const notReady = employmentType === 'temporary' && employee.registration_workflow?.eligible_for_waiting_and_company === false;
    options.push(
      notReady
        ? { value: String(employee.id), label: `${pickerLabel(employee)} — ${notReadyLabel(employee.registration_workflow?.missing ?? [])}`, disabled: true }
        : { value: String(employee.id), label: pickerLabel(employee) },
    );
  }

  return options;
}

/** True when more people match than are listed: the picker then offers "Show more". */
export function hasMore(shown: number, total: number): boolean {
  return total > shown;
}
