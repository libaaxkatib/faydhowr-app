import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { ApiClientError } from '../api/client.ts';
import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import type { EmployeeAdvance, EmployeePenalty } from '../types/employee.ts';
import type { DeductionBalanceRow, PayrollDeductionDetail } from '../types/payroll.ts';
import {
  BALANCE_FILTER_KEYS,
  DEDUCTION_PATH,
  amountProblem,
  amountReady,
  balanceRowView,
  balanceTotalsView,
  canChangeDeduction,
  canWaiveDeduction,
  carryNote,
  centsText,
  changedFields,
  dateProblem,
  deductionErrorMessage,
  deductionRowView,
  isDuplicateRecord,
  newIdempotencyKey,
  parseBalanceFilters,
  periodProblem,
  reasonReady,
  registerPayload,
  sanitizeAmountInput,
  toCents,
  waiveAmountProblem,
  type RegisterForm,
} from './deductions.ts';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');
const NOW = new Date(2026, 10, 5, 12, 0, 0); // 5 Nov 2026

// ---------------------------------------------------------------- who is offered what

test('Void and Correct are for an HR Manager or a Super Admin; Waive balance is for a Super Admin only', () => {
  assert.deepEqual(['super_admin', 'hr_manager', 'marketing_manager', undefined, null].map((role) => canChangeDeduction(role)), [true, true, false, false, false]);
  assert.deepEqual(['super_admin', 'hr_manager', 'marketing_manager', undefined, null].map((role) => canWaiveDeduction(role)), [true, false, false, false, false]);
  assert.deepEqual(DEDUCTION_PATH, { penalty: 'penalties', advance: 'advances' });
});

// ---------------------------------------------------------------- amounts

test('the amount box lets through only digits and one point with at most two decimals', () => {
  assert.equal(sanitizeAmountInput('25.50'), '25.50');
  assert.equal(sanitizeAmountInput('-25'), '25');
  assert.equal(sanitizeAmountInput('1e5'), '15');
  assert.equal(sanitizeAmountInput('12.3456'), '12.34');
  assert.equal(sanitizeAmountInput('1.2.3'), '1.23');
  assert.equal(sanitizeAmountInput('abc'), '');
  assert.equal(sanitizeAmountInput('$ 1,000.5'), '1000.5');
  assert.equal(sanitizeAmountInput('.5'), '.5');
});

test('an amount must be greater than 0 with at most two decimals; an empty box is not an error yet but is not ready', () => {
  assert.equal(amountProblem(''), null);
  assert.equal(amountReady(''), false);
  for (const ok of ['1', '25', '25.5', '25.50', '0.01', '1000000']) {
    assert.equal(amountProblem(ok), null, ok);
    assert.equal(amountReady(ok), true, ok);
  }
  for (const zero of ['0', '0.00', '.5', '5.', '-1', 'abc']) {
    assert.equal(amountProblem(zero), 'validation.deduction.amountPositive', zero);
    assert.equal(amountReady(zero), false, zero);
  }
  assert.equal(amountProblem('1.234'), 'validation.deduction.amountDecimals');
});

test('the waiver amount may stay empty (everything owed) but never exceeds what is still owed', () => {
  assert.equal(waiveAmountProblem('', 5000), null);
  assert.equal(waiveAmountProblem('50', 5000), null);
  assert.equal(waiveAmountProblem('50.00', 5000), null);
  assert.equal(waiveAmountProblem('50.01', 5000), 'validation.deduction.amountTooMuch');
  assert.equal(waiveAmountProblem('0', 5000), 'validation.deduction.amountPositive');
  assert.equal(waiveAmountProblem('1.999', 5000), 'validation.deduction.amountDecimals');
});

