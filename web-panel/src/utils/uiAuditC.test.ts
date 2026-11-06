import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { uiAuditC } from '../i18n/messages/uiAuditC.ts';
import { placeholdersOf } from '../i18n/catalog.ts';
import {
  EARLIEST_DATE,
  MAX_AMOUNT,
  MAX_REASON_LENGTH,
  amountProblem,
  amountReady,
  dateProblem,
  periodProblem,
  reasonProblem,
  reasonReady,
} from './deductions.ts';
import { canSettleCorrection, canWriteOffCorrection } from './payrollCorrections.ts';
import { PAYROLL_DEPENDENT_QUERY_KEYS, invalidatePayrollDependents } from './payrollQueries.ts';
import { MAX_ADJUSTMENT, adjustmentAmountProblem, isPaidLineByLineOnly, lineCanBePaid, paymentActions, sectionActions, sectionHasPaidLines } from './payrollSections.ts';
import { reviewIsBlocked } from './sourceChanges.ts';
import { defaultPayrollMonth, previousMonth } from './payrollMonth.ts';
import type { PayrollCorrection, PayrollItem } from '../types/payroll.ts';

/**
 * UI audit, package C: the payroll / deduction screens against the server's rules. Pure helpers are tested directly; where the behaviour is a wiring decision of a
 * component (which date a dialog proposes, which buttons a row shows) the component's source is checked, because no browser runs in this suite.
 */

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');
const recordDialog = read('../features/hrm/RecordDeductionDialog.tsx');
const manageDialogs = read('../features/hrm/ManageDeductionDialogs.tsx');
const companyPayment = read('../features/hrm/CompanyPaymentDialog.tsx');
const correctionDialogs = read('../features/hrm/CorrectionDialogs.tsx');
const runsPage = read('../features/hrm/PayrollRunsPage.tsx');
const sectionsPanel = read('../features/hrm/CompanySectionsPanel.tsx');
const actionDialog = read('../features/hrm/SectionActionDialog.tsx');
const correctionsPanel = read('../features/hrm/PayrollCorrectionsPanel.tsx');
const ledger = read('../features/hrm/FinancialLedgerPage.tsx');
const workplacePanel = read('../features/hrm/WorkplacePaymentsPanel.tsx');
const generateDialog = read('../features/hrm/GenerateCompanyDialog.tsx');
const deductionCard = read('../features/hrm/DeductionRecordsCard.tsx');

const NOW = new Date(2026, 10, 5, 12, 0, 0);

// ---------------------------------------------------------------- C-2: the entry limits of DeductionRules, inline

test('C-2 the amount is limited to 1,000,000 like the server; the limit itself is allowed', () => {
  assert.equal(MAX_AMOUNT, 1000000);
  assert.equal(amountProblem('1000000'), null);
  assert.equal(amountProblem('1000000.00'), null);
  assert.equal(amountProblem('1000000.01'), 'validation.deduction.amountTooLarge');
  assert.equal(amountProblem('5000000'), 'validation.deduction.amountTooLarge');
  assert.equal(amountReady('5000000'), false);
  // The existing rules are unchanged.
  assert.equal(amountProblem('0'), 'validation.deduction.amountPositive');
  assert.equal(amountProblem('1.234'), 'validation.deduction.amountDecimals');
  assert.equal(amountProblem(''), null);
  assert.equal(amountReady('25.50'), true);
});

test('C-2 the date may not be before 2020-01-01 and may not be in the future; impossible dates are invalid', () => {
  assert.equal(EARLIEST_DATE, '2020-01-01');
  assert.equal(dateProblem('2020-01-01', NOW), null);
  assert.equal(dateProblem('2019-12-31', NOW), 'validation.deduction.dateTooEarly');
  assert.equal(dateProblem('2019-05-01', NOW), 'validation.deduction.dateTooEarly');
  assert.equal(dateProblem('2026-02-30', NOW), 'validation.deduction.dateInvalid');
  assert.equal(dateProblem('', NOW), 'validation.deduction.dateInvalid');
  assert.equal(dateProblem('2026-11-04', NOW), null);
  assert.equal(dateProblem('2026-12-06', NOW), 'validation.deduction.dateFuture');
  // The payroll month rules are untouched.
  assert.equal(periodProblem('2019-12', NOW), 'validation.deduction.periodRange');
  assert.equal(periodProblem('2026-12', NOW), null);
  assert.equal(periodProblem('2027-01', NOW), 'validation.deduction.periodRange');
});

