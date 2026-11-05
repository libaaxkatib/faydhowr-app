/**
 * Pure helpers of the Temporary Replacement warnings shown when a company is generated, reviewed or finalized. The warnings NEVER block anything: nothing
 * here disables a button or asks for a confirmation. The texts (`detail`, `what_remains`) are the server's own and are shown as given; the helpers only
 * group them by condition and decide the headings. Visible wording comes from the language catalogue.
 */

import type { MessageKey } from '../i18n/messages/index.ts';
import type { GenerationWarning, GenerationWarningCondition } from '../types/payroll.ts';

export const WARNING_CONDITIONS: readonly GenerationWarningCondition[] = ['A', 'B', 'C', 'D'];

export const WARNING_CONDITION_LABEL: Record<GenerationWarningCondition, MessageKey> = {
  A: 'warning.payroll.gen.condA',
  B: 'warning.payroll.gen.condB',
  C: 'warning.payroll.gen.condC',
  D: 'warning.payroll.gen.condD',
};

/** The warnings grouped by condition A, B, C, D (empty groups left out); a condition the screen does not know is shown last, with the D heading. */
export function groupWarnings(warnings: readonly GenerationWarning[]): { condition: GenerationWarningCondition; warnings: GenerationWarning[] }[] {
  const known = new Set<string>(WARNING_CONDITIONS);
  const groups = WARNING_CONDITIONS.map((condition) => ({
    condition,
    warnings: warnings.filter((warning) => warning.condition === condition || (condition === 'D' && !known.has(warning.condition))),
  }));

  return groups.filter((group) => group.warnings.length > 0);
}

/** The person a warning is about as "Name (number)", or null when it names nobody. */
export function warningPerson(warning: Pick<GenerationWarning, 'employee_name' | 'employee_number'>): string | null {
  const name = warning.employee_name?.trim();
  const number = warning.employee_number?.trim();
  if (name && number) return `${name} (${number})`;

  return name || number || null;
}

/** The warning count of a company row: the loaded warnings when they are known, otherwise the count the overview sent. */
export function warningCountOf(row: { generation_warning_count?: number | null }, loaded?: readonly GenerationWarning[]): number {
  return loaded ? loaded.length : (row.generation_warning_count ?? 0);
}
