import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { multiCompanyPolicyCardVisible } from './policyCardVisibility.ts';

test('the Super Admin sees the multi-company card; the HR Manager does not', () => {
  assert.equal(multiCompanyPolicyCardVisible('super_admin'), true);
  assert.equal(multiCompanyPolicyCardVisible('hr_manager'), false);
});

test('nothing is shown before the signed-in role is known', () => {
  assert.equal(multiCompanyPolicyCardVisible(undefined), false);
});

test('the card does not ask the server for the setting when it is hidden, and renders nothing', () => {
  const source = readFileSync(new URL('../features/hrm/companyEmployees/MultiCompanyPolicyCard.tsx', import.meta.url), 'utf8');
  assert.ok(source.includes('multiCompanyPolicyCardVisible(admin?.role)'));
  assert.ok(source.includes('enabled: visible'));
  assert.ok(source.includes('if (!visible || !policy) return null;'));
});

test('the card is still mounted on the companies page for those who may see it (the setting was not removed)', () => {
  assert.ok(readFileSync(new URL('../features/hrm/ClientCompaniesPage.tsx', import.meta.url), 'utf8').includes('<MultiCompanyPolicyCard />'));
});