test('money in cents has no floating-point drift and prints as dollars', () => {
  assert.equal(toCents('25.50'), 2550);
  assert.equal(toCents('0.07'), 7);
  assert.equal(toCents(19.99), 1999);
  assert.ok(Number.isNaN(toCents('')));
  assert.ok(Number.isNaN(toCents(null)));
  assert.ok(Number.isNaN(toCents('abc')));
  assert.equal(centsText(1250), '$12.50');
  assert.equal(centsText(0), '$0.00');
  assert.equal(centsText(null), '$0.00');
  assert.equal(centsText(123456), '$1,234.56');
});

test('a reason needs at least three characters once trimmed', () => {
  assert.deepEqual(['', '  ', 'ab', ' ab ', 'abc', ' abc '].map(reasonReady), [false, false, false, false, true, true]);
});

test('the payroll month runs from 2020-01 up to next month; the date at most 31 days ahead', () => {
  assert.equal(periodProblem('2026-11', NOW), null);
  assert.equal(periodProblem('2026-12', NOW), null, 'next month is allowed');
  assert.equal(periodProblem('2027-01', NOW), 'validation.deduction.periodRange');
  assert.equal(periodProblem('2020-01', NOW), null);
  assert.equal(periodProblem('2019-12', NOW), 'validation.deduction.periodRange');
  assert.equal(periodProblem('', NOW), 'validation.deduction.periodRange');
  assert.equal(periodProblem('2026-13', NOW), 'validation.deduction.periodRange');
  assert.equal(periodProblem('2026-12', new Date(2026, 11, 31)), null, 'December rolls to January');
  assert.equal(periodProblem('2027-01', new Date(2026, 11, 31)), null);

  assert.equal(dateProblem('2026-11-05', NOW), null);
  assert.equal(dateProblem('2020-01-01', NOW), null, 'a past date is the server\'s business, not the panel\'s');
  assert.equal(dateProblem('2026-12-06', NOW), null, '31 days ahead is the limit');
  assert.equal(dateProblem('2026-12-07', NOW), 'validation.deduction.dateFuture');
  assert.equal(dateProblem('', NOW), 'validation.deduction.dateInvalid');
});

// ---------------------------------------------------------------- the registration payload and its key

const FORM: RegisterForm = { date: '2026-11-04', reason: '  Late three times ', amount: '25.50', payrollPeriod: '2026-11', notes: '   ', company: {} };

test('a registration is always USD, trimmed, carries the dialog key, and asks for confirmation only after the person confirmed', () => {
  const penalty = registerPayload('penalty', FORM, 'key-1', false);
  assert.deepEqual(penalty, { currency: 'USD', payroll_period: '2026-11', reason: 'Late three times', notes: null, idempotency_key: 'key-1', penalty_date: '2026-11-04', deduction_amount: 25.5 });
  assert.ok(!('confirm_duplicate' in penalty));

  const again = registerPayload('penalty', FORM, 'key-1', true);
  assert.equal(again.confirm_duplicate, true);
  assert.equal(again.idempotency_key, 'key-1', 'the retry reuses the very same key');

  const advance = registerPayload('advance', { ...FORM, notes: ' for rent ', company: { client_company_id: 7 } }, 'key-2', false);
  assert.deepEqual(advance, { currency: 'USD', payroll_period: '2026-11', reason: 'Late three times', notes: 'for rent', idempotency_key: 'key-2', client_company_id: 7, advance_date: '2026-11-04', amount: 25.5 });
});

test('the office company is sent as null and a form with no choice sends no company at all', () => {
  assert.equal(registerPayload('advance', { ...FORM, company: { client_company_id: null } }, 'k', false).client_company_id, null);
  assert.ok(!('client_company_id' in registerPayload('advance', FORM, 'k', false)));
});

test('an idempotency key is new for every dialog opening, and only uses characters the server accepts', () => {
  const keys = new Set(Array.from({ length: 200 }, () => newIdempotencyKey()));
  assert.equal(keys.size, 200);
  for (const key of keys) assert.match(key, /^[A-Za-z0-9._:-]{1,100}$/);
});

