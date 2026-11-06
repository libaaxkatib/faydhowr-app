import test from 'node:test';
import assert from 'node:assert/strict';
import { r1cReviewOf } from '../i18n/r1cApprovals.ts';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { placeholdersOf } from '../i18n/catalog.ts';
import {
  COMPANY_STATE_LABEL,
  amountOrDash,
  buildCompanyRows,
  canGenerateCompany,
  companySegment,
  companyStateBadge,
  dashboardPayrollCards,
  expectedStatusOf,
  generateResetsReview,
  isPayrollPeriod,
  reopenNeedsSuperAdmin,
  showAllCompaniesPaid,
  summaryCounts,
} from './payrollOverview.ts';
import { MAX_PAYMENT_REFERENCE, companyPaymentPayload, sectionActions } from './payrollSections.ts';
import type { CompanyPayrollState, PayrollOverview, PayrollOverviewCompany, PayrollOverviewSummary, PayrollRunSection } from '../types/payroll.ts';

/**
 * HR Release 1A, web panel: every assigned company has a state, Generate is per company, the month card says "All Companies Paid" only when it is true,
 * a company that was never generated shows "—" (never 0), the payment reference is optional, and the warnings never block anything. The server decides
 * every state and amount; these checks cover what the panel decides.
 */

const api = readFileSync(new URL('../api/hr.ts', import.meta.url), 'utf8');
const page = readFileSync(new URL('../features/hrm/PayrollRunsPage.tsx', import.meta.url), 'utf8');
const panel = readFileSync(new URL('../features/hrm/CompanySectionsPanel.tsx', import.meta.url), 'utf8');
const generateDialog = readFileSync(new URL('../features/hrm/GenerateCompanyDialog.tsx', import.meta.url), 'utf8');
const actionDialog = readFileSync(new URL('../features/hrm/SectionActionDialog.tsx', import.meta.url), 'utf8');
const warningsPanel = readFileSync(new URL('../features/hrm/GenerationWarningsPanel.tsx', import.meta.url), 'utf8');
const paymentDialog = readFileSync(new URL('../features/hrm/CompanyPaymentDialog.tsx', import.meta.url), 'utf8');
const workplacePanel = readFileSync(new URL('../features/hrm/WorkplacePaymentsPanel.tsx', import.meta.url), 'utf8');
const dashboard = readFileSync(new URL('../features/hrm/HrDashboardPage.tsx', import.meta.url), 'utf8');

function company(overrides: Partial<PayrollOverviewCompany> = {}): PayrollOverviewCompany {
  return {
    client_company_id: 5, company_name: 'Alpha Co', category: 'company', state: 'draft', generated: true, nothing_payable: false, legacy_approved: false, section_id: 11, section_status: 'draft',
    generated_at: '2026-11-05T08:00:00Z', generation_warning_count: 0, payroll_total_cents: 60000, payments_received_cents: 0, outstanding_cents: 60000, payroll_total: '600.00', payments_received: '0.00', outstanding: '600.00', ...overrides,
  };
}

function notGenerated(overrides: Partial<PayrollOverviewCompany> = {}): PayrollOverviewCompany {
  return company({ state: 'not_generated', generated: false, section_id: null, section_status: null, generated_at: null, payroll_total_cents: null, payments_received_cents: null, outstanding_cents: null, payroll_total: null, payments_received: null, outstanding: null, ...overrides });
}

function summary(overrides: Partial<PayrollOverviewSummary> = {}): PayrollOverviewSummary {
  return {
    companies_assigned: 3, companies_generated: 3, not_generated: 0, draft: 0, reviewed: 0, finalized_unpaid: 0, partially_paid: 0, paid: 3, overpaid: 0,
    payroll_total_cents: 150000, payments_received_cents: 150000, outstanding_cents: 0, payroll_total: '1500.00', payments_received: '1500.00', outstanding: '0.00', all_companies_paid: true, ...overrides,
  };
}

