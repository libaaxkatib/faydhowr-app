import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { placeholdersOf } from '../i18n/catalog.ts';
import { WORKPLACE_STATUS, amountToCents, canRecordPayment, paymentPayload, validatePartialAmount, validateVoidReason, waitsForCompanyFinalization, workplaceName } from './workplacePayments.ts';

/**
 * Payments by workplace. The server calculates every total, balance and status (tested in WorkplacePaymentsTest); these checks cover what the panel
 * decides: when the buttons are offered, what an amount typed in dollars means, exactly what is sent for a full and for a partial payment, that the
 * server's figures are only displayed, and that every word is in the language catalogue (Somali and English).
 */

const panel = readFileSync(new URL('../features/hrm/WorkplacePaymentsPanel.tsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('../features/hrm/PayrollRunsPage.tsx', import.meta.url), 'utf8');
const api = readFileSync(new URL('../api/hr.ts', import.meta.url), 'utf8');

const KEYS = [
  'option.payroll.wpTitle', 'option.payroll.wpHelp', 'option.payroll.wpWorkplace', 'option.payroll.wpTotal', 'option.payroll.wpPaid', 'option.payroll.wpOutstanding',
  'option.payroll.wpNone', 'option.payroll.wpUnassigned', 'option.payroll.wpHistory', 'option.payroll.wpNoPayments', 'option.payroll.wpRecordedBy', 'option.payroll.wpNote',
  'option.payroll.wpVoidedMark', 'status.payroll.wpUnpaid', 'status.payroll.wpPartial', 'status.payroll.wpPaid',
  'action.payroll.wpVoid', 'confirm.payroll.wpVoidTitle', 'confirm.payroll.wpVoidBody', 'option.payroll.wpVoidReason',
  'validation.payroll.wpVoidReason', 'warning.payroll.wpNotFinalized', 'notify.payroll.wpVoided', 'notify.payroll.wpFailed',
] as const;

const workplace = { client_company_id: 7, work_location_id: null, company_name: 'Acme Co', workplace_name: null, payable: true, can_record: true, outstanding_cents: 12_500 };

test('the status words are UNPAID, PARTIAL and PAID with their own colours', () => {
  assert.deepEqual(Object.keys(WORKPLACE_STATUS), ['unpaid', 'partial', 'paid']);
  assert.equal(CATALOGUE[WORKPLACE_STATUS.unpaid.labelKey].en, 'UNPAID');
  assert.equal(CATALOGUE[WORKPLACE_STATUS.partial.labelKey].en, 'PARTIAL');
  assert.equal(CATALOGUE[WORKPLACE_STATUS.paid.labelKey].en, 'PAID');
  assert.deepEqual([WORKPLACE_STATUS.unpaid.tone, WORKPLACE_STATUS.partial.tone, WORKPLACE_STATUS.paid.tone], ['danger', 'warning', 'success']);
});

test('the payment buttons are offered only for a workplace whose own company is finalized, to someone allowed, with a balance', () => {
  assert.equal(canRecordPayment(workplace, true), true);
  assert.equal(canRecordPayment({ ...workplace, can_record: false }, true), false, 'its company is not finalized yet (other companies of the month may be)');
  assert.equal(canRecordPayment(workplace, false), false, 'no payroll approval permission');
  assert.equal(canRecordPayment({ ...workplace, outstanding_cents: 0 }, true), false, 'already fully paid');
  assert.equal(canRecordPayment({ ...workplace, payable: false }, true), false, 'lines with no workplace');
});

test('a workplace whose company is not finalized says "Company not finalized yet"; a finalized or fully paid one does not', () => {
  assert.equal(waitsForCompanyFinalization({ ...workplace, can_record: false }), true);
  assert.equal(waitsForCompanyFinalization(workplace), false);
  assert.equal(waitsForCompanyFinalization({ ...workplace, can_record: false, outstanding_cents: 0 }), false);
  assert.equal(waitsForCompanyFinalization({ ...workplace, can_record: false, payable: false }), false);
  assert.equal(CATALOGUE['warning.payroll.wpCompanyNotFinalized'].en, 'Company not finalized yet');
});

test('an amount typed in dollars means whole cents, with at most two decimals', () => {
  assert.equal(amountToCents('100'), 10_000);
  assert.equal(amountToCents('100.5'), 10_050);
  assert.equal(amountToCents('0.07'), 7);
  assert.equal(amountToCents(' 12.34 '), 1_234);
  for (const bad of ['', 'abc', '1.234', '-5', '1,000', '1e3', '.5', '5.', '1 000']) assert.equal(amountToCents(bad), null, bad);
});

test('a partial amount must be above zero and not above the outstanding balance', () => {
  assert.equal(validatePartialAmount('125.00', 12_500), null, 'exactly the balance is allowed: the workplace then becomes PAID');
  assert.equal(validatePartialAmount('0.01', 12_500), null);
  assert.notEqual(validatePartialAmount('125.01', 12_500), null);
  assert.notEqual(validatePartialAmount('0', 12_500), null);
  assert.notEqual(validatePartialAmount('', 12_500), null);
  assert.notEqual(validatePartialAmount('-1', 12_500), null);
  assert.match(validatePartialAmount('200', 12_500) ?? '', /125\.00/, 'the message names what is still open');
});

test('a full payment sends no amount; a partial payment sends the typed one; both carry the date, the note and the key', () => {
  const full = paymentPayload(workplace, 'full', '999', '2026-12-18', '  ', 'key-123456');
  assert.deepEqual(full, { client_company_id: 7, work_location_id: null, mode: 'full', payment_date: '2026-12-18', note: null, idempotency_key: 'key-123456' });
  assert.ok(!('amount' in full), 'the server pays the outstanding balance of that moment');

  const partial = paymentPayload({ client_company_id: 7, work_location_id: 3 }, 'partial', ' 40.5 ', '2026-12-18', ' first instalment ', 'key-abcdefgh');
  assert.deepEqual(partial, { client_company_id: 7, work_location_id: 3, mode: 'partial', amount: '40.5', payment_date: '2026-12-18', note: 'first instalment', idempotency_key: 'key-abcdefgh' });
});

test('the workplace is named by its company and, when it has one, its location', () => {
  assert.equal(workplaceName({ company_name: 'Acme Co', workplace_name: null }), 'Acme Co');
  assert.equal(workplaceName({ company_name: 'Acme Co', workplace_name: 'North' }), 'Acme Co · North');
  assert.equal(workplaceName({ company_name: null, workplace_name: 'Office' }), 'Office');
  assert.equal(workplaceName({ company_name: null, workplace_name: null }), '—');
});

test('voiding needs a real reason of at least five characters', () => {
  assert.notEqual(validateVoidReason(''), null);
  assert.notEqual(validateVoidReason('  no '), null);
  assert.equal(validateVoidReason('Typed twice'), null);
});

test('the panel only displays the server\'s figures and sends the person\'s request', () => {
  assert.match(api, /workplacePayments: \(runId: number\) => apiRequest<WorkplacePayments>\(`admin\/hr\/payroll-runs\/\$\{runId\}\/workplace-payments`\)/);
  assert.match(api, /recordWorkplacePayment[\s\S]{0,260}method: 'POST'/);
  assert.match(api, /voidWorkplacePayment[\s\S]{0,200}workplace-payments\/\$\{paymentId\}\/void/);
  for (const field of ['workplace.total', 'workplace.paid', 'workplace.outstanding', 'data.totals.total', 'data.totals.paid', 'data.totals.outstanding']) assert.ok(panel.includes(field), field);
  assert.ok(!/(total|paid|outstanding)(_cents)?\)?\s*[-+]\s*(workplace|data)\./.test(panel), 'no arithmetic on the totals');
  assert.ok(!/reduce\(/.test(panel), 'nothing is summed in the panel');
});

test('a retry or a double click is recorded once: one key per dialog opening, and the buttons lock while it is sent (the company payment dialog)', () => {
  const dialog = readFileSync(new URL('../features/hrm/CompanyPaymentDialog.tsx', import.meta.url), 'utf8');
  assert.match(dialog, /const \[key\] = useState\(newIdempotencyKey\)/);
  assert.match(dialog, /companyPaymentPayload\(mode, amount, date, note, key\)/);
  assert.match(dialog, /isLoading=\{mutation\.isPending\}/);
  assert.match(dialog, /onClose=\{mutation\.isPending \? \(\) => undefined : onClose\}/);
});

test('recording and voiding are offered by permission, and the lists refresh afterwards', () => {
  // Payments are recorded from the company list ("Approve Paid" / "Approve Partial"); this panel keeps the history and the void of a mistaken payment.
  assert.match(page, /<WorkplacePaymentsPanel run=\{run\} canVoid=\{canVoid\} \/>/);
  assert.ok(!/canRecord|setPaying|PaymentDialog|wpPayFull|wpPayPartial/.test(panel), 'no payment buttons in the workplace history panel');
  assert.match(panel, /canVoid && workplace\.can_record/);
  assert.match(panel, /waitsForCompanyFinalization\(workplace\)/);
  assert.match(panel, /queryClient\.invalidateQueries\(\{ queryKey: \['workplace-payments'\] \}\)/);
  assert.match(page, /queryClient\.invalidateQueries\(\{ queryKey: \['workplace-payments'\] \}\)/, 'paying or voiding a single line refreshes the workplace figures');
});

test('every word of the panel is a catalogue message with English and Somali, written as a proposal', () => {
  for (const key of KEYS) {
    const entry = CATALOGUE[key];
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.notEqual(entry.en, entry.so, `${key}: Somali differs from English`);
    assert.equal(entry.review, 'proposed', `${key}: new wording is a proposal, not owner-approved`);
    assert.deepEqual(placeholdersOf(entry.en), placeholdersOf(entry.so), `${key}: the same placeholders in both languages`);
    assert.ok(panel.includes(`'${key}'`) || readFileSync(new URL('./workplacePayments.ts', import.meta.url), 'utf8').includes(`'${key}'`), `${key} is used`);
  }
  assert.deepEqual(placeholdersOf(CATALOGUE['confirm.payroll.apPaidBody'].en), ['amount', 'month', 'name']);
});

test('the panel writes no English text and no colour of its own (theme tokens only, so Light and Dark both work)', () => {
  const jsxText = [...panel.matchAll(/>\s*([A-Z][A-Za-z ,.'-]{3,})\s*</g)].map((match) => match[1]);
  assert.deepEqual(jsxText, [], 'no English words between tags');
  assert.ok(!/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\b(?:bg|text|border)-(?:gray|red|green|blue|yellow|amber|black|slate)/.test(panel));
});
