import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

import { canCloseOrReopenCompany } from './accessRules.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';

// Items handed over by the five remediation packages and finished in the consolidation: the role rule for closing / reopening a company (finding D-11)
// and the Somali/English wording for four server refusals that had none (C-1, C-4, A-3 and UG-6). Every new message is `proposed`, never approved.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('closing or reopening a company is for the HR Manager and the Super Admin only, like the backend', () => {
  assert.equal(canCloseOrReopenCompany('super_admin'), true);
  assert.equal(canCloseOrReopenCompany('hr_manager'), true);
  assert.equal(canCloseOrReopenCompany('marketing_manager'), false);
  assert.equal(canCloseOrReopenCompany('hr_employee'), false);
  assert.equal(canCloseOrReopenCompany(undefined), false);
  assert.equal(canCloseOrReopenCompany(null), false);

  const page = read('../features/hrm/ClientCompaniesPage.tsx');
  assert.match(page, /const mayCloseCompany = canCloseOrReopenCompany\(admin\?\.role\);/);
  assert.match(page, /\{!mayCloseCompany \? null : company\.status === 'inactive' && company\.closure \? \(/);
  // The rest of the company actions keep their own hr.manage gate.
  assert.match(page, /<PermissionGate module="hr">/);
});

test('four server refusals now have wording in both languages, and the panel recognises their codes', () => {
  const helpers = read('./hrHelpers.ts');

  for (const code of ['CORRECTION_SUPERSEDED', 'WORKPLACE_NOT_IN_PAYROLL', 'END_BEFORE_ASSIGNMENT_START', 'EMPLOYEE_HAS_OPEN_ASSIGNMENTS']) {
    assert.match(helpers, new RegExp(`'${code}',`), `${code} is a known error code`);

    const entry = CATALOGUE[`notify.error.${code}` as keyof typeof CATALOGUE];
    assert.ok(entry.en.length > 20 && entry.so.length > 20, `${code} has English and Somali`);
    assert.equal(entry.review, 'proposed', `${code} is only proposed`);
    assert.equal((entry as { id?: string }).id, undefined, `${code} carries no owner id`);
  }
});
