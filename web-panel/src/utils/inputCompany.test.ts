import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { companyChoices, companyMissing, companyPayload, choiceValue } from './inputCompany.ts';

/**
 * A penalty, advance, unpaid leave or absence belongs to ONE company (each company has its own payroll). The server resolves it when the employee
 * works for a single company and refuses to guess when there are several (tested in CompanyPayrollInputLocksTest); these checks cover the panel's part:
 * when the choice is asked for, what is sent, and that the words exist in the language catalogue.
 */

const alpha = { client_company_id: 1, client_company_name: 'Alpha Co', status: 'active' } as const;
const alphaAgain = { client_company_id: 1, client_company_name: 'Alpha Co', status: 'active' } as const;
const bravo = { client_company_id: 2, client_company_name: 'Bravo Co', status: 'active' } as const;
const office = { client_company_id: null, client_company_name: null, status: 'active' } as const;
const cancelled = { client_company_id: 3, client_company_name: 'Gone Co', status: 'cancelled' } as const;

test('an employee of one company has nothing to choose, however many assignments there are', () => {
  const choices = companyChoices([alpha, alphaAgain], 'Fayadhowr Office');

  assert.deepEqual(choices, [{ id: 1, name: 'Alpha Co' }]);
  assert.equal(companyMissing(choices, ''), false);
  assert.deepEqual(companyPayload(choices, ''), {}, 'the server uses the only company');
});

test('an employee of several companies must choose, and the choice is sent as the company id', () => {
  const choices = companyChoices([alpha, bravo, cancelled], 'Fayadhowr Office');

  assert.deepEqual(choices.map(choiceValue), ['1', '2'], 'a cancelled assignment is not a choice');
  assert.equal(companyMissing(choices, ''), true);
  assert.equal(companyMissing(choices, '2'), false);
  assert.deepEqual(companyPayload(choices, '2'), { client_company_id: 2 });
  assert.deepEqual(companyPayload(choices, ''), {}, 'nothing is sent until a company is chosen (and the button stays disabled)');
});

test('the office is a choice of its own, named by the office label, and is sent as no company', () => {
  const choices = companyChoices([alpha, office], 'Fayadhowr Office');

  assert.deepEqual(choices.map((choice) => choice.name), ['Alpha Co', 'Fayadhowr Office']);
  assert.deepEqual(companyPayload(choices, 'none'), { client_company_id: null });
});

test('the penalty, advance and leave forms ask for the company and the attendance roster names the office', () => {
  const page = readFileSync(new URL('../features/hrm/EmployeeDetailPage.tsx', import.meta.url), 'utf8');
  const roster = readFileSync(new URL('../features/hrm/AttendancePage.tsx', import.meta.url), 'utf8');

  const recordDialog = readFileSync(new URL('../features/hrm/RecordDeductionDialog.tsx', import.meta.url), 'utf8');
  const correctDialog = readFileSync(new URL('../features/hrm/ManageDeductionDialogs.tsx', import.meta.url), 'utf8');

  assert.equal((page.match(/<CompanyChoiceField/g) ?? []).length, 1, 'unpaid leave');
  assert.equal((recordDialog.match(/<CompanyChoiceField/g) ?? []).length, 1, 'the one dialog that records a penalty or an advance');
  assert.equal((correctDialog.match(/<CompanyChoiceField/g) ?? []).length, 1, 'correcting a penalty or an advance can name the company too');
  assert.equal((page.match(/companyMissing\(/g) ?? []).length >= 1, true, 'the leave form stays disabled until a company is chosen');
  assert.equal((recordDialog.match(/companyMissing\(/g) ?? []).length >= 1, true, 'recording a penalty or an advance stays disabled until a company is chosen');
  assert.match(roster, /client_company_id: null/, 'the daily roster is the office roster');
});

test('every word the company choice uses is in the language catalogue, written as a proposal', () => {
  for (const key of ['option.employeeDetail.fieldCompany', 'option.employeeDetail.chooseCompany', 'option.employeeDetail.companyOwnsPayroll', 'option.employeeDetail.chargedToCompany', 'warning.employeeDetail.recordNoCompany'] as const) {
    assert.ok(CATALOGUE[key], key);
    assert.ok(CATALOGUE[key].en.length > 0 && CATALOGUE[key].so.length > 0, `${key} has English and Somali`);
    assert.notEqual(CATALOGUE[key].review, 'approved', `${key} is a proposal, not one of the 161 owner-approved messages`);
  }
});