function section(overrides: Partial<PayrollRunSection> = {}): PayrollRunSection {
  return {
    id: 11, client_company_id: 5, company_name: 'Alpha Co', category: 'company', status: 'draft', employee_count: 3, item_count: 3, gross: '600.00', net: '600.00', payable: '600.00', payable_cents: 60000, paid: '0.00', paid_cents: 0, outstanding: '600.00', outstanding_cents: 60000,
    payment_status: 'unpaid', approved: false, can_pay: false, reviewed_at: null, reviewed_by: null, approved_at: null, approved_by: null, finalized_at: null, finalized_by: null, ...overrides,
  };
}

const format = (amount: number) => `$${amount.toFixed(2)}`;

// ------------------------------------------------------------------ the state of each company

test('every state has proposed-or-approved wording in both languages and a tone; a generated company with nothing to pay says so', () => {
  const states: CompanyPayrollState[] = ['not_generated', 'draft', 'reviewed', 'finalized', 'partially_paid', 'paid', 'overpaid'];
  for (const state of states) {
    const entry = CATALOGUE[COMPANY_STATE_LABEL[state].labelKey];
    assert.ok(entry.en.trim() && entry.so.trim(), state);
  }
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.not_generated.labelKey].en, 'Not Generated');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.draft.labelKey].en, 'Draft');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.reviewed.labelKey].en, 'Reviewed');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.finalized.labelKey].en, 'Finalized');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.partially_paid.labelKey].en, 'Partially paid');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.paid.labelKey].en, 'Paid');
  assert.equal(CATALOGUE[COMPANY_STATE_LABEL.overpaid.labelKey].en, 'Overpaid');
  // Existing words are reused, never re-translated: Draft / Reviewed / Finalized keep their owner-approved Somali.
  for (const state of ['draft', 'reviewed', 'finalized'] as const) assert.equal(CATALOGUE[COMPANY_STATE_LABEL[state].labelKey].review, 'approved', state);
  assert.equal(COMPANY_STATE_LABEL.paid.tone, 'success');
  assert.equal(COMPANY_STATE_LABEL.partially_paid.tone, 'warning', 'Partially paid is never the green of Paid');
  assert.equal(COMPANY_STATE_LABEL.overpaid.tone, 'danger');

  assert.equal(CATALOGUE[companyStateBadge(company({ nothing_payable: true, state: 'draft' })).labelKey].en, 'Generated — Nothing Payable');
  assert.equal(companyStateBadge(company({ state: 'reviewed' })).labelKey, COMPANY_STATE_LABEL.reviewed.labelKey);
  assert.equal(companyStateBadge(notGenerated({ nothing_payable: true })).labelKey, COMPANY_STATE_LABEL.not_generated.labelKey, 'a company that was never generated cannot be "nothing payable"');
});

test('a company that is not generated shows "—" for every amount, never 0 and never an estimate', () => {
  assert.equal(amountOrDash(null, format), '—');
  assert.equal(amountOrDash(undefined, format), '—');
  assert.equal(amountOrDash('', format), '—');
  assert.equal(amountOrDash('abc', format), '—');
  assert.equal(amountOrDash('600.00', format), '$600.00');
  assert.equal(amountOrDash('0.00', format), '$0.00', 'a generated company with nothing payable really is $0.00');
  const notYet = notGenerated();
  assert.deepEqual([notYet.payroll_total, notYet.payments_received, notYet.outstanding].map((value) => amountOrDash(value, format)), ['—', '—', '—']);

  // The list shows a row's figures only from a generated section: a company without one gets a dash.
  assert.match(panel, /section \? money\(pick\(section\)\) : amountOrDash\(null, formatCurrency\)/);
});

// ------------------------------------------------------------------ the per-company Generate

test('Generate is offered for a company with no section, a Draft or a Reviewed section - never for a finalized, paid or locked one, never without hr.payroll.manage', () => {
  assert.equal(canGenerateCompany(notGenerated(), true), true);
  assert.equal(canGenerateCompany(company({ section_status: 'draft' }), true), true);
  assert.equal(canGenerateCompany(company({ section_status: 'reviewed', state: 'reviewed' }), true), true);
  assert.equal(canGenerateCompany(company({ section_status: 'finalized', state: 'finalized' }), true), false);
  assert.equal(canGenerateCompany(company({ section_status: 'finalized', state: 'paid' }), true), false);
  assert.equal(canGenerateCompany(company({ section_status: 'approved', state: 'reviewed', legacy_approved: true }), true), false, 'locked by an earlier version');
  assert.equal(canGenerateCompany(notGenerated(), false), false);
  assert.equal(canGenerateCompany(company(), false), false);
});

