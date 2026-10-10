import test from 'node:test';
import assert from 'node:assert/strict';

import { NAV_GROUPS } from './nav.config.ts';
import { isGroupVisible, isItemRenderable } from './navVisibility.ts';

/**
 * Covers the actual bug: the sidebar previously showed every NAV_GROUPS
 * entry to every authenticated admin, regardless of role. These tests run
 * the real NAV_GROUPS data (not a mock) through the real visibility
 * functions Sidebar.tsx uses, standing in for each role's real effective
 * permissions the way useEffectivePermissions() would resolve them.
 *
 * Run with: node --test src/layouts/navVisibility.test.ts
 * (Node's built-in test runner + native TypeScript support - no new
 * dependency or test framework was added for this.)
 */

function permissionSetOf(keys: string[]): (key: string) => boolean {
  const set = new Set(keys);
  return (key: string) => set.has(key);
}

function groupLabels(hasPermission: (key: string) => boolean, isSuperAdmin: boolean): string[] {
  return NAV_GROUPS.filter((group) => isGroupVisible(group, hasPermission, isSuperAdmin)).map((group) => group.label);
}

test('HR Manager with the intended HR grant sees only Human Resources, nothing else', () => {
  const hasPermission = permissionSetOf(['dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view']);

  const visibleGroups = groupLabels(hasPermission, false);
  assert.deepEqual(visibleGroups, ['Human Resources']);
  assert.ok(!visibleGroups.includes('Mobile App Management'));
  assert.ok(!visibleGroups.includes('Marketing'));
  assert.ok(!visibleGroups.includes('System'));

  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const renderable = hrGroup.items.filter((item) => isItemRenderable(item, hasPermission, false)).map((i) => i.label);
  // Every HR item requires hr.view or hr.reports.view, both granted here - all should render,
  // except Payroll, which needs its own hr.payroll.view permission (not part of this grant).
  assert.deepEqual(
    renderable,
    hrGroup.items.filter((i) => i.permission !== 'hr.payroll.view').map((i) => i.label),
  );
  assert.ok(!renderable.includes('Payroll'));
});

test('Payroll nav item appears only with hr.payroll.view (or for a Super Admin)', () => {
  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const payroll = hrGroup.items.find((i) => i.label === 'Payroll')!;
  assert.equal(payroll.permission, 'hr.payroll.view');

  const withoutIt = permissionSetOf(['hr.view', 'hr.manage', 'hr.payroll.manage']);
  const withIt = permissionSetOf(['hr.view', 'hr.payroll.view']);
  assert.equal(isItemRenderable(payroll, withoutIt, false), false);
  assert.equal(isItemRenderable(payroll, withIt, false), true);
  assert.equal(isItemRenderable(payroll, permissionSetOf([]), true), true);
});

test('HR Manager with zero grants sees no group at all - a title/submenu alone never shows a group', () => {
  const hasPermission = permissionSetOf([]);

  assert.deepEqual(groupLabels(hasPermission, false), []);
});

test('Marketing Manager sees only Marketing', () => {
  const hasPermission = permissionSetOf(['dashboard.view', 'marketing.view', 'marketing.manage']);

  const visibleGroups = groupLabels(hasPermission, false);
  assert.deepEqual(visibleGroups, ['Marketing']);
  assert.ok(!visibleGroups.includes('Human Resources'));
  assert.ok(!visibleGroups.includes('Mobile App Management'));
});

test('Mobile App Manager with customers.view sees Mobile App Management, with its comingSoon placeholders, but nothing else', () => {
  const hasPermission = permissionSetOf(['dashboard.view', 'customers.view']);

  const visibleGroups = groupLabels(hasPermission, false);
  assert.deepEqual(visibleGroups, ['Mobile App Management']);

  const mobileGroup = NAV_GROUPS.find((g) => g.label === 'Mobile App Management')!;
  const renderable = mobileGroup.items.filter((item) => isItemRenderable(item, hasPermission, false)).map((i) => i.label);
  // Customers (real, permitted) plus every comingSoon placeholder - identical to today's
  // pre-fix UX for a role that does have real access to this group.
  assert.deepEqual(renderable, mobileGroup.items.map((i) => i.label));
});

test('Mobile App Manager with zero grants does not see Mobile App Management at all', () => {
  const hasPermission = permissionSetOf([]);

  assert.deepEqual(groupLabels(hasPermission, false), []);
});

test('Super Admin sees every group regardless of hasPermission', () => {
  const hasPermission = permissionSetOf([]); // deliberately empty - isSuperAdmin alone must be enough

  const visibleGroups = groupLabels(hasPermission, true);
  assert.deepEqual(
    visibleGroups,
    NAV_GROUPS.map((g) => g.label),
  );
});

test('every real (non-comingSoon) nav item declares the permission its own page requires', () => {
  for (const group of NAV_GROUPS) {
    for (const item of group.items) {
      if (item.comingSoon) continue;
      assert.ok(
        typeof item.permission === 'string' && item.permission.length > 0,
        `"${item.label}" in "${group.label}" has no permission tag - it would be visible to every role`,
      );
    }
  }
});