test('without crypto.randomUUID the key falls back to a timestamp and a random part that still passes the same rule', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
  try {
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });
    const a = newIdempotencyKey();
    const b = newIdempotencyKey();
    assert.notEqual(a, b);
    for (const key of [a, b]) assert.match(key, /^[A-Za-z0-9._:-]{1,100}$/);
  } finally {
    if (original) Object.defineProperty(globalThis, 'crypto', original);
  }
});

// ---------------------------------------------------------------- errors

const NEW_CODES = [
  'DUPLICATE_RECORD',
  'IDEMPOTENCY_KEY_REUSED',
  'DEDUCTION_CHANGE_FORBIDDEN',
  'REVERSE_APPLIED_SUPER_ADMIN_ONLY',
  'RECORD_CHANGED',
  'RECORD_NOT_ACTIVE',
  'RECORD_NOT_FOUND',
  'DEDUCTION_ALREADY_APPLIED',
  'WAIVE_SUPER_ADMIN_ONLY',
  'WAIVE_NOT_APPLICABLE',
  'WAIVE_AMOUNT_INVALID',
  'PAYROLL_INPUTS_CHANGED',
];

test('every new server error code reads as a plain sentence: the server\'s own in English, ours in Somali, never raw JSON', () => {
  for (const code of NEW_CODES) {
    const entry = CATALOGUE[`notify.error.${code}` as keyof typeof CATALOGUE];
    assert.ok(entry, `${code} has a catalogue entry`);
    const error = new ApiClientError(409, 'The server says what happened.', code);
    assert.equal(deductionErrorMessage(error, 'fallback'), 'The server says what happened.', `${code} in English`);
    assert.equal(deductionErrorMessage({ code }, 'fallback'), 'fallback', `${code} in English without a server message`);
    try {
      setActiveLanguage('so');
      assert.equal(deductionErrorMessage(error, 'fallback'), entry.so, `${code} in Somali`);
    } finally {
      setActiveLanguage('en');
    }
    assert.ok(!/[{}"]/.test(entry.en) && !/[{}"]/.test(entry.so), `${code}: no JSON in the wording`);
  }
});

test('"not an active employee" is worded for penalties and advances, not for replacements', () => {
  const error = new ApiClientError(422, "Employee 'Asha' is former and had no assignment in 2026-09.", 'EMPLOYEE_NOT_ACTIVE');
  assert.equal(deductionErrorMessage(error, 'fallback'), "Employee 'Asha' is former and had no assignment in 2026-09.");
  assert.ok(!deductionErrorMessage({ code: 'EMPLOYEE_NOT_ACTIVE' }, 'x').includes('Both employees'));
  try {
    setActiveLanguage('so');
    assert.equal(deductionErrorMessage(error, 'fallback'), CATALOGUE['notify.deduction.employeeNotActive'].so);
  } finally {
    setActiveLanguage('en');
  }
});

test('other errors keep their usual wording; only DUPLICATE_RECORD asks for the "record it again anyway" step', () => {
  assert.equal(isDuplicateRecord(new ApiClientError(409, 'x', 'DUPLICATE_RECORD')), true);
  assert.equal(isDuplicateRecord(new ApiClientError(409, 'x', 'IDEMPOTENCY_KEY_REUSED')), false);
  assert.equal(isDuplicateRecord(new Error('boom')), false);
  assert.equal(isDuplicateRecord(null), false);
  assert.equal(deductionErrorMessage({ code: 'PAYROLL_PERIOD_LOCKED' }, 'f'), CATALOGUE['notify.error.PAYROLL_PERIOD_LOCKED'].en);
  assert.equal(deductionErrorMessage(new Error('Network down'), 'f'), 'Network down');
  assert.equal(deductionErrorMessage({}, 'Could not do it.'), 'Could not do it.');
});

// ---------------------------------------------------------------- one row of the card

const penalty = (over: Partial<EmployeePenalty> = {}): EmployeePenalty => ({
  id: 1,
  penalty_date: '2026-11-01',
  reason: 'Late',
  deduction_amount: '25.00',
  currency: 'USD',
  payroll_period: '2026-11',
  notes: null,
  recorded_by: 'HR',
  created_at: '2026-11-01T08:00:00Z',
  status: 'active',
  version: 1,
  deducted_cents: 0,
  owed_cents: 2500,
  waived_cents: 0,
  ...over,
});

