import test from 'node:test';
import assert from 'node:assert/strict';

import { ADMIN_ROLE_LABELS, ASSIGNABLE_ADMIN_ROLES } from './system.ts';

test('the panel offers exactly the approved delegated Web Panel roles', () => {
  assert.deepEqual(
    ASSIGNABLE_ADMIN_ROLES.map((role) => role.value),
    ['hr_manager', 'marketing_manager', 'mobile_app_manager'],
  );
  assert.deepEqual(
    ASSIGNABLE_ADMIN_ROLES.map((role) => role.label),
    ['HR Manager', 'Marketing Manager', 'Mobile App Manager'],
  );
});

test('Super Admin, the separate Mobile App roles and the employee-level roles are never offered for assignment', () => {
  const offered = new Set(ASSIGNABLE_ADMIN_ROLES.map((role) => role.value as string));

  for (const role of ['super_admin', 'manager', 'sales', 'inventory', 'accountant', 'hr_employee', 'marketing_employee']) {
    assert.equal(offered.has(role), false, `${role} must not be assignable from the Web Panel`);
  }
});

test('every database role still has a display label, including the ones that cannot be assigned here', () => {
  assert.deepEqual(Object.keys(ADMIN_ROLE_LABELS).sort(), [
    'accountant', 'hr_employee', 'hr_manager', 'inventory', 'manager', 'marketing_employee',
    'marketing_manager', 'mobile_app_manager', 'sales', 'super_admin',
  ]);
});