test('C-2 a reason is 3 to 500 characters once trimmed, and the box says which limit is broken', () => {
  assert.equal(MAX_REASON_LENGTH, 500);
  assert.equal(reasonReady('ab'), false);
  assert.equal(reasonReady('  ab  '), false);
  assert.equal(reasonReady('abc'), true);
  assert.equal(reasonReady('x'.repeat(500)), true);
  assert.equal(reasonReady('x'.repeat(501)), false);
  assert.equal(reasonProblem(''), null);
  assert.equal(reasonProblem('ab'), 'validation.deduction.reasonShort');
  assert.equal(reasonProblem('abc'), null);
  assert.equal(reasonProblem('x'.repeat(501)), 'validation.deduction.reasonLong');
});

test('C-2 the dialogs carry the limits: min/max on the date, maxLength on the reason and note, and the Correct dialog checks the reason it changes', () => {
  for (const [name, text] of [['record', recordDialog], ['manage', manageDialogs]] as const) {
    assert.match(text, /min=\{EARLIEST_DATE\} max=\{facilityToday\(\)\}/, `${name}: date min and max`);
    assert.match(text, /maxLength=\{MAX_REASON_LENGTH\}/, `${name}: reason maxLength`);
    assert.match(text, /maxLength=\{MAX_NOTES_LENGTH\}/, `${name}: note maxLength`);
  }
  assert.match(manageDialogs, /reasonReady\(reason\)/, 'a changed reason must be 3-500 characters');
  assert.match(manageDialogs, /changed\.reason === undefined \|\| reasonReady\(reason\)/, 'an unchanged older reason is simply left as it is');
});

// ---------------------------------------------------------------- C-3: Mogadishu "today"

test('C-3 the date of a penalty or advance follows the Mogadishu calendar, whatever the browser clock says', () => {
  // 22:00 UTC on 5 Nov is 01:00 on 6 Nov in Mogadishu: the 6th is already today there and must be accepted, the 7th is the future.
  const lateEvening = new Date('2026-11-05T22:00:00Z');
  assert.equal(dateProblem('2026-11-06', lateEvening), null);
  assert.equal(dateProblem('2026-11-07', lateEvening), 'validation.deduction.dateFuture');
  // 20:59 UTC is 23:59 in Mogadishu on the 5th: the 6th is still tomorrow.
  const justBeforeMidnight = new Date('2026-11-05T20:59:00Z');
  assert.equal(dateProblem('2026-11-05', justBeforeMidnight), null);
  assert.equal(dateProblem('2026-11-06', justBeforeMidnight), 'validation.deduction.dateFuture');
});

test('C-3 the payment, reconciliation, mark-paid and penalty/advance dialogs use the Mogadishu day, not the browser day', () => {
  for (const [name, text] of [['company payment', companyPayment], ['reconcile', correctionDialogs], ['mark paid', runsPage], ['record deduction', recordDialog]] as const) {
    assert.ok(!/localToday/.test(text), `${name}: no browser-day helper left`);
    assert.match(text, /facilityToday\(\)/, `${name}: uses the Mogadishu day`);
  }
  assert.match(companyPayment, /date <= facilityToday\(\)/);
  assert.match(correctionDialogs, /date <= facilityToday\(\)/);
  assert.match(runsPage, /paymentDate > facilityToday\(\)/, 'mark paid refuses a date after today in Mogadishu');
});

// ---------------------------------------------------------------- UG-7: current month in the NEW penalty / advance forms only

