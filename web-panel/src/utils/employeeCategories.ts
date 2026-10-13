// Pure helpers for employee categories (no React, no network, no `@/` imports) so they can be unit-tested by Node's
// native test runner (employeeCategories.test.ts).

/** The category every new Office Staff member gets automatically (the server sets it; forms only show it). */
export const OFFICE_STAFF_CATEGORY = 'Office Staff';

/**
 * Categories offered when recruiting someone or raising a workforce request. "Office Staff" is left out there: it is
 * assigned automatically by the Office Staff workflow. An employee who already has it keeps it (pass their current id).
 */
export function recruitmentCategories<T extends { id: number; name: string }>(categories: readonly T[], keepCategoryId: number | null = null): T[] {
  return categories.filter((category) => category.name.trim().toLowerCase() !== OFFICE_STAFF_CATEGORY.toLowerCase() || category.id === keepCategoryId);
}
