import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { canManageMarketingTeams, canRecordCommissionAmounts, defaultTeamId, isMarketingUnrestricted } from './marketingAccess.ts';
import { invalidateMarketing, isMarketingQueryKey } from './marketingCache.ts';
import { firstFieldError, marketingErrorMessage } from './marketingErrors.ts';
import { appendFeedback, canSaveFeedback, canSaveStatusChange, validateCommissionEntry, validateRateValue, validateTeamName } from './marketingFormRules.ts';
import { localIsoDate, reportPresetRange } from './marketingReportRange.ts';

const page = (name: string) => readFileSync(new URL(`../features/marketing/${name}`, import.meta.url), 'utf8');

// ---------------------------------------------------------------- E-3: who is offered team management
const hasAll = () => true;
const hasNone = () => false;

test('E-3: a team member holds marketing.manage but is not offered team management; the manager and the Super Admin are', () => {
  assert.equal(canManageMarketingTeams({ isSuperAdmin: false, role: 'marketing_employee', hasPermission: hasAll }), false);
  assert.equal(canManageMarketingTeams({ isSuperAdmin: false, role: 'marketing_manager', hasPermission: hasAll }), true);
  assert.equal(canManageMarketingTeams({ isSuperAdmin: true, role: undefined, hasPermission: hasAll }), true);
  assert.equal(canManageMarketingTeams({ isSuperAdmin: false, role: 'marketing_manager', hasPermission: hasNone }), false);
  assert.equal(isMarketingUnrestricted({ isSuperAdmin: false, role: 'hr_manager' }), false);
});

test('E-3: the Teams page gates delete / remove / add-member by the server flag and no longer by marketing.manage alone', () => {
  const source = page('TeamsPage.tsx');
  assert.ok(source.includes('team.can_manage_members === true'));
  assert.ok(source.includes('canManageMarketingTeams'));
  assert.ok(!source.includes('<PermissionGate'));
});

// ---------------------------------------------------------------- E-4: team name, rate value, real validation reasons
test('E-4: a team name is required and a rate value must be a number of 0 or more', () => {
  assert.ok(validateTeamName('   '));
  assert.equal(validateTeamName('Team C'), undefined);
  assert.ok(validateRateValue(''));
  assert.ok(validateRateValue('-1'));
  assert.ok(validateRateValue('abc'));
  assert.equal(validateRateValue('0'), undefined);
  assert.equal(validateRateValue('12.5'), undefined);
});

test('E-4: a validation failure shows the first real reason, any other failure keeps its own message', () => {
  const validation = { message: 'The given data was invalid.', isValidation: true, fieldErrors: { name: ['The name has already been taken.'] } };
  assert.equal(marketingErrorMessage(validation, 'fallback'), 'The name has already been taken.');
  assert.equal(marketingErrorMessage({ message: 'Only the Marketing Manager can create marketing teams.', isValidation: false }, 'fallback'), 'Only the Marketing Manager can create marketing teams.');
  assert.equal(marketingErrorMessage({ isValidation: true, fieldErrors: {}, message: 'Business rule said no.' }, 'fallback'), 'Business rule said no.');
  assert.equal(marketingErrorMessage('boom', 'fallback'), 'fallback');
  assert.equal(firstFieldError({ a: [], b: ['second'] }), 'second');
});

test('E-4: the screens use the real reason instead of the generic message', () => {
  for (const name of ['MarketingRecordDetailPage.tsx', 'FollowUpsPage.tsx', 'TeamsPage.tsx', 'CommissionPage.tsx']) {
    assert.ok(page(name).includes('marketingErrorMessage'), name);
    assert.ok(!/err instanceof Error \? err\.message/.test(page(name)), name);
  }
});

