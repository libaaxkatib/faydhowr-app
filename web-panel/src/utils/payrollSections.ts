/**
 * Pure helpers of the company-level payroll screen. The server decides every status, total and permission (a company is reviewed, approved and
 * finalized on its own; the month's own status is its least advanced company's); these helpers only decide which buttons the screen offers and how a
 * month and its lines are grouped for display. Visible wording comes from the language catalogue.
 */

import type { MessageKey } from '../i18n/messages/index.ts';
import type { PayrollItem, PayrollRun, PayrollRunSection, PayrollRunStatus } from '../types/payroll.ts';

export type SectionAction = 'review' | 'approve' | 'finalize' | 'reopen';

/** The buttons a company row offers: review needs hr.payroll.manage; approve, finalize and reopen need hr.payroll.approve; a finalized company offers none. */
export function sectionActions(status: PayrollRunStatus, canManage: boolean, canApprove: boolean): SectionAction[] {
  switch (status) {
    case 'draft':
      return canManage ? ['review'] : [];
    case 'reviewed':
      return canApprove ? ['approve'] : [];
    case 'approved':
      return canApprove ? ['finalize', 'reopen'] : [];
    case 'finalized':
      return [];
  }
}

/** How the month is shown: "partially finalized" while some, but not all, of its companies are finalized; otherwise its own status. */
export function monthDisplayStatus(run: Pick<PayrollRun, 'status' | 'is_partially_finalized'>): PayrollRunStatus | 'partially_finalized' {
  return run.is_partially_finalized ? 'partially_finalized' : run.status;
}

export const MONTH_STATUS_LABEL: Record<PayrollRunStatus | 'partially_finalized', { labelKey: MessageKey; tone: 'neutral' | 'info' | 'warning' | 'success' }> = {
  draft: { labelKey: 'option.payrollStatus.draft', tone: 'neutral' },
  reviewed: { labelKey: 'option.payrollStatus.reviewed', tone: 'info' },
  approved: { labelKey: 'option.payrollStatus.approved', tone: 'warning' },
  finalized: { labelKey: 'option.payrollStatus.finalized', tone: 'success' },
  partially_finalized: { labelKey: 'status.payroll.partiallyFinalized', tone: 'warning' },
};

/** True when the company the line belongs to is finalized (a line of a month with no company sections follows the month). */
export function lineIsFinalized(run: Pick<PayrollRun, 'status' | 'sections'>, item: Pick<PayrollItem, 'section_id'>): boolean {
  if (run.status === 'finalized') return true;
  if (item.section_id === null || item.section_id === undefined) return false;

  return (run.sections ?? []).some((section) => section.id === item.section_id && section.status === 'finalized');
}

/** The lines of one company (null = every company). */
export function itemsOfSection(items: PayrollItem[], sectionId: number | null): PayrollItem[] {
  return sectionId === null ? items : items.filter((item) => item.section_id === sectionId);
}

/** The company the screen is filtered to, or null when it does not exist (any more) in this month. */
export function selectedSection(sections: PayrollRunSection[], sectionId: number | null): PayrollRunSection | null {
  return sectionId === null ? null : (sections.find((section) => section.id === sectionId) ?? null);
}

/** A company's name as the table shows it (the "no company" section has none). */
export function sectionName(section: Pick<PayrollRunSection, 'company_name'>, fallback: string): string {
  return section.company_name?.trim() || fallback;
}
