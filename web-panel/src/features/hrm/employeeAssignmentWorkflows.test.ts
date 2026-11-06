import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

import { CATALOGUE } from '../../i18n/messages/index.ts';
import { assignmentListMonth, validateEndDate } from '../../utils/companyEmployees.ts';
import { companyChoices } from '../../utils/inputCompany.ts';
import { buildPayload, emptyForm } from '../../utils/officeStaff.ts';
import { canStartEmbeddedWorkflow } from './embeddedWorkflow.ts';
import type { WorkAssignment } from '../../types/employee.ts';

// Package A (UI audit remediation, owner decisions UG-2 and UG-6, 2026-11-05): Employee Detail completes the same workflows as the company page.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');
const detail = read('./EmployeeDetailPage.tsx');
const section = (source: string, from: string, to: string) => source.slice(source.indexOf(from), source.indexOf(to));
const endModal = section(detail, 'function EndAssignmentModal(', 'function MarkSeparatedModal(');
const separateModal = section(detail, 'function MarkSeparatedModal(', 'function RehireModal(');
const picker = read('./companyEmployees/SuccessorPicker.tsx');

const base = { id: 1, employee_id: 1, client_company_id: 5, client_company_name: 'Acme', work_location_id: null, location_type: 'client', position_id: null, position_name: null, start_date: '2026-01-01', end_date: null, salary_amount: '300', salary_currency: 'USD', salary_frequency: 'monthly', status: 'active', notes: null, created_at: '' } as unknown as WorkAssignment;

// ---- A-1: the replacement picker no longer says a replacement is required --------------------------------------------------------------------------

test('A-1: the picker offers "No replacement now", marks nothing as required and uses the optional wording', () => {
  assert.doesNotMatch(picker, /text-danger">\*</, 'no required asterisk');
  assert.match(picker, /option\.hr\.successorNone/);
  assert.match(picker, /onChange=\{\(\) => onChange\(EMPTY_SUCCESSOR\)\}/, 'choosing it clears a half-made choice');
  assert.match(picker, /option\.hr\.successorQuestionOptional/);
  assert.match(picker, /option\.hr\.successorHelpOptional/);
  assert.doesNotMatch(picker, /'option\.hr\.successorHelp'|'option\.hr\.successorQuestion'/, 'the old wording is not shown');
  assert.match(CATALOGUE['option.hr.successorHelpOptional'].en, /optional/);
  assert.doesNotMatch(CATALOGUE['option.hr.successorHelpOptional'].en, /cannot be left without/);
  // The old keys stay defined and unchanged.
  assert.match(CATALOGUE['option.hr.successorHelp'].en, /cannot be left without a replacement/);
});

// ---- A-2 / A-3: End Assignment --------------------------------------------------------------------------------------------------------------------

test('A-3: the last day is required and may not be before the assignment started (the server refuses it too)', () => {
  assert.equal(validateEndDate('', '2026-10-10'), 'Enter the last day.');
  assert.match(validateEndDate('2026-10-09', '2026-10-10') ?? '', /2026-10-10/);
  assert.equal(validateEndDate('2026-10-10', '2026-10-10'), undefined, 'the first day itself is a valid last day');
  assert.match(endModal, /validateEndDate\(endDate, assignment\.start_date\)/);
  assert.match(endModal, /localToday\(\)/);
  assert.doesNotMatch(endModal, /toISOString/);
});

test('A-2: the End dialog can carry the correction reason and the away-holder confirmation the server asks for', () => {
  assert.match(endModal, /useCorrectionFlow\(\)/);
  assert.match(endModal, /<CorrectionReasonField visible=\{correction\.needed\}/);
  assert.match(endModal, /REPLACEMENT_CONTINUES_CODE/);
  assert.match(endModal, /confirm_replacement_continues: true/);
  assert.match(endModal, /\.\.\.correction\.payload/);
  assert.match(endModal, /<ServerAnswer error=\{error\}/, 'the answer stays visible inside the dialog');
  assert.match(detail, /const REPLACEMENT_CONTINUES_CODE = 'REPLACEMENT_CONTINUES_CONFIRMATION_REQUIRED'/);
  assert.match(detail, /error=\{endAssignmentMutation\.error\}/);
  assert.match(detail, /endAssignmentMutation\.reset\(\)/, 'a new opening does not show the previous attempt\'s error');
});

// ---- A-4: separation --------------------------------------------------------------------------------------------------------------------------------

test('A-4: Separation sends the away-holder confirmation once the server asks for it', () => {
  assert.match(separateModal, /confirm_replacement_continues: true/);
  assert.match(separateModal, /data-testid="away-separate-confirm"/);
  assert.match(separateModal, /error: unknown/);
  assert.match(detail, /error=\{separateMutation\.error\}/);
  assert.match(separateModal, /localToday\(\)/);
});

// ---- A-5 / A-8: Change Salary, Assign -----------------------------------------------------------------------------------------------------------

test('A-5: Change Salary and Assign carry a correction reason when the server asks for one', () => {
  const salary = section(detail, 'function ChangeSalaryModal(', 'function EndAssignmentModal(');
  const assign = section(detail, 'function AssignWorkLocationModal(', 'function SetWorkplaceModal(');
  for (const modal of [salary, assign]) {
    assert.match(modal, /useCorrectionFlow\(\)/);
    assert.match(modal, /\.\.\.correction\.payload/);
    assert.match(modal, /<CorrectionReasonField visible=\{correction\.needed\}/);
    assert.match(modal, /correction\.noteError\(err\)/);
  }
  assert.match(salary, /validation\.company\.salaryBeforeAssignment/);
});

test('A-8: Change salary is not offered on a Replacement; the inherited-salary note is shown instead', () => {
  assert.match(detail, /assignment\.assignment_type === 'replacement' \? \(\s*<span[^>]*data-testid="salary-inherited-note"/);
  assert.match(CATALOGUE['option.employeeDetail.salaryInheritedShort'].en, /inherited/);
});

// ---- A-7: modals start clean ----------------------------------------------------------------------------------------------------------------------

test('A-7: Employee Detail modals are mounted only while open, with the user\'s own calendar day as the default', () => {
  for (const flag of ['isAssignOpen', 'isSeparateOpen', 'isRehireOpen', 'isLeaveOpen', 'isReviewOpen', 'isPaymentOpen']) {
    assert.match(detail, new RegExp(`\\{${flag} && \\(`), `${flag} modal is mounted only while open`);
  }
  assert.doesNotMatch(detail, /toISOString\(\)\.slice\(0, 10\)/, 'no UTC date default is left on the page');
  const reopen = read('./CorrectionDialogs.tsx');
  assert.match(reopen, /setReason\(''\);\s*setCorrectionReason\(''\);\s*setServerError\(null\);\s*\}, \[row\?\.assignment_id\]\)/);
  const dialogs = read('./companyEmployees/AssignmentDialogs.tsx');
  assert.match(dialogs, /const today = \(\) => localToday\(\);/, 'B-6: the company dialogs no longer default to the UTC day');
  assert.doesNotMatch(dialogs, /new Date\(\)\.toISOString\(\)\.slice\(0, 10\)/);
});