test('the request carries the section status the screen showed, and nothing for a company that is not generated', () => {
  assert.equal(expectedStatusOf(notGenerated()), undefined);
  assert.equal(expectedStatusOf(company({ section_status: 'draft' })), 'draft');
  assert.equal(expectedStatusOf(company({ section_status: 'reviewed' })), 'reviewed');
  assert.equal(expectedStatusOf(company({ section_status: 'finalized' })), undefined);
  assert.equal(generateResetsReview(company({ section_status: 'reviewed' })), true);
  assert.equal(generateResetsReview(company({ section_status: 'draft' })), false);
  assert.equal(generateResetsReview(notGenerated()), false);
  assert.equal(companySegment(7), '7');
  assert.equal(companySegment(null), 'none');

  assert.match(api, /generateCompany: \(period: string, company: number \| null, expectedStatus\?: GenerateExpectedStatus\)[\s\S]{0,260}payroll\/\$\{period\}\/companies\/\$\{companySegment\(company\)\}\/generate[\s\S]{0,120}method: 'POST'[\s\S]{0,80}expected_status: expectedStatus/);
  assert.match(api, /companyWarnings: \(period: string, company: number \| null\) => apiRequest<CompanyWarnings>\(`admin\/hr\/payroll\/\$\{period\}\/companies\/\$\{companySegment\(company\)\}\/warnings`\)/);
  assert.match(api, /overview: \(period: string\) => apiRequest<PayrollOverview>\(`admin\/hr\/payroll\/\$\{period\}\/overview`\)/);
  assert.match(generateDialog, /hrApi\.payrollRuns\.generateCompany\(period, row\.client_company_id, expectedStatusOf\(row\)\)/);
});