// ---------------------------------------------------------------- E-5: shared refresh
test('E-5: every marketing- query key is refreshed after a write, nothing else', () => {
  assert.equal(isMarketingQueryKey(['marketing-dashboard']), true);
  assert.equal(isMarketingQueryKey(['marketing-record', 5]), true);
  assert.equal(isMarketingQueryKey(['marketing-commission-records', {}]), true);
  assert.equal(isMarketingQueryKey(['hr-employees']), false);
  assert.equal(isMarketingQueryKey([]), false);

  let predicate: ((query: { queryKey: readonly unknown[] }) => boolean) | undefined;
  invalidateMarketing({
    invalidateQueries: (filters) => {
      predicate = filters.predicate;
    },
  });
  assert.equal(predicate?.({ queryKey: ['marketing-follow-ups', 'today'] }), true);
  assert.equal(predicate?.({ queryKey: ['customers'] }), false);
});

test('E-5: follow-up, detail and form writes refresh through the shared rule', () => {
  for (const name of ['FollowUpsPage.tsx', 'MarketingRecordDetailPage.tsx', 'XarunFormDialog.tsx', 'ProjectFormDialog.tsx', 'LogCommissionDialog.tsx', 'CommissionPage.tsx']) {
    assert.ok(page(name).includes('invalidateMarketing(queryClient)'), name);
  }
});

// ---------------------------------------------------------------- E-6 / E-7: follow-up editors
test("E-6: the status editor can be saved only when the status differs from the record's saved one", () => {
  assert.equal(canSaveStatusChange('done', 'done'), false);
  assert.equal(canSaveStatusChange('pending', 'done'), true);
  assert.equal(canSaveStatusChange('', 'done'), false);
});

test('E-6: the follow-up screen starts the status editor from the record status the server sends', () => {
  const source = page('FollowUpsPage.tsx');
  assert.ok(source.includes("setStatusValue(fu.record_status ?? 'pending')"));
  assert.ok(source.includes('canSaveStatusChange(statusValue, fu.record_status)'));
});

test('E-7 (owner decision 2026-11-06: APPEND): the feedback editor starts empty, shows the saved feedback read-only, says the text is added after it, and cannot save an empty text', () => {
  assert.equal(canSaveFeedback('   '), false);
  assert.equal(canSaveFeedback('New text'), true);
  assert.equal(canSaveFeedback('Asked for a price list.'), true, 'the same words may be said again; it is a new entry, nothing is overwritten');
  const source = page('FollowUpsPage.tsx');
  assert.ok(source.includes("setFeedbackText('')"));
  assert.ok(!source.includes("setFeedbackText(fu.record_feedback"), 'the editor is no longer pre-filled with the saved feedback');
  assert.ok(source.includes('warning.ownerDecisions1c.feedbackAppendHint'));
  assert.ok(source.includes('option.ownerDecisions1c.feedbackSoFar'));
});

test('E-7: appendFeedback puts the new entry after the saved text with a blank line, like the server', () => {
  assert.equal(appendFeedback(null, ' First note '), 'First note');
  assert.equal(appendFeedback('First note', 'Second note'), 'First note\n\nSecond note');
  assert.equal(appendFeedback('First note\r\n', 'Second note'), 'First note\n\nSecond note');
});

// ---------------------------------------------------------------- E-1 / E-2: commission
test('E-1: Log commission sends exactly the fields the manual-entry endpoint accepts, and the amount only for the manager / Super Admin', () => {
  const dialog = page('LogCommissionDialog.tsx');
  assert.ok(dialog.includes('marketingApi.commission.createRecord(recordId'));
  assert.ok(dialog.includes('admin_id: Number(form.admin_id)'));
  assert.ok(dialog.includes('reference_date: form.reference_date'));
  assert.ok(dialog.includes('mayEnterAmount && form.amount'));
  assert.ok(!dialog.includes('rate_value') && !dialog.includes('commission_rate_id'), 'no rate or calculation in the dialog');
  assert.ok(page('MarketingRecordDetailPage.tsx').includes('<LogCommissionDialog'));
  assert.equal(canRecordCommissionAmounts({ isSuperAdmin: false, role: 'marketing_employee' }), false);
  assert.equal(canRecordCommissionAmounts({ isSuperAdmin: false, role: 'marketing_manager' }), true);
});