test('an active row nothing has deducted offers Void and Correct to an HR Manager, and Waive to nobody', () => {
  const row = penalty();
  assert.deepEqual(
    (['hr_manager', 'super_admin'] as const).map((role) => {
      const view = deductionRowView(row, [row], role);
      return [view.canVoid, view.canCorrect, view.canWaive];
    }),
    [[true, true, false], [true, true, false]],
  );
  const other = deductionRowView(row, [row], 'marketing_manager');
  assert.deepEqual([other.canVoid, other.canCorrect, other.canWaive], [false, false, false]);
  assert.equal(deductionRowView(row, [row], 'hr_manager').amountText, '25.00 USD');
});

test('a row a finalized payroll already deducted from offers only the Super Admin\'s Waive balance', () => {
  const row = penalty({ id: 2, deducted_cents: 1000, owed_cents: 1500 });
  const hr = deductionRowView(row, [row], 'hr_manager');
  assert.deepEqual([hr.canVoid, hr.canCorrect, hr.canWaive, hr.applied], [false, false, false, true]);
  const admin = deductionRowView(row, [row], 'super_admin');
  assert.deepEqual([admin.canVoid, admin.canCorrect, admin.canWaive], [false, false, true]);
  assert.equal(deductionRowView(penalty({ id: 3, deducted_cents: 2500, owed_cents: 0 }), [], 'super_admin').canWaive, false, 'fully deducted: nothing to waive');
});

test('a voided row offers no action, shows what replaced it, and the replacement shows what it replaces', () => {
  const old = penalty({ id: 10, status: 'voided', void_reason: 'wrong amount', voided_at: '2026-11-02T00:00:00Z' });
  const fresh = penalty({ id: 11, replaces_id: 10, deduction_amount: '15.00' });
  const oldView = deductionRowView(old, [old, fresh], 'super_admin');
  assert.deepEqual([oldView.voided, oldView.canVoid, oldView.canCorrect, oldView.canWaive, oldView.replacedById], [true, false, false, false, 11]);
  const freshView = deductionRowView(fresh, [old, fresh], 'super_admin');
  assert.deepEqual([freshView.voided, freshView.replacesId, freshView.replacedById, freshView.canVoid], [false, 10, null, true]);
});

test('a row without a version (an older answer) offers no action rather than guessing one; advances read their own amount', () => {
  const noVersion = penalty({ version: undefined });
  assert.equal(deductionRowView(noVersion, [noVersion], 'super_admin').canVoid, false);
  const advance: EmployeeAdvance = { id: 5, advance_date: '2026-11-01', amount: '100.00', currency: 'USD', payroll_period: '2026-11', reason: 'Rent', notes: null, recorded_by: null, created_at: '', version: 3, status: 'active' };
  const view = deductionRowView(advance, [advance], 'hr_manager');
  assert.equal(view.amountText, '100.00 USD');
  assert.equal(view.version, 3);
  assert.equal(view.owedCents, null, 'no balance figures were sent');
});

test('a correction sends only the fields that changed', () => {
  const before = { amount: '25.00', date: '2026-11-01', reason: 'Late', notes: '' };
  assert.deepEqual(changedFields(before, { ...before }), {});
  assert.deepEqual(changedFields(before, { ...before, amount: '30.00', notes: 'x' }), { amount: '30.00', notes: 'x' });
});

// ---------------------------------------------------------------- the Outstanding balances list

const ROW: DeductionBalanceRow = {
  kind: 'penalty',
  id: 4,
  employee_id: 9,
  employee_number: 'E-9',
  employee_name: ' Asha Warsame ',
  employee_status: 'active',
  client_company_id: 3,
  company_name: 'Alpha Co',
  registered_period: '2026-10',
  reason: 'Late',
  registered_cents: 5000,
  deducted_cents: 2000,
  waived_cents: 500,
  owed_cents: 2500,
  state: 'partly_deducted',
  leaver: true,
  version: 2,
};

