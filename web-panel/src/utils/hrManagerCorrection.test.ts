import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { CATALOGUE } from '../i18n/messages/index.ts';

/**
 * Owner decision 2026-11-04: an HR Manager may add an employee to a company whose payroll is already approved or finalized, as an HR correction with a
 * reason; everything else about closed payroll stays Super Admin only. The server decides who may (ClosedPayrollCorrection, tested in
 * HrManagerAssignmentCorrectionTest); the screen must agree with it: it never hides or blocks the Add Employee dialog by role, it shows the server's
 * "this reaches closed payroll" answer with the right notice, and the other dialogs keep their Super Admin wording.
 */
const dialogs = readFileSync(new URL('../features/hrm/companyEmployees/AssignmentDialogs.tsx', import.meta.url), 'utf8');

test('the dialogs ask the server, not the role: no role check can disagree with the backend', () => {
  assert.ok(!/isSuperAdmin|super_admin|hr_manager|useAuth|role ===/.test(dialogs), 'who may record the correction is decided by the server only');
});

test('the Add Employee dialog keeps its own notice and every other dialog now says a Super Admin OR an HR Manager can record the correction', () => {
  const add = dialogs.slice(dialogs.indexOf('export function AssignEmployeeDialog'), dialogs.indexOf('export function EditAssignmentDialog'));

  assert.match(add, /notice="warning\.company\.correctionNoticeAdd"/);
  const others = dialogs.slice(dialogs.indexOf('export function EditAssignmentDialog'));
  assert.ok(!others.includes('correctionNoticeAdd'), 'editing, replacing, returning and stopping use the general notice');
  // (updated 2026-11-05, audit A-6 / B-1: the old notice said "Only a Super Admin"; owner decision 2026-11-04 lets an HR Manager record any closed-payroll correction)
  assert.match(dialogs, /notice = 'warning\.company\.correctionNoticeBoth'/);
  assert.ok(!/notice = 'warning\.company\.correctionNotice'/.test(dialogs), 'the stale notice is no longer the default');
});

test('the notice explains the HR correction, that the closed month is untouched and that paying what is owed stays with a Super Admin', () => {
  const entry = CATALOGUE['warning.company.correctionNoticeAdd'];

  assert.equal(entry.review, 'proposed');
  assert.match(entry.en, /already approved or finalized/);
  assert.match(entry.en, /HR correction/);
  assert.match(entry.en, /closed month stays exactly as it was/);
  assert.match(entry.en, /Super Admin/);
  assert.notEqual(entry.en, entry.so);
  // The OLD key is left defined and unchanged (catalogue messages are never reworded); the screens use the new one.
  assert.match(CATALOGUE['warning.company.correctionNotice'].en, /Only a Super Admin can record it/);
  const both = CATALOGUE['warning.company.correctionNoticeBoth'];
  assert.match(both.en, /A Super Admin or an HR Manager can record it/);
  assert.ok(!/Only a Super Admin/.test(both.en));
  assert.equal(both.review, 'proposed');
  assert.notEqual(both.en, both.so);
});

test('the reason field is required before saving a correction (the server checks it again) and success is shown only after the server saved', () => {
  assert.match(dialogs, /correctionError\(state, correctionReason, t\)/);
  assert.match(dialogs, /reason\.trim\(\)\.length < 5/);
});