test('the month-wide Generate, the month-level Review and the month-level Reopen are all gone from the client and the page; the per-company reopen stays', () => {
  assert.ok(!/generate: \(period/.test(api) && !/body: \{ period \}/.test(api), 'no hrApi.payrollRuns.generate(period)');
  assert.ok(!/\n {4}review: \(id: number\)/.test(api), 'no hrApi.payrollRuns.review(id)');
  assert.ok(!/payroll-runs\/\$\{id\}\/review/.test(api));
  assert.ok(!/payrollRuns\.generate\(|payrollRuns\.review\(/.test(page + panel));
  assert.ok(!/generateMutation|reviewMutation|onReview|busy\.review/.test(page), 'no month-level Generate / Review mutation or button');
  // Owner decision O-1 (2026-11-05): there is no month-level Reopen - no client function, no mutation, no button, no dialog.
  assert.ok(!/payroll-runs\/\$\{id\}\/reopen/.test(api), 'no hrApi.payrollRuns.reopen(id)');
  assert.ok(!/payrollRuns\.reopen\(/.test(page + panel), 'nothing calls a month-level reopen');
  assert.ok(!/reopenMutation|isReopenOpen|onReopen|ReopenModal/.test(page), 'no month-level Reopen mutation, button or dialog on the Payroll page');
  // The per-company reopen is untouched: its own client function and the company row's button.
  assert.match(api, /reopenSection: \(sectionId: number, reason: string\) => apiRequest<PayrollRun>\(`admin\/hr\/payroll-run-sections\/\$\{sectionId\}\/reopen`, \{ method: 'PATCH', body: \{ reason \} \}\)/);
  assert.match(panel, /hrApi\.payrollRuns\.reopenSection\(section\.id, reason \?\? ''\)/);
});

test('the per-company Generate button reuses the owner-approved "Generate / refresh draft" verbatim', () => {
  assert.equal(CATALOGUE['action.generateDraft'].review, 'approved');
  assert.equal(CATALOGUE['action.generateDraft'].en, 'Generate / refresh draft');
  assert.equal(CATALOGUE['action.generateDraft'].so, 'Samee / Cusboonaysii Qabyo');
  assert.match(page, /generateLabel=\{t\('action\.generateDraft'\)\}/);
  assert.match(panel, /\{generateLabel\}/);
  assert.match(generateDialog, /\{t\('action\.generateDraft'\)\}/);
});

// ------------------------------------------------------------------ warnings are warnings only

test('the warnings never disable a button and never add a mandatory confirmation', () => {
  for (const [name, text] of [['generate dialog', generateDialog], ['review / finalize dialog', actionDialog], ['warnings panel', warningsPanel]] as const) {
    assert.ok(!/type="checkbox"|checked=|setAcknowledged|acknowledge/i.test(text), `${name}: no confirmation checkbox`);
    assert.ok(!/disabled=\{[^}]*(warning|Warning)/.test(text), `${name}: a warning never disables a button`);
  }
  assert.ok(!/disabled=/.test(generateDialog.replace(/disabled=\{mutation\.isPending\}/g, '')), 'the generate button is always available (only while it is being sent is it busy)');
  // UI audit U-1 (this assertion described the old behaviour): the confirm button is off ONLY while a stale-draft notice is shown, because the server would
  // refuse the step anyway. The Temporary Replacement warnings still never disable it (the first check above), and it is never off for any other reason.
  assert.ok(!/disabled=/.test(actionDialog.replace(/disabled=\{isLoading\}/g, '').replace(/disabled=\{blocked\}/g, '')), 'review / finalize confirm is off only while a stale-draft notice is shown');
  assert.match(actionDialog, /const blocked = reviewIsBlocked\(warnings, refusedChanges !== null\);/);
  assert.match(generateDialog, /<GenerationWarningsPanel period=\{period\} company=\{row\.client_company_id\}( context="generate")? \/>/);
  assert.match(actionDialog, /<GenerationWarningsPanel period=\{period\} company=\{company\}( context="action")? \/>/);
  assert.match(panel, /<SectionActionDialog[\s\S]{0,300}sectionReviewTitle/);
  assert.match(panel, /<SectionActionDialog[\s\S]{0,300}sectionFinalizeTitle/);
  // The texts come from the server and are shown as given.
  assert.match(warningsPanel, /\{warning\.detail\}/);
  assert.match(warningsPanel, /what_remains/);
});

// ------------------------------------------------------------------ the month card

test('"All Companies Paid" is shown only when the server says so AND no company is waiting to be generated', () => {
  assert.equal(showAllCompaniesPaid(summary()), true);
  assert.equal(showAllCompaniesPaid(summary({ all_companies_paid: false })), false);
  assert.equal(showAllCompaniesPaid(summary({ all_companies_paid: true, not_generated: 1 })), false, 'never while a company is not generated');
  assert.equal(showAllCompaniesPaid(summary({ all_companies_paid: true, companies_assigned: 0, companies_generated: 0, paid: 0 })), false, 'a month with no company is not "all paid"');

  const entry = CATALOGUE['status.payroll.allCompaniesPaid'];
  assert.equal(entry.review, r1cReviewOf('status.payroll.allCompaniesPaid'));
  assert.deepEqual(placeholdersOf(entry.en), ['month']);
  assert.deepEqual(placeholdersOf(entry.so), ['month']);
  assert.equal(entry.en, '{month} — All Companies Paid');
});

test('when it is not all paid the month card lists the counts in the owner\'s order, and the figures come from the summary untouched', () => {
  const counts = summaryCounts(summary({ all_companies_paid: false, companies_assigned: 9, companies_generated: 8, not_generated: 1, draft: 2, reviewed: 1, finalized_unpaid: 1, partially_paid: 1, paid: 2, overpaid: 1 }));
  assert.deepEqual(counts.map((c) => c.value), [9, 8, 1, 2, 1, 1, 1, 2, 1]);
  assert.deepEqual(counts.map((c) => CATALOGUE[c.labelKey].en), ['Assigned', 'Generated', 'Not Generated', 'Draft', 'Reviewed', 'Finalized (unpaid)', 'Partially paid', 'Paid', 'Overpaid']);

  const card = readFileSync(new URL('../features/hrm/MonthSummaryCard.tsx', import.meta.url), 'utf8');
  for (const field of ['summary.payroll_total', 'summary.payments_received', 'summary.outstanding']) assert.ok(card.includes(field), field);
  assert.ok(!/reduce\(|Number\(summary\./.test(card), 'nothing is added up in the panel');
  assert.match(card, /\{!allPaid && \(/, 'the counts appear only when the month is not all paid');
});

// ------------------------------------------------------------------ the list

test('the list holds every assigned company, joined with its section when it has one, and keeps a section the overview does not list', () => {
  const overview: PayrollOverview = {
    period: '2026-10', run_id: 4, summary: summary(),
    companies: [company({ section_id: 11 }), notGenerated({ client_company_id: 6, company_name: 'Beta Co' }), company({ client_company_id: null, company_name: null, section_id: 13, generated: true })],
  };
  const sections = [section({ id: 11 }), section({ id: 13, client_company_id: null, company_name: null }), section({ id: 99, client_company_id: 77, company_name: 'Gone Co' })];
  const rows = buildCompanyRows(overview, sections);

  assert.deepEqual(rows.map((r) => r.key), ['s-11', 'c-6', 's-13', 's-99']);
  assert.equal(rows[1].section, null, 'Beta Co is not generated: no section, only Generate');
  assert.equal(rows[3].overview, null, 'a section the overview does not list keeps its row');
  assert.deepEqual(buildCompanyRows(undefined, sections).map((r) => r.key), ['s-11', 's-13', 's-99'], 'without the overview the sections are still listed');
  assert.deepEqual(buildCompanyRows({ ...overview, companies: [] }, []), []);
});

// ------------------------------------------------------------------ reopening a finalized company that has payments

test('a finalized company with recorded payments is reopened by a Super Admin only: no button for anyone else, a hint instead', () => {
  const paid = section({ status: 'finalized', has_payments: true });
  assert.equal(reopenNeedsSuperAdmin(paid, false), true);
  assert.equal(reopenNeedsSuperAdmin(paid, true), false);
  assert.equal(reopenNeedsSuperAdmin(section({ status: 'finalized', has_payments: false }), false), false);
  assert.equal(reopenNeedsSuperAdmin(section({ status: 'finalized' }), false), false);
  assert.equal(reopenNeedsSuperAdmin(section({ status: 'reviewed', has_payments: true }), false), false);

  assert.deepEqual(sectionActions('finalized', true, true, true), []);
  assert.deepEqual(sectionActions('finalized', true, true, false), ['reopen_finalized']);
  assert.deepEqual(sectionActions('finalized', true, true), ['reopen_finalized'], 'the old call is unchanged');
  assert.deepEqual(sectionActions('reviewed', true, true, true), ['finalize', 'reopen'], 'only the finalized reopen is restricted');

  assert.match(panel, /reopenNeedsSuperAdmin\(section, isSuperAdmin\)/);
  assert.match(panel, /warning\.payroll\.reopenPaidSuperAdmin/);
  assert.match(page, /isSuperAdmin=\{isSuperAdmin\}/);
  assert.match(page, /const \{ admin, isSuperAdmin \} = useAuth\(\)/);
  assert.match(readFileSync(new URL('./hrHelpers.ts', import.meta.url), 'utf8'), /'PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY'/);
});

// ------------------------------------------------------------------ the optional payment reference

test('the payment reference is optional: trimmed, left out when empty, and never required to pay', () => {
  const base = { mode: 'full' as const, payment_date: '2026-11-05', note: null, idempotency_key: 'key-1234567' };
  assert.deepEqual(companyPaymentPayload('full', '', '2026-11-05', '', 'key-1234567'), base, 'no reference argument');
  assert.deepEqual(companyPaymentPayload('full', '', '2026-11-05', '', 'key-1234567', ''), base);
  assert.deepEqual(companyPaymentPayload('full', '', '2026-11-05', '', 'key-1234567', '   '), base, 'blank is omitted');
  assert.deepEqual(companyPaymentPayload('full', '', '2026-11-05', '', 'key-1234567', '  TX-998877 '), { ...base, reference: 'TX-998877' });
  assert.ok(!('reference' in companyPaymentPayload('partial', '10', '2026-11-05', '', 'key-1234567', '')));
  assert.equal(MAX_PAYMENT_REFERENCE, 100);

  assert.match(paymentDialog, /maxLength=\{MAX_PAYMENT_REFERENCE\}/);
  assert.match(paymentDialog, /placeholder=\{t\('option\.payroll\.paymentReferencePlaceholder'\)\}/);
  assert.ok(!/const ready =[^\n]*reference/.test(paymentDialog), 'the reference never decides whether the payment can be recorded');
  assert.match(workplacePanel, /payment\.reference \?\? '—'/);
  for (const key of ['option.payroll.paymentReference', 'option.payroll.paymentReferencePlaceholder', 'option.payroll.wpReference'] as const) assert.equal(CATALOGUE[key].review, 'proposed', key);
});

// ------------------------------------------------------------------ the dashboard

test('the dashboard shows the Company payroll group with its own month and no employee-payment card', () => {
  const cards = dashboardPayrollCards(summary({ all_companies_paid: false, paid: 1, partially_paid: 2, finalized_unpaid: 3, not_generated: 4 }));
  assert.deepEqual(cards.map((c) => c.value), ['1500.00', '1500.00', '0.00', 1, 2, 3, 4]);
  assert.deepEqual(cards.map((c) => c.currency), [true, true, true, false, false, false, false]);
  assert.deepEqual(cards.map((c) => CATALOGUE[c.labelKey].en), [
    'Company payroll total', 'Company payments received', 'Outstanding company payroll balance', 'Paid', 'Partially paid', 'Finalized (unpaid)', 'Not Generated',
  ]);
  assert.equal(isPayrollPeriod('2026-10'), true);
  assert.equal(isPayrollPeriod(''), false);
  assert.equal(isPayrollPeriod('2026-13'), false);

  assert.match(dashboard, /\{data\.payroll && \(/, 'the group exists only when the server sent payroll');
  assert.match(dashboard, /value=\{payrollMonth \?\? data\.payroll\.period\}/, 'defaults to the month the server returned (the previous month)');
  assert.match(dashboard, /hrApi\.dashboard\(payrollMonth \?\? undefined\)/);
  assert.match(api, /dashboard: \(payrollPeriod\?: string\) =>\s+apiRequest<HrDashboardData>\('admin\/hr\/dashboard', payrollPeriod \? \{ query: \{ payroll_period: payrollPeriod \} \} : undefined\)/);
  assert.match(dashboard, /<KpiCard/);
  assert.ok(!/month_payments_total/.test(dashboard), 'the employee-payment total is no longer displayed');
  assert.ok(!/month_payments_total/.test(readFileSync(new URL('../types/employee.ts', import.meta.url), 'utf8')));
});

test('every new message is a proposal with the same placeholders in English and Somali', () => {
  const keys = Object.keys(CATALOGUE).filter((key) =>
    /^(status\.payroll\.state\.|status\.payroll\.allCompaniesPaid|warning\.payroll\.(gen\.|legacyApproved|generatePerCompanyNote|reopenPaidSuperAdmin)|option\.payroll\.(colState|allCompaniesTitle|loadingCompanies|noCompaniesAssigned|warningCount|sum|paymentReference|wpReference)|confirm\.payroll\.(generateCompany|sectionReview)|notify\.payroll\.(companyGenerated|sectionChanged)|notify\.error\.(PAID_COMPANY|PAYROLL_SECTION_CHANGED|PAYROLL_COMPANY_LOCKED|COMPANY_NOT_ASSIGNED)|option\.hr\.(companyPayroll|kpiCompany))/.test(key),
  );
  assert.ok(keys.length >= 45, `found ${keys.length}`);
  for (const key of keys) {
    const entry = CATALOGUE[key as keyof typeof CATALOGUE];
    assert.equal(entry.review, r1cReviewOf(key), key);
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.deepEqual(placeholdersOf(entry.en), placeholdersOf(entry.so), `${key}: the same placeholders in both languages`);
  }
});