// ---- A-9: unpaid leave company choices follow the dates ------------------------------------------------------------------------------------

test('A-9: only the companies the person works for ON THE LEAVE DATES are offered', () => {
  const rows = ([
    { client_company_id: 1, client_company_name: 'Old Co', status: 'ended', start_date: '2025-01-01', end_date: '2026-03-31' },
    { client_company_id: 2, client_company_name: 'Now Co', status: 'active', start_date: '2026-04-01', end_date: null },
  ] as unknown) as WorkAssignment[];
  assert.deepEqual(companyChoices(rows, 'Office').map((c) => c.name), ['Old Co', 'Now Co'], 'no dates: every non-cancelled company, as before');
  assert.deepEqual(companyChoices(rows, 'Office', { from: '2026-10-05', to: '2026-10-07' }).map((c) => c.name), ['Now Co'], 'a company the person left is not offered');
  assert.deepEqual(companyChoices(rows, 'Office', { from: '2026-03-30', to: '2026-04-02' }).map((c) => c.name), ['Old Co', 'Now Co'], 'both overlap the dates');
  assert.deepEqual(companyChoices(rows, 'Office', { from: '', to: '' }).length, 2, 'unfinished dates fall back to all');
  const leave = section(detail, 'function RecordLeaveModal(', 'function RecordPerformanceReviewModal(');
  assert.match(leave, /companyChoices\(assignments, officeLabel\(\), \{ from: startDate, to: endDate \}\)/);
});

// ---- UG-2: the company-page workflows are reachable from Employee Detail ------------------------------------------------------------------

test('UG-2: Replace, Return and Reopen are offered on Employee Detail only where the company page offers them', () => {
  assert.equal(canStartEmbeddedWorkflow('replace', base), true);
  assert.equal(canStartEmbeddedWorkflow('replace', { ...base, client_company_id: null }), false, 'the office has no company list');
  assert.equal(canStartEmbeddedWorkflow('replace', { ...base, status: 'ended' }), false);
  assert.equal(canStartEmbeddedWorkflow('return', base), false, 'a Permanent assignment is not returned to');
  assert.equal(canStartEmbeddedWorkflow('return', { ...base, assignment_type: 'replacement', replaces_assignment_id: 9 }), true);
  assert.equal(canStartEmbeddedWorkflow('return', { ...base, assignment_type: 'replacement', replaces_assignment_id: null }), false);
  assert.equal(canStartEmbeddedWorkflow('reopen', { ...base, status: 'ended' }), true);
  assert.equal(canStartEmbeddedWorkflow('reopen', base), false);
  assert.equal(canStartEmbeddedWorkflow('reopen', { ...base, status: 'ended', client_company_id: null }), false);
});