test('UG-7 a new penalty or advance opens on the CURRENT month, and the payroll screens still open on the previous month', () => {
  assert.match(recordDialog, /useState\(localMonth\(\)\)/);
  assert.ok(!/defaultPayrollMonth/.test(recordDialog), 'the form no longer uses the payroll screens\' previous-month default');
  // Only this dialog changed: the shared default stays "previous month" (payrollMonth.test.ts pins it).
  assert.equal(defaultPayrollMonth, previousMonth);
  assert.equal(defaultPayrollMonth(new Date(2026, 10, 5)), '2026-10');
  assert.equal(defaultPayrollMonth(new Date(2027, 0, 5)), '2026-12');
  // The Correct dialog starts from the record's own month: it is not a new form and keeps it.
  assert.match(manageDialogs, /useState\(record\.payroll_period\)/);
  // The month rules of the form are unchanged: 2020-01 up to next month, so the current month is always accepted.
  assert.equal(periodProblem('2026-11', NOW), null);
  assert.equal(periodProblem('2019-12', NOW), 'validation.deduction.periodRange');
});

// ---------------------------------------------------------------- C-1: superseded corrections are history only

function correction(overrides: Partial<PayrollCorrection> = {}): Pick<PayrollCorrection, 'status' | 'kind' | 'outstanding_cents' | 'available_to_settle_cents'> {
  return { status: 'open', kind: 'underpayment', outstanding_cents: 15000, available_to_settle_cents: 15000, ...overrides };
}

test('C-1 money can be recorded only against an OPEN correction that still has something to settle', () => {
  assert.equal(canSettleCorrection(correction(), true, 'super_admin'), true);
  assert.equal(canSettleCorrection(correction({ status: 'superseded' }), true, 'super_admin'), false, 'a superseded underpayment is not paid');
  assert.equal(canSettleCorrection(correction({ status: 'superseded', kind: 'overpayment' }), true, 'hr_manager'), false, 'a superseded debt is not repaid');
  assert.equal(canSettleCorrection(correction({ status: 'settled', outstanding_cents: 0 }), true, 'super_admin'), false);
  assert.equal(canSettleCorrection(correction({ kind: 'overpayment', available_to_settle_cents: 0 }), true, 'hr_manager'), false, 'everything left is already scheduled in draft payroll months');
  // The role rules are as before: only a Super Admin pays an underpayment; a Super Admin or HR Manager records a repayment.
  assert.equal(canSettleCorrection(correction(), true, 'hr_manager'), false);
  assert.equal(canSettleCorrection(correction({ kind: 'overpayment' }), true, 'hr_manager'), true);
  assert.equal(canSettleCorrection(correction({ kind: 'overpayment' }), false, 'super_admin'), false, 'needs hr.payroll.manage');
});

test('C-1 a debt is written off only by a Super Admin, on an OPEN correction with something left', () => {
  assert.equal(canWriteOffCorrection(correction({ kind: 'overpayment' }), true, 'super_admin'), true);
  assert.equal(canWriteOffCorrection(correction({ kind: 'overpayment', status: 'superseded' }), true, 'super_admin'), false);
  assert.equal(canWriteOffCorrection(correction({ kind: 'overpayment', available_to_settle_cents: 0 }), true, 'super_admin'), false);
  assert.equal(canWriteOffCorrection(correction({ kind: 'underpayment' }), true, 'super_admin'), false);
  assert.equal(canWriteOffCorrection(correction({ kind: 'overpayment' }), true, 'hr_manager'), false);
});

test('C-1 the corrections panel uses those rules and says why a superseded row has no buttons', () => {
  assert.match(correctionsPanel, /canSettleCorrection\(c, mayManage, admin\?\.role\)/);
  assert.match(correctionsPanel, /canWriteOffCorrection\(c, mayManage, admin\?\.role\)/);
  assert.match(correctionsPanel, /c\.status === 'superseded' &&[^\n]*warning\.correction\.supersededNoActions/);
});

// ---------------------------------------------------------------- C-4: the "no company" section is paid line by line only

