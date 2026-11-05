/**
 * Pure helpers of the company-by-company payroll overview (HR Release 1A). The server decides every state, count and amount (there is no month-wide
 * Generate); these helpers only decide how a state is worded and coloured, which button a company row offers, how the list of every assigned company is
 * joined with the companies that already have a payroll section, and when the month may be called "All Companies Paid". Visible wording comes from the
 * language catalogue.
 */

import type { MessageKey } from '../i18n/messages/index.ts';
import type {
  CompanyPayrollState,
  GenerateExpectedStatus,
  PayrollOverview,
  PayrollOverviewCompany,
  PayrollOverviewSummary,
  PayrollRunSection,
} from '../types/payroll.ts';

type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger';

/** How each state is worded and coloured. Draft / Reviewed / Finalized / Partially paid / Overpaid reuse the words the screen already had. */
export const COMPANY_STATE_LABEL: Record<CompanyPayrollState, { labelKey: MessageKey; tone: Tone }> = {
  not_generated: { labelKey: 'status.payroll.state.notGenerated', tone: 'neutral' },
  draft: { labelKey: 'option.payrollStatus.draft', tone: 'neutral' },
  reviewed: { labelKey: 'option.payrollStatus.reviewed', tone: 'info' },
  finalized: { labelKey: 'option.payrollStatus.finalized', tone: 'warning' },
  partially_paid: { labelKey: 'status.payroll.apPartial', tone: 'warning' },
  paid: { labelKey: 'status.payroll.state.paid', tone: 'success' },
  overpaid: { labelKey: 'status.payroll.apOverpaid', tone: 'danger' },
};

/** The badge of a company row: a generated company with nothing to pay says so ("Generated — Nothing Payable") instead of a plain state. */
export function companyStateBadge(row: Pick<PayrollOverviewCompany, 'state' | 'generated' | 'nothing_payable'>): { labelKey: MessageKey; tone: Tone } {
  if (row.generated && row.nothing_payable) return { labelKey: 'status.payroll.state.nothingPayable', tone: 'neutral' };

  return COMPANY_STATE_LABEL[row.state];
}

/** An amount the server sends as a string, or "—" while the company is not generated (never 0, never an estimate). */
export function amountOrDash(value: string | null | undefined, format: (amount: number) => string): string {
  if (value === null || value === undefined || value === '') return '—';
  const amount = Number(value);

  return Number.isFinite(amount) ? format(amount) : '—';
}

/**
 * Generate is offered for a company with no section yet, a Draft one or a Reviewed one - never for one that is finalized, paid or locked by an earlier
 * version. It needs hr.payroll.manage (the server checks it again).
 */
export function canGenerateCompany(row: Pick<PayrollOverviewCompany, 'generated' | 'section_status' | 'legacy_approved'>, canManage: boolean): boolean {
  if (!canManage) return false;
  if (!row.generated) return true;

  return !row.legacy_approved && (row.section_status === 'draft' || row.section_status === 'reviewed');
}

/** The section status the screen showed, sent so a change made by someone else in the meantime is refused; omitted for a company not generated yet. */
export function expectedStatusOf(row: Pick<PayrollOverviewCompany, 'generated' | 'section_status'>): GenerateExpectedStatus | undefined {
  if (!row.generated) return undefined;

  return row.section_status === 'draft' || row.section_status === 'reviewed' ? row.section_status : undefined;
}

/** True when the company's Generate would reset a review (the dialog says so). */
export function generateResetsReview(row: Pick<PayrollOverviewCompany, 'generated' | 'section_status'>): boolean {
  return row.generated && row.section_status === 'reviewed';
}

/** "Reopen for correction" is not offered to anyone but a Super Admin once payments are recorded on a finalized company (the server answers 403 otherwise). */
export function reopenNeedsSuperAdmin(section: Pick<PayrollRunSection, 'status' | 'has_payments'>, isSuperAdmin: boolean): boolean {
  return section.status === 'finalized' && section.has_payments === true && !isSuperAdmin;
}

/**
 * "<Month> — All Companies Paid" is shown ONLY when the server says every company is paid AND no company is waiting to be generated; otherwise the
 * month shows its counts. A month with no assigned company is never "all paid".
 */