test('UG-2: the embedded workflows reuse the company page dialogs (no second copy) and refresh Employee Detail afterwards', () => {
  const host = read('./EmbeddedAssignmentWorkflows.tsx');
  assert.match(host, /<ReplaceEmployeeDialog row=\{row\}/);
  assert.match(host, /<ReturnEmployeeDialog row=\{row\}/);
  assert.match(host, /<ReopenAssignmentDialog row=\{row\}/);
  assert.match(host, /companyEmployeesApi\.list\(companyId as number/);
  assert.match(detail, /<EmbeddedAssignmentWorkflows workflow=\{embedded\}/);
  assert.match(detail, /startEmbedded\('replace', assignment\)/);
  assert.match(detail, /startEmbedded\('return', assignment\)/);
  assert.match(detail, /startEmbedded\('reopen', assignment\)/);
  const dialogs = read('./companyEmployees/AssignmentDialogs.tsx');
  assert.match(dialogs, /invalidateQueries\(\{ queryKey: \['employee'\] \}\)/);
  assert.match(read('./CorrectionDialogs.tsx'), /invalidateQueries\(\{ queryKey: \['employee'\] \}\)/);
  assert.match(detail, /invalidateQueries\(\{ queryKey: \['company-employees'\] \}\)/, 'A-14: Employee Detail refreshes the company lists too');
});

test('UG-2: the assignment list month stays inside the months the assignment covers', () => {
  assert.equal(assignmentListMonth('2026-01-01', null, '2026-11'), '2026-11');
  assert.equal(assignmentListMonth('2026-01-01', '2026-03-15', '2026-11'), '2026-03');
  assert.equal(assignmentListMonth('2027-02-01', null, '2026-11'), '2027-02');
});

// ---- UG-6 (A-10): the override dialog shows the server's reason -----------------------------------------------------------------------------

test('UG-6: the status override dialog shows the server\'s refusal inside the dialog and starts clean', () => {
  const override = read('./StatusOverrideDialog.tsx');
  assert.match(override, /data-testid="override-server-error"/);
  assert.match(override, /setServerError\(friendlyErrorMessage\(error/);
  assert.match(override, /if \(isOpen\) \{\s*setTarget\(''\);/);
});

// ---- A-11: Office Staff ------------------------------------------------------------------------------------------------------------------------------

test('A-11: Office Staff registration sends the correction reason only once the server asked for it', () => {
  const form = { ...emptyForm('2026-09-10'), position_id: '3', monthly_salary: '300', full_name: 'Test Person', phone: '0615000000', emergency_contact_name: 'E', emergency_contact_phone: '0615000001', guarantor_name: 'G', guarantor_phone: '0615000002' };
  assert.equal('correction_reason' in buildPayload('new', form, null, {}), false);
  assert.equal('correction_reason' in buildPayload('new', form, null, { correctionReason: '   ' }), false);
  assert.equal(buildPayload('new', form, null, { correctionReason: ' Started on 10 September ' }).correction_reason, 'Started on 10 September');
  const page = read('./OfficeStaffRegistrationPage.tsx');
  assert.match(page, /correction\.noteError\(error\)/);
  assert.match(page, /<CorrectionReasonField visible=\{correction\.needed\}/);
});

// ---- A-12 .. A-14 ------------------------------------------------------------------------------------------------------------------------------------

test('A-12: Former Employees asks before rehiring and only the clicked row shows the spinner', () => {
  const page = read('./FormerEmployeesPage.tsx');
  assert.match(page, /<ConfirmDialog/);
  assert.match(page, /setRehiring\(row\.original\)/);
  assert.match(page, /rehireMutation\.isPending && rehireMutation\.variables === row\.original\.id/);
});

test('A-13: failures show the server\'s words (never a raw "given data was invalid") and a matching fallback', () => {
  assert.doesNotMatch(detail, /err instanceof Error \? err\.message/);
  const closure = read('./CompanyClosureDialogs.tsx');
  assert.doesNotMatch(closure, /notify\.payroll\.reopenFailed/);
});

test('A-6 / B-1: the replacement notices exist as new proposed messages and the old ones are untouched', () => {
  assert.equal(CATALOGUE['warning.company.correctionNoticeBoth'].review, 'proposed');
  assert.equal(CATALOGUE['warning.company.startDateChangeBoth'].review, 'proposed');
  assert.match(CATALOGUE['warning.company.startDateChange'].en, /only a Super Admin can make the change/);
  assert.match(CATALOGUE['warning.company.startDateChangeBoth'].en, /a Super Admin or an HR Manager can make the change/);
});