test('C-4 Approve Paid / Approve Partial are never offered for the "no company" section; a company that owes money still gets them', () => {
  const owes = { can_pay: true, outstanding_cents: 12000 };
  assert.deepEqual(paymentActions({ ...owes, client_company_id: 5 }, true), ['approve_paid', 'approve_partial']);
  assert.deepEqual(paymentActions({ ...owes, client_company_id: null }, true), []);
  assert.deepEqual(paymentActions(owes, true), ['approve_paid', 'approve_partial'], 'unchanged for callers that do not name the company');
  assert.deepEqual(paymentActions({ ...owes, client_company_id: 5 }, false), []);
  assert.equal(isPaidLineByLineOnly({ client_company_id: null, status: 'finalized', outstanding_cents: 12000 }), true);
  assert.equal(isPaidLineByLineOnly({ client_company_id: null, status: 'draft', outstanding_cents: 12000 }), false);
  assert.equal(isPaidLineByLineOnly({ client_company_id: null, status: 'finalized', outstanding_cents: 0 }), false);
  assert.equal(isPaidLineByLineOnly({ client_company_id: 5, status: 'finalized', outstanding_cents: 12000 }), false);
  assert.match(sectionsPanel, /isPaidLineByLineOnly\(section\)/);
});

// ---------------------------------------------------------------- C-5: nothing to pay