export function showAllCompaniesPaid(summary: Pick<PayrollOverviewSummary, 'all_companies_paid' | 'not_generated' | 'companies_assigned'>): boolean {
  return summary.all_companies_paid && summary.not_generated === 0 && summary.companies_assigned > 0;
}

/** The counts of the month card, in the order the owner listed them. */
export function summaryCounts(summary: PayrollOverviewSummary): { labelKey: MessageKey; value: number }[] {
  return [
    { labelKey: 'option.payroll.sumAssigned', value: summary.companies_assigned },
    { labelKey: 'option.payroll.generated', value: summary.companies_generated },
    { labelKey: 'status.payroll.state.notGenerated', value: summary.not_generated },
    { labelKey: 'option.payrollStatus.draft', value: summary.draft },
    { labelKey: 'option.payrollStatus.reviewed', value: summary.reviewed },
    { labelKey: 'status.payroll.state.finalizedUnpaid', value: summary.finalized_unpaid },
    { labelKey: 'status.payroll.apPartial', value: summary.partially_paid },
    { labelKey: 'status.payroll.state.paid', value: summary.paid },
    { labelKey: 'status.payroll.apOverpaid', value: summary.overpaid },
  ];
}

/** One line of the company list: the overview row (every assigned company) joined with the payroll section it already has, if any. */
export interface CompanyListRow {
  key: string;
  overview: PayrollOverviewCompany | null;
  section: PayrollRunSection | null;
}

/**
 * Every assigned company of the month (from the overview), each with its section when it has been generated. A section the overview does not list is kept
 * as a row of its own, so none of its buttons ever disappears; while the overview is not available the rows are the sections alone.
 */
export function buildCompanyRows(overview: PayrollOverview | undefined, sections: PayrollRunSection[]): CompanyListRow[] {
  if (!overview) return sections.map((section) => ({ key: `s-${section.id}`, overview: null, section }));

  const used = new Set<number>();
  const rows = overview.companies.map((company): CompanyListRow => {
    const section =
      sections.find((candidate) => candidate.id === company.section_id) ??
      (company.generated ? sections.find((candidate) => !used.has(candidate.id) && candidate.client_company_id === company.client_company_id) : undefined) ??
      null;
    if (section) used.add(section.id);

    return { key: section ? `s-${section.id}` : `c-${company.client_company_id ?? 'none'}`, overview: company, section };
  });

  const orphans = sections.filter((section) => !used.has(section.id)).map((section): CompanyListRow => ({ key: `s-${section.id}`, overview: null, section }));

  return [...rows, ...orphans];
}

/** The path segment of a company in the generate / warnings endpoints: its id, or the word `none` for the "no company" lines. */
export function companySegment(clientCompanyId: number | null): string {
  return clientCompanyId === null ? 'none' : String(clientCompanyId);
}

/** A month as the month picker produces it. */
export function isPayrollPeriod(value: string): boolean {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(value);
}

/**
 * The cards of the dashboard's Company payroll group: the three money figures (as the server's strings) and the counts that matter at a glance - Paid,
 * Partially Paid, Finalized (unpaid) and Not Generated - each kept apart, so a company that was never generated is not hidden inside "unpaid".
 */
export function dashboardPayrollCards(summary: PayrollOverviewSummary): { labelKey: MessageKey; value: string | number; currency: boolean }[] {
  return [
    { labelKey: 'option.hr.kpiCompanyPayrollTotal', value: summary.payroll_total, currency: true },
    { labelKey: 'option.hr.kpiCompanyPaymentsReceived', value: summary.payments_received, currency: true },
    { labelKey: 'option.hr.kpiCompanyOutstanding', value: summary.outstanding, currency: true },
    { labelKey: 'status.payroll.state.paid', value: summary.paid, currency: false },
    { labelKey: 'status.payroll.apPartial', value: summary.partially_paid, currency: false },
    { labelKey: 'status.payroll.state.finalizedUnpaid', value: summary.finalized_unpaid, currency: false },
    { labelKey: 'status.payroll.state.notGenerated', value: summary.not_generated, currency: false },
  ];
}