test('E-1: the entry form needs an employee and a date, and an amount, when given, must be 0 or more', () => {
  assert.deepEqual(Object.keys(validateCommissionEntry({ admin_id: '', reference_date: '', amount: '' })).sort(), ['admin_id', 'reference_date']);
  assert.ok(validateCommissionEntry({ admin_id: '3', reference_date: '2026-08-01', amount: '-5' }).amount);
  assert.deepEqual(validateCommissionEntry({ admin_id: '3', reference_date: '2026-08-01', amount: '' }), {});
});

test('E-2: the Commission list pages, and filters by month and employee, with the parameters the server already supports', () => {
  const source = page('CommissionPage.tsx');
  assert.ok(source.includes('<Pagination'));
  assert.ok(source.includes('month: month || undefined'));
  assert.ok(source.includes('admin_id: employeeFilter ? Number(employeeFilter) : undefined'));
  assert.ok(source.includes('page,'));
});

// ---------------------------------------------------------------- E-9 / E-12
test('E-9: a team member with exactly one team starts with it chosen; nobody else gets a team chosen for them', () => {
  assert.equal(defaultTeamId(false, [{ id: 4 }]), '4');
  assert.equal(defaultTeamId(false, [{ id: 4 }, { id: 5 }]), '');
  assert.equal(defaultTeamId(true, [{ id: 4 }]), '');
  assert.equal(defaultTeamId(false, undefined), '');
});

test('E-12: Brought by is offered on the XARUN and PROJECT create forms to the manager / Super Admin only, and the rate form can name an employee', () => {
  for (const name of ['XarunFormDialog.tsx', 'ProjectFormDialog.tsx']) {
    const source = page(name);
    assert.ok(source.includes("mode === 'create' && unrestricted"), name);
    assert.ok(source.includes('brought_by_admin_id'), name);
  }
  assert.ok(page('CommissionPage.tsx').includes('admin_id: rateEmployee ? Number(rateEmployee) : null'));
});

// ---------------------------------------------------------------- E-11: reports period
test('E-11: the period presets are plain local dates', () => {
  const today = new Date(2026, 7, 20); // 20 Aug 2026, local
  assert.deepEqual(reportPresetRange('today', today), { from: '2026-08-20', to: '2026-08-20' });
  assert.deepEqual(reportPresetRange('last7', today), { from: '2026-08-14', to: '2026-08-20' });
  assert.deepEqual(reportPresetRange('last30', today), { from: '2026-07-21', to: '2026-08-20' });
  assert.deepEqual(reportPresetRange('thisMonth', today), { from: '2026-08-01', to: '2026-08-20' });
  assert.equal(localIsoDate(new Date(2026, 0, 5)), '2026-01-05');
});

test('E-11: the Reports page shows the effective period and takes its employees from the marketing employees list', () => {
  const source = page('MarketingReportsPage.tsx');
  assert.ok(source.includes('data.range.from'));
  assert.ok(source.includes('warning.marketing.reportDefaultPeriod'));
  assert.ok(source.includes('marketingApi.employees.list'));
  assert.ok(!source.includes('team.members'));
});

// ---------------------------------------------------------------- E-14: UX bundle
test('E-14: completed follow-ups can be listed (All tab) and are not offered Reschedule; narrow screens stack the form columns', () => {
  const followUps = page('FollowUpsPage.tsx');
  assert.ok(followUps.includes("value: 'all'"));
  assert.ok(/status !== 'completed' && \(\s*<Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick=\{\(\) => setEditor\(\{ id: fu\.id, kind: 'reschedule' \}\)\}/.test(followUps));
  for (const name of ['XarunFormDialog.tsx', 'ProjectFormDialog.tsx']) assert.ok(!/grid grid-cols-2/.test(page(name)), name);
  assert.ok(page('TeamsPage.tsx').includes('confirm.marketing.removeMemberTitle'));
});
