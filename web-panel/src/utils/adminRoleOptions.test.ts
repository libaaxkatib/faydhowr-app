import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { withCurrentRole } from './adminRoleOptions.ts';

const ASSIGNABLE = [{ value: 'hr_manager' }, { value: 'marketing_manager' }, { value: 'mobile_app_manager' }];

test('an assignable role keeps the list as it is', () => {
  assert.deepEqual(withCurrentRole(ASSIGNABLE, 'hr_manager').map((role) => role.value), ['hr_manager', 'marketing_manager', 'mobile_app_manager']);
});

test("a Super Admin's own role is shown first when that account is edited", () => {
  assert.deepEqual(withCurrentRole(ASSIGNABLE, 'super_admin').map((role) => role.value), ['super_admin', 'hr_manager', 'marketing_manager', 'mobile_app_manager']);
  assert.deepEqual(withCurrentRole(ASSIGNABLE, 'sales').map((role) => role.value)[0], 'sales');
});

test('creating an admin never offers Super Admin, and the shared list does not contain it', () => {
  const system = readFileSync(new URL('../types/system.ts', import.meta.url), 'utf8');
  const list = system.slice(system.indexOf('export const ASSIGNABLE_ADMIN_ROLES'), system.indexOf('export const ROLE_RESTRICTED_PERMISSIONS'));
  assert.ok(!list.includes('super_admin'));
  const dialog = readFileSync(new URL('../features/system/AdminFormDialog.tsx', import.meta.url), 'utf8');
  assert.ok(dialog.includes("admin && mode === 'edit' ? withCurrentRole(ASSIGNABLE_ADMIN_ROLES, admin.role) : ASSIGNABLE_ADMIN_ROLES"));
});