test('a balance row is formatted for the screen: money, kind and state as message keys, a dash for a missing name', () => {
  const view = balanceRowView(ROW);
  assert.deepEqual(
    [view.key, view.employeeName, view.employeeNumber, view.companyName, view.kindKey, view.period, view.registered, view.deducted, view.waived, view.owed, view.stateKey, view.leaver],
    ['penalty-4', 'Asha Warsame', 'E-9', 'Alpha Co', 'option.deduction.kindPenalty', '2026-10', '$50.00', '$20.00', '$5.00', '$25.00', 'status.deduction.statePartly', true],
  );
  const bare = balanceRowView({ ...ROW, kind: 'advance', employee_name: null, employee_number: null, company_name: null, state: 'pending', leaver: false, reason: null });
  assert.deepEqual([bare.employeeName, bare.employeeNumber, bare.companyName, bare.kindKey, bare.stateKey, bare.leaver, bare.reason], ['—', '', '—', 'option.deduction.kindAdvance', 'status.deduction.statePending', false, '']);
  assert.equal(bare.key, 'advance-4', 'a penalty and an advance with the same id are different rows');
});

test('the totals row prints the server\'s totals, including the leavers', () => {
  assert.deepEqual(balanceTotalsView({ totals: { count: 3, owed_cents: 12345, leaver_count: 1, leaver_owed_cents: 2500 } }), { count: 3, owed: '$123.45', leaverCount: 1, leaverOwed: '$25.00' });
});

test('the list filters come from the address bar and anything unknown is ignored', () => {
  const from = (params: Record<string, string>) => parseBalanceFilters((key) => params[key] ?? '');
  assert.deepEqual(from({}), {});
  assert.deepEqual(from({ company: '7', kind: 'advance', leavers: '1' }), { client_company_id: 7, kind: 'advance', leavers_only: true });
  assert.deepEqual(from({ company: 'abc', kind: 'bonus', leavers: '0' }), {});
  assert.deepEqual(from({ company: '-3' }), {});
  assert.deepEqual([...BALANCE_FILTER_KEYS], ['company', 'kind', 'leavers']);
});

// ---------------------------------------------------------------- a payroll line that carried a deduction forward

test('a plain in-month deduction taken in full shows no note; other kinds never do', () => {
  assert.equal(carryNote('penalty', { carried_in: false, not_deducted_cents: 0, owed_cents: 2500, deducted_cents: 2500 }), null);
  assert.equal(carryNote('penalty', null), null);
  assert.equal(carryNote('absence', { carried_in: true, not_deducted_cents: 500 }), null);
  assert.equal(carryNote('replacement', { not_deducted_cents: 500 }), null);
});

test('a carried-in line and a partly deducted line say what carries on', () => {
  const detail: PayrollDeductionDetail = { carried_in: true, registered_period: '2026-09', owed_cents: 4000, deducted_cents: 1500, not_deducted_cents: 2500, capped_at_zero_net: true, carries_to_next_month: true, previously_deducted_cents: 1000 };
  assert.deepEqual(carryNote('advance', detail), { carriedIn: true, carriedFrom: '2026-09', owedCents: 4000, deductedCents: 1500, notDeductedCents: 2500, previouslyDeductedCents: 1000 });
  assert.deepEqual(carryNote('penalty', { carried_in: true, registered_period: '2026-09', owed_cents: 1000, deducted_cents: 1000, not_deducted_cents: 0 })?.notDeductedCents, 0);
  const inMonth = carryNote('penalty', { carried_in: false, owed_cents: 3000, deducted_cents: 1000, not_deducted_cents: 2000 });
  assert.deepEqual([inMonth?.carriedIn, inMonth?.carriedFrom, inMonth?.notDeductedCents], [false, null, 2000]);
});

// ---------------------------------------------------------------- wiring guards (the screens use these helpers and send what the server needs)

