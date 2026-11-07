import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { ownerDecisions1c } from '../i18n/messages/ownerDecisions1c.ts';
import { placeholdersOf } from '../i18n/catalog.ts';

// Owner decisions of 2026-11-06 (E-7, E-8, B-Q4, TRANSLATION-POLICY): what the panel shows and who is offered it. The server decides everything again.

const source = (path: string) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('every message added for these decisions is a proposal with no owner id and a Somali proposal that keeps the placeholders', () => {
  const keys = Object.keys(ownerDecisions1c);
  assert.ok(keys.length >= 30);

  for (const key of keys) {
    const entry = CATALOGUE[key as keyof typeof CATALOGUE];
    assert.equal(entry.review, 'proposed', `${key} is only a proposal`);
    assert.equal(entry.id, undefined, `${key} has no owner id`);
    assert.ok(entry.so.trim() !== '' && entry.so !== entry.en, `${key} has a Somali proposal`);
    assert.deepEqual(placeholdersOf(entry.so), placeholdersOf(entry.en), `${key} keeps its placeholders`);
  }
});

test('TRANSLATION-POLICY: the Translations page offers Edit to the Super Admin only and "Propose change" to everyone else, and approving is Super Admin only', () => {
  const page = source('features/system/TranslationsPage.tsx');
  assert.ok(page.includes("isSuperAdmin ? setEditingKey(key) : setProposingKey(key)"), 'the row button edits for the Super Admin and proposes for the others');
  assert.ok(page.includes('isSuperAdmin && editingKey'), 'the direct editor is rendered for the Super Admin only');
  assert.ok(page.includes('!isSuperAdmin && proposingKey'), 'the proposal dialog is rendered for the others only');
  // Approve / Reject buttons sit in the isSuperAdmin branch; the other branch only has Withdraw.
  const card = page.slice(page.indexOf('function ProposalsCard'), page.indexOf('function ProposeWordingDialog'));
  const superBranch = card.indexOf('{isSuperAdmin ? (');
  const otherBranch = card.indexOf(') : (', superBranch);
  assert.ok(superBranch > 0 && otherBranch > superBranch);
  assert.ok(card.indexOf('approveProposal') > superBranch && card.indexOf('rejectProposal') > superBranch && card.indexOf('rejectProposal') < otherBranch, 'approve and reject only in the Super Admin branch');
  assert.ok(card.indexOf("t('action.ownerDecisions1c.withdrawProposal')") > otherBranch, 'withdraw only in the proposer branch');
  assert.ok(page.includes('translationProposalsApi.propose'), 'a proposal is sent to the proposals endpoint, never to the override endpoint');
  assert.ok(!page.slice(page.indexOf('function ProposeWordingDialog')).includes('translationOverridesApi'), 'the proposal dialog never touches the overrides');
});

test('TRANSLATION-POLICY: the proposals client talks to the proposals endpoints only and the route / menu are open to the two proposer roles', () => {
  const api = source('api/translationProposals.ts');
  for (const path of ['admin/i18n/proposals', '/approve', '/reject', '/withdraw']) assert.ok(api.includes(path), path);
  assert.ok(!api.includes('i18n/overrides'));

  const app = source('App.tsx');
  assert.ok(app.includes("path=\"/system/translations\" element={<RequireRole roles={['hr_manager', 'marketing_manager']}><TranslationsPage />"));
});

test('B-Q4: a Fulfilled workforce request can be cancelled from the list, with a confirmation that says what is kept', () => {
  const page = source('features/hrm/WorkforceRequestsPage.tsx');
  assert.ok(page.includes("request.status !== 'cancelled' && ("), 'every request that is not already cancelled offers Cancel');
  assert.ok(!page.includes("request.status === 'open' || request.status === 'partially_filled') && ("));
  assert.ok(page.includes("cancelling.status === 'fulfilled' ? 'confirm.ownerDecisions1c.cancelFulfilledBody'"));
  const body = CATALOGUE['confirm.ownerDecisions1c.cancelFulfilledBody'].en;
  assert.ok(body.includes('keeps the request') && body.includes('assignment'));
});

test('E-8: the Add Team Member team field is not required and the empty choice is sent as null', () => {
  const dialog = source('features/marketing/AddTeamMemberDialog.tsx');
  assert.ok(dialog.includes('htmlFor="member-team" error={error(\'team_id\')}'), 'no required marker on the team');
  assert.ok(dialog.includes('option.ownerDecisions1c.teamOptional'));
});

test('E-7: the Xarun and Project edit forms hold only NEW feedback, show the saved feedback read-only and send it appended', () => {
  for (const name of ['XarunFormDialog.tsx', 'ProjectFormDialog.tsx']) {
    const form = source(`features/marketing/${name}`);
    assert.ok(form.includes("feedback: '', // owner decision E-7"), `${name}: the box starts empty`);
    assert.ok(form.includes('appendFeedback(record!.feedback, form.feedback)'), `${name}: sent after the saved text`);
    assert.ok(form.includes('option.ownerDecisions1c.feedbackSoFar'), `${name}: the saved feedback is shown`);
    assert.ok(!form.includes('feedback: form.feedback || null'), `${name}: never sends null / a replacement`);
  }
});

test('B-Q2: both Assign forms say, without blocking anything, that a Temporary employee keeps the type and gets no Permanent payroll line', () => {
  const company = source('features/hrm/companyEmployees/AssignmentDialogs.tsx');
  const detail = source('features/hrm/EmployeeDetailPage.tsx');
  assert.ok(company.includes('{isTemporaryWorker(picked) && (') && company.includes('warning.ownerDecisions1c.temporaryPermanentAssignment'));
  assert.ok(detail.includes("isTemporary={employee.employment_type === 'temporary'}") && detail.includes('warning.ownerDecisions1c.temporaryPermanentAssignment'));
  // A notice, not a gate: isTemporaryWorker is used only to render the notice in the Assign dialog (once for the notice, once in the Replace dialog's own checks),
  // never inside its validation, and the detail dialog only reads the prop for the notice.
  const assignDialog = company.slice(company.indexOf('export function AssignEmployeeDialog'), company.indexOf('export function EditAssignmentDialog'));
  assert.equal(assignDialog.split('isTemporaryWorker(').length - 1, 1, 'the Assign dialog calls it once, to show the notice');
  assert.ok(!assignDialog.includes('validateAssignForm({ employee_id: picked?.id ?? \'\', assignment_type: \'permanent\', position_id: positionId, start_date: startDate, salary_amount: amount, salary_currency: currency, isTemporary'), 'no Temporary condition in the Assign validation');
  assert.equal(detail.split('isTemporary').length - 1, 4, 'the detail dialog uses the flag for the prop, its type, the default and the notice only');
  const text = CATALOGUE['warning.ownerDecisions1c.temporaryPermanentAssignment'].en;
  assert.ok(text.includes('does not change the employee type') && text.includes('no Permanent monthly payroll line') && text.includes('÷ 30'));
});
