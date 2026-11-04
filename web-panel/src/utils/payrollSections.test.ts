import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { placeholdersOf } from '../i18n/catalog.ts';
import { MONTH_STATUS_LABEL, PAYMENT_STATUS_LABEL, canReconcile, companyPaymentPayload, itemsOfSection, lineIsFinalized, monthDisplayStatus, paymentActions, sectionActions, sectionName, selectedSection } from './payrollSections.ts';
import type { PayrollItem, PayrollRunSection } from '../types/payroll.ts';

/**
 * Company-level payroll on the monthly screen. The server decides every status and total (tested in CompanyPayrollSectionsTest); these checks cover
 * what the panel decides: which buttons each company row offers, how a partly finalized month is worded, which lines are payable, that each button acts
 * on its own company only, and that every word is in the language catalogue (Somali and English, written as a proposal).
 */

const panel = readFileSync(new URL('../features/hrm/CompanySectionsPanel.tsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('../features/hrm/PayrollRunsPage.tsx', import.meta.url), 'utf8');
const api = readFileSync(new URL('../api/hr.ts', import.meta.url), 'utf8');

const KEYS = [
  'status.payroll.partiallyFinalized', 'option.payroll.companiesFinalized', 'option.payroll.sectionsTitle', 'option.payroll.sectionsHelp', 'option.payroll.sectionHistory',
  'option.payroll.sectionNoCompany', 'option.payroll.sectionShowLines', 'option.payroll.sectionAllLines', 'option.payroll.sectionFilteredBy',
  'confirm.payroll.sectionFinalizeTitle', 'confirm.payroll.sectionFinalizeBody', 'confirm.payroll.sectionReopenBody', 'warning.payroll.wpCompanyNotFinalized',
] as const;

function section(overrides: Partial<PayrollRunSection> = {}): PayrollRunSection {
  return {
    id: 1, client_company_id: 5, company_name: 'Alpha Co', category: 'company', status: 'draft', employee_count: 3, item_count: 3, gross: '600.00', net: '600.00', payable: '600.00', payable_cents: 60000, paid: '0.00', paid_cents: 0, outstanding: '600.00', outstanding_cents: 60000,
    payment_status: 'unpaid', approved: false, can_pay: false, reviewed_at: null, reviewed_by: null, approved_at: null, approved_by: null, finalized_at: null, finalized_by: null, ...overrides,
  };
}

test('each company row offers only the next step, only to someone allowed, and a finalized company offers none', () => {
  assert.deepEqual(sectionActions('draft', true, true), ['review']);
  assert.deepEqual(sectionActions('draft', false, true), [], 'review needs hr.payroll.manage');
  // There is no Approve step: a reviewed company is finalized straight away (or sent back to draft).
  assert.deepEqual(sectionActions('reviewed', true, true), ['finalize', 'reopen']);
  assert.deepEqual(sectionActions('reviewed', true, false), [], 'finalize and reopen need hr.payroll.approve');
  assert.deepEqual(sectionActions('approved', true, true), ['finalize', 'reopen'], 'a company an earlier version left approved is treated like a reviewed one');
  assert.deepEqual(sectionActions('approved', true, false), []);
  assert.deepEqual(sectionActions('finalized', true, true), ['reopen_finalized'], 'owner decision 2026-11-04: a finalized company can be reopened for correction (with a reason)');
  assert.deepEqual(sectionActions('finalized', true, false), [], 'reopening finalized payroll needs hr.payroll.approve');
  assert.deepEqual(sectionActions('finalized', false, false), []);
});

test('a month with some, but not all, companies finalized is shown as partially finalized; otherwise its own status', () => {
  assert.equal(monthDisplayStatus({ status: 'draft', is_partially_finalized: true }), 'partially_finalized');
  assert.equal(monthDisplayStatus({ status: 'draft', is_partially_finalized: false }), 'draft');
  assert.equal(monthDisplayStatus({ status: 'finalized', is_partially_finalized: false }), 'finalized');
  assert.equal(monthDisplayStatus({ status: 'approved' }), 'approved');
  assert.equal(CATALOGUE[MONTH_STATUS_LABEL.partially_finalized.labelKey].en, 'Partially finalized');
  assert.equal(MONTH_STATUS_LABEL.partially_finalized.tone, 'warning', 'not the success colour of a finalized month');
  assert.equal(MONTH_STATUS_LABEL.finalized.tone, 'success');
});

test('a line is payable when its own company is finalized, whatever the other companies of the month are', () => {
  const run = { status: 'draft' as const, sections: [section({ id: 1, status: 'finalized' }), section({ id: 2, status: 'draft' }), section({ id: 3, status: 'approved' })] };

  assert.equal(lineIsFinalized(run, { section_id: 1 }), true);
  assert.equal(lineIsFinalized(run, { section_id: 2 }), false);
  assert.equal(lineIsFinalized(run, { section_id: 3 }), false, 'approved is not finalized');
  assert.equal(lineIsFinalized(run, { section_id: null }), false);
  assert.equal(lineIsFinalized({ status: 'finalized' as const, sections: [] }, { section_id: null }), true, 'a month without company sections follows the month');
  assert.equal(lineIsFinalized({ status: 'draft' as const }, { section_id: 9 }), false);
});

test('selecting a company shows only its lines; an unknown company shows all', () => {
  const items = [{ id: 1, section_id: 10 }, { id: 2, section_id: 11 }, { id: 3, section_id: 10 }, { id: 4, section_id: null }] as PayrollItem[];

  assert.deepEqual(itemsOfSection(items, 10).map((i) => i.id), [1, 3]);
  assert.deepEqual(itemsOfSection(items, null).map((i) => i.id), [1, 2, 3, 4]);
  assert.deepEqual(itemsOfSection(items, 99), []);

  const sections = [section({ id: 10 }), section({ id: 11 })];
  assert.equal(selectedSection(sections, 11)?.id, 11);
  assert.equal(selectedSection(sections, 99), null, 'a company that is no longer in the month drops the filter');
  assert.equal(selectedSection(sections, null), null);
  assert.equal(sectionName(section({ company_name: ' Alpha Co ' }), 'No company'), 'Alpha Co');
  assert.equal(sectionName(section({ company_name: null }), 'No company'), 'No company');
});

test('every button calls the endpoint of ITS company only', () => {
  assert.match(api, /reviewSection: \(sectionId: number\) => apiRequest<PayrollRun>\(`admin\/hr\/payroll-run-sections\/\$\{sectionId\}\/review`, \{ method: 'PATCH' \}\)/);
  assert.ok(!/approveSection|payroll-run-sections\/\$\{sectionId\}\/approve|payroll-runs\/\$\{id\}\/approve/.test(api), 'there is no Approve call for a company or for the month');
  assert.match(api, /payCompany: \(sectionId: number, payload: RecordCompanyPaymentPayload\)[\s\S]{0,160}payroll-run-sections\/\$\{sectionId\}\/payments/);
  assert.match(api, /finalizeSection[\s\S]{0,140}payroll-run-sections\/\$\{sectionId\}\/finalize/);
  assert.match(api, /reopenSection[\s\S]{0,200}payroll-run-sections\/\$\{sectionId\}\/reopen[\s\S]{0,80}body: \{ reason \}/);
  assert.match(panel, /hrApi\.payrollRuns\.reviewSection\(section\.id\)/);
  assert.match(panel, /hrApi\.payrollRuns\.finalizeSection\(section\.id\)/);
  assert.ok(!/payrollRuns\.(review|approve|finalize|reopen)\(/.test(panel), 'the company table never calls a month-level action');
  assert.match(readFileSync(new URL('../features/hrm/CompanyPaymentDialog.tsx', import.meta.url), 'utf8'), /hrApi\.payrollRuns\.payCompany\(section\.id, companyPaymentPayload\(mode, amount, date, note, key\)\)/);
});

test('finalizing asks for confirmation naming the company, reopening asks for a reason, and nothing runs twice at once', () => {
  assert.match(panel, /if \(action === 'finalize'\) setFinalizing\(section\)/);
  assert.match(panel, /confirm\.payroll\.sectionFinalizeTitle/);
  assert.match(panel, /reason\.trim\(\)\.length < 10/);
  assert.match(panel, /disabled=\{busy\}/, 'the buttons lock while a request is running');
  assert.match(panel, /onClose=\{isLoading \? \(\) => undefined : onClose\}/);
});

test('the month shows its progress and the company table filters the lines; payments follow each line\'s company', () => {
  assert.match(panel, /option\.payroll\.companiesFinalized', \{ finalized: run\.sections_finalized \?\? 0, total: run\.sections_total \?\? sections\.length \}/);
  assert.match(page, /<CompanySectionsPanel run=\{run\}[\s\S]{0,160}selectedSectionId=\{shownSectionId\} onSelect=\{setSelectedSectionId\}/);
  assert.match(page, /<ItemsTable run=\{run\} sectionId=\{shownSectionId\}/);
  assert.match(page, /const lineFinal = \(item: PayrollItem\) => lineIsFinalized\(run, item\)/);
  assert.match(page, /monthDisplayStatus\(row\.original\)/, 'the month list says "partially finalized" too');
  assert.match(page, /run\.sectioned && \(run\.sections\?\.length \?\? 0\) > 0/, 'a month without company sections shows no company table');
});

test('the company table shows the figures the server sends and adds nothing up', () => {
  for (const field of ['section.gross', 'section.net', 'section.paid', 'section.outstanding', 'section.employee_count']) assert.ok(panel.includes(field), field);
  assert.ok(!/reduce\(/.test(panel), 'nothing is summed in the panel');
  assert.ok(!/Number\(section\./.test(panel), 'no arithmetic on a section\'s amounts');
});

test('every word of the company screen is a catalogue message with English and Somali, written as a proposal', () => {
  for (const key of KEYS) {
    const entry = CATALOGUE[key];
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.notEqual(entry.en, entry.so, `${key}: Somali differs from English`);
    assert.equal(entry.review, 'proposed', `${key}: new wording is a proposal, not owner-approved`);
    assert.deepEqual(placeholdersOf(entry.en), placeholdersOf(entry.so), `${key}: the same placeholders in both languages`);
  }

  assert.deepEqual(placeholdersOf(CATALOGUE['option.payroll.companiesFinalized'].en), ['finalized', 'total']);
  assert.deepEqual(placeholdersOf(CATALOGUE['confirm.payroll.sectionFinalizeBody'].en), ['month', 'name']);
  // The existing approved words are reused, never reworded.
  for (const key of ['action.finalize', 'action.reopen', 'action.markReviewed']) assert.equal(CATALOGUE[key as 'action.finalize'].review, 'approved', key);
});

test('the company panel writes no English text and no colour of its own (theme tokens only, so Light and Dark both work)', () => {
  const jsxText = [...panel.matchAll(/>\s*([A-Z][A-Za-z ,.'-]{3,})\s*</g)].map((match) => match[1]);
  assert.deepEqual(jsxText, [], 'no English words between tags');
  assert.ok(!/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\b(?:bg|text|border)-(?:gray|red|green|blue|yellow|amber|black|slate)/.test(panel));
});

// ------------------------------------------------------------------ payment-based approval (owner decision 2026-11-04)

test('"Approve Paid" and "Approve Partial" are offered only for a finalized company that still owes money, and only to someone who may record payments', () => {
  const owing = section({ status: 'finalized', can_pay: true, outstanding_cents: 60000, payment_status: 'unpaid' });

  assert.deepEqual(paymentActions(owing, true), ['approve_paid', 'approve_partial']);
  assert.deepEqual(paymentActions(owing, false), [], 'needs hr.payroll.approve');
  assert.deepEqual(paymentActions(section({ status: 'reviewed', can_pay: false }), true), [], 'a company that is not finalized cannot be paid');
  assert.deepEqual(paymentActions(section({ status: 'finalized', can_pay: true, outstanding_cents: 0, payment_status: 'approved', approved: true }), true), [], 'a fully paid company has nothing left to pay');
});

test('the two statuses of a company are worded and coloured apart: Partially paid is never the green of Approved', () => {
  assert.equal(CATALOGUE[PAYMENT_STATUS_LABEL.unpaid.labelKey].en, 'Unpaid');
  assert.equal(CATALOGUE[PAYMENT_STATUS_LABEL.partially_paid.labelKey].en, 'Partially paid');
  assert.equal(CATALOGUE[PAYMENT_STATUS_LABEL.approved.labelKey].en, 'Approved · Paid');
  assert.deepEqual([PAYMENT_STATUS_LABEL.unpaid.tone, PAYMENT_STATUS_LABEL.partially_paid.tone, PAYMENT_STATUS_LABEL.approved.tone], ['danger', 'warning', 'success']);
  assert.match(panel, /option\.payroll\.colPayrollStatus[\s\S]{0,200}option\.payroll\.colPaymentStatus/, 'both columns are shown side by side');
  assert.match(panel, /data-testid="section-payment-status"/);
});

test('there is no Approve button: not for the month and not for a company', () => {
  assert.ok(!/approvePayroll|approvePayrollButton|onApprove|approveMutation|confirming === 'approve'/.test(page), 'the month page has no Approve');
  assert.ok(!/approvePayroll|approvePayrollButton|'approve'/.test(panel.replace(/approve_paid|approve_partial|section-approve/g, '')), 'the company panel has no Approve step');
  assert.ok(!/sectionActions[\s\S]{0,40}'approve'/.test(panel));
});

test('the payment request sends the amount only for a partial payment, trims the note and carries the once-per-dialog key', () => {
  assert.deepEqual(companyPaymentPayload('full', '123', '2026-11-04', '  ', 'key-1234567'), { mode: 'full', payment_date: '2026-11-04', note: null, idempotency_key: 'key-1234567' });
  assert.deepEqual(companyPaymentPayload('partial', ' 200.50 ', '2026-11-04', ' cash ', 'key-1234567'), { mode: 'partial', amount: '200.50', payment_date: '2026-11-04', note: 'cash', idempotency_key: 'key-1234567' });

  const dialog = readFileSync(new URL('../features/hrm/CompanyPaymentDialog.tsx', import.meta.url), 'utf8');
  assert.match(dialog, /useState\(newIdempotencyKey\)/, 'one key per dialog opening');
  assert.match(dialog, /validatePartialAmount\(amount, section\.outstanding_cents\)/, 'greater than zero and not more than the outstanding balance');
  for (const field of ['section.payable', 'section.paid', 'section.outstanding']) assert.ok(dialog.includes(field), `the dialog shows ${field}`);
});

test('the month approval summary shows the companies, who is approved, unpaid, partial, not finalized, what is outstanding and whether the month is approved', () => {
  for (const key of ['option.payroll.approvalCompanies', 'option.payroll.approvalApproved', 'option.payroll.approvalUnpaid', 'option.payroll.approvalPartial', 'option.payroll.approvalNotFinalized', 'option.payroll.approvalOutstanding', 'status.payroll.monthApproved', 'status.payroll.monthPending'] as const) {
    assert.ok(panel.includes(key), key);
    assert.equal(CATALOGUE[key].review, 'proposed', `${key}: the new wording is a proposal`);
    assert.notEqual(CATALOGUE[key].en, CATALOGUE[key].so);
  }
  assert.match(panel, /approval\.fully_approved/);
  assert.match(panel, /\{run\.approval && <MonthApproval approval=\{run\.approval\} \/>\}/);
});

test('Reconcile overpayment is offered only for a finalized company with an unsettled excess, to someone who may approve', () => {
  const over = { status: 'finalized' as const, unreconciled_overpaid_cents: 13333 };
  assert.equal(canReconcile(over, true), true);
  assert.equal(canReconcile(over, false), false);
  assert.equal(canReconcile({ status: 'finalized', unreconciled_overpaid_cents: 0 }, true), false);
  assert.equal(canReconcile({ status: 'draft', unreconciled_overpaid_cents: 500 }, true), false);
  assert.equal(PAYMENT_STATUS_LABEL.overpaid.tone, 'danger');
});