test('the new endpoints are called on the paths the server defines', () => {
  const api = read('../api/hr.ts');
  assert.ok(api.includes('`admin/hr/employees/${employeeId}/${kind}/${recordId}/void`'));
  assert.ok(api.includes('`admin/hr/employees/${employeeId}/${kind}/${recordId}/correct`'));
  assert.ok(api.includes('`admin/hr/employees/${employeeId}/${kind}/${recordId}/waive`'));
  assert.ok(api.includes("'admin/hr/payroll/deduction-balances'"));
});

test('every void, correct and waive request quotes the version the screen showed and a reason', () => {
  const dialogs = read('../features/hrm/ManageDeductionDialogs.tsx');
  assert.equal((dialogs.match(/expected_version: record\.version \?\? 0/g) ?? []).length, 3);
  assert.ok(dialogs.includes('correction_reason: correctionReason.trim()'));
  assert.equal((dialogs.match(/reasonReady\(/g) ?? []).length >= 3, true, 'each dialog stays off until the reason is written');
  assert.equal((dialogs.match(/deductionErrorMessage\(/g) ?? []).length, 3, 'each dialog shows a readable message, never raw JSON');
});

test('the registration dialog makes one key per opening, reuses it on retry, and confirms before recording a duplicate', () => {
  const dialog = read('../features/hrm/RecordDeductionDialog.tsx');
  assert.ok(dialog.includes('const [key] = useState(newIdempotencyKey)'));
  assert.ok(dialog.includes('registerPayload('));
  assert.ok(dialog.includes('isDuplicateRecord(err)'));
  assert.ok(dialog.includes('submit(true)'));
  assert.ok(dialog.includes('sanitizeAmountInput(e.target.value)'));
  assert.ok(dialog.includes('value="USD" readOnly disabled'));
  assert.ok(!dialog.includes("employee.status === 'active'"), 'the old "Active only" assumption is gone: the server decides');
});

test('the employee page records through the shared card and no longer limits the buttons to Active employees', () => {
  const page = read('../features/hrm/EmployeeDetailPage.tsx');
  assert.equal((page.match(/<DeductionRecordsCard/g) ?? []).length, 2);
  assert.ok(!page.includes('RecordPenaltyModal') && !page.includes('RecordAdvanceModal'));
  const card = read('../features/hrm/DeductionRecordsCard.tsx');
  assert.ok(card.includes('deductionRowView(record, records, admin?.role)'));
  assert.ok(!card.includes('employee.status'), 'no employee-status gate');
});

test('Outstanding balances is reachable only with the payroll view permission, next to Payroll', () => {
  const app = read('../App.tsx');
  assert.match(app, /path="\/hr\/payroll\/outstanding-balances" element=\{<RequirePermission permission="hr\.payroll\.view"><OutstandingBalancesPage \/><\/RequirePermission>\}/);
  const nav = read('../layouts/nav.config.ts');
  const lines = nav.split('\n');
  const payroll = lines.findIndex((line) => line.includes("labelKey: 'nav.payroll'"));
  assert.ok(lines[payroll + 1].includes("labelKey: 'nav.outstandingBalances'") && lines[payroll + 1].includes("permission: 'hr.payroll.view'"));
});

test('the payroll warnings panel shows the blocking notice and the payroll lines show the carried-forward note', () => {
  const panel = read('../features/hrm/GenerationWarningsPanel.tsx');
  assert.ok(panel.includes('data.deduction_inputs_changed === true'));
  assert.ok(panel.includes("warning.deduction.inputsChangedBlocking"));
  const runs = read('../features/hrm/PayrollRunsPage.tsx');
  assert.ok(runs.includes('<DeductionCarryNote kind={entry.kind} detail={entry.detail} />'));
  const sections = read('../features/hrm/CompanySectionsPanel.tsx');
  assert.ok(sections.includes('friendlyErrorMessage(err'), 'a Review / Finalize refusal shows the server\'s message (PAYROLL_INPUTS_CHANGED has its own wording)');
});