test('C-5 a line is offered "Mark paid" only when it has a positive net to pay and is not paid yet', () => {
  assert.equal(lineCanBePaid({ net: '120.00', payment_status: 'unpaid' }), true);
  assert.equal(lineCanBePaid({ net: '0.00', payment_status: 'unpaid' }), false, 'a penalty took the whole pay: the server refuses PAYROLL_ITEM_NOTHING_TO_PAY');
  assert.equal(lineCanBePaid({ net: '120.00', payment_status: 'paid' }), false);
  assert.match(runsPage, /\{lineCanBePaid\(item\) && \(/);
  assert.match(runsPage, /status\.payroll\.nothingToPayLine/);
});

// ---------------------------------------------------------------- C-6: adjustment bound

test('C-6 an adjustment is limited to +/-100,000 like the server', () => {
  assert.equal(MAX_ADJUSTMENT, 100000);
  assert.equal(adjustmentAmountProblem('100000'), null);
  assert.equal(adjustmentAmountProblem('-100000'), null);
  assert.equal(adjustmentAmountProblem('100000.01'), 'validation.payroll.adjustAmountRange');
  assert.equal(adjustmentAmountProblem('-250000'), 'validation.payroll.adjustAmountRange');
  assert.equal(adjustmentAmountProblem(''), null);
  assert.equal(adjustmentAmountProblem('-25'), null);
  assert.match(runsPage, /adjustmentAmountProblem\(amount\)/);
});

// ---------------------------------------------------------------- S-1: voiding a workplace payment

test('S-1 the void of a mistaken workplace payment is offered by role only, not hidden when the company is not finalized', () => {
  assert.match(workplacePanel, /canVoid && \(/);
  assert.ok(!/canVoid && workplace\.can_record/.test(workplacePanel));
});

// ---------------------------------------------------------------- S-2: lines paid one by one

test('S-2 a finalized company with lines paid one by one is not offered "Reopen for correction"; the screen says what to do', () => {
  const items: Pick<PayrollItem, 'section_id' | 'payment_status'>[] = [
    { section_id: 1, payment_status: 'unpaid' },
    { section_id: 2, payment_status: 'paid' },
  ];
  assert.equal(sectionHasPaidLines(items, 1), false);
  assert.equal(sectionHasPaidLines(items, 2), true);
  assert.equal(sectionHasPaidLines(undefined, 2), false);
  // The panel feeds that into the same "blocked" flag the paid-company rule uses, so the button is not offered.
  assert.deepEqual(sectionActions('finalized', true, true, true), []);
  assert.deepEqual(sectionActions('finalized', true, true, false), ['reopen_finalized']);
  assert.match(sectionsPanel, /sectionActions\(section\.status, canManage, canApprove, paidReopenBlocked \|\| paidLinesBlocked\)/);
  assert.match(sectionsPanel, /warning\.payroll\.reopenPaidLines/);
});

// ---------------------------------------------------------------- U-1: the confirm button while the draft is stale

test('U-1 Review / Finalize cannot be confirmed while a stale-draft notice is shown, and the Temporary warnings never block', () => {
  assert.equal(reviewIsBlocked({ deduction_inputs_changed: true }, false), true);
  assert.equal(reviewIsBlocked({ source_inputs_changed: true }, false), true);
  assert.equal(reviewIsBlocked({ deduction_inputs_changed: false, source_inputs_changed: false }, false), false);
  assert.equal(reviewIsBlocked({}, false), false);
  assert.equal(reviewIsBlocked(undefined, false), false, 'while the warnings load, or cannot be read, the server still decides');
  assert.equal(reviewIsBlocked(null, false), false);
  assert.equal(reviewIsBlocked(undefined, true), true, 'the server has just refused it');
  assert.match(actionDialog, /disabled=\{blocked\}/);
  assert.ok(!/warnings\.warnings|\.warnings\.length/.test(actionDialog), 'the Temporary Replacement warnings are not part of the decision');
});

// ---------------------------------------------------------------- U-3: screens that show payroll results refresh

test('U-3 every payroll action marks the dashboard, ledger, employee and roll-up screens stale', () => {
  assert.deepEqual(PAYROLL_DEPENDENT_QUERY_KEYS.map((key) => key[0]).sort(), ['deduction-balances', 'employee', 'financial-ledger', 'hr-dashboard', 'payroll-rollup', 'payroll-summary']);
  const calls: (readonly string[])[] = [];
  invalidatePayrollDependents({ invalidateQueries: ({ queryKey }) => calls.push(queryKey) });
  assert.equal(calls.length, PAYROLL_DEPENDENT_QUERY_KEYS.length);
  for (const [name, text] of [
    ['payroll page', runsPage], ['company list', sectionsPanel], ['generate', generateDialog], ['company payment', companyPayment], ['reconcile / reopen', correctionDialogs],
    ['workplace payments', workplacePanel], ['corrections', correctionsPanel], ['penalty / advance card', deductionCard],
  ] as const) {
    assert.match(text, /invalidatePayrollDependents\(queryClient\)/, name);
  }
});

// ---------------------------------------------------------------- U-5: states and the ledger

test('U-5 the corrections panel says so while it loads and when it cannot be read (it used to vanish silently)', () => {
  assert.match(correctionsPanel, /data-testid="payroll-corrections-loading"/);
  assert.match(correctionsPanel, /data-testid="payroll-corrections-error"/);
  assert.match(correctionsPanel, /<ErrorState error=\{error\} onRetry=/);
});

test('U-5 the ledger names the payment a reversal row cancels, and does not ask the server for a range that ends before it starts', () => {
  assert.match(ledger, /row\.original\.is_reversal/);
  assert.match(ledger, /option\.payroll\.ledgerReversalOf/);
  assert.match(ledger, /const rangeInvalid = from !== '' && to !== '' && to < from;/);
  assert.match(ledger, /enabled: !rangeInvalid/);
  assert.match(ledger, /validation\.reports\.dateRangeOrder/);
});

// ---------------------------------------------------------------- the new messages

test('every new message has English and Somali that differ, the same placeholders, and is a proposal that touches no approved wording', () => {
  const keys = Object.keys(uiAuditC) as (keyof typeof uiAuditC)[];
  assert.ok(keys.length >= 10);
  for (const key of keys) {
    const entry = CATALOGUE[key];
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.notEqual(entry.en, entry.so, `${key}: Somali differs from English`);
    assert.equal(entry.review, 'proposed', `${key}: new wording is a proposal`);
    assert.deepEqual(placeholdersOf(entry.so), placeholdersOf(entry.en), `${key}: same placeholders`);
  }
});
