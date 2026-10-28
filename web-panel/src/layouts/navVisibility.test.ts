import test from 'node:test';
import assert from 'node:assert/strict';

import { NAV_GROUPS } from './nav.config.ts';
import { firstAllowedPath, isGroupVisible, isItemRenderable } from './navVisibility.ts';

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
  const hasPermission = permissionSetOf(['dashboard.view', 'hr.dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage']);

  const visibleGroups = groupLabels(hasPermission, false);
  assert.deepEqual(visibleGroups, ['Human Resources']);
  assert.ok(!visibleGroups.includes('Mobile App Management'));
  assert.ok(!visibleGroups.includes('Marketing'));
  assert.ok(!visibleGroups.includes('System'));

  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const renderable = hrGroup.items.filter((item) => isItemRenderable(item, hasPermission, false)).map((i) => i.label);
  // Every HR item requires hr.view or hr.reports.view, both granted here - all should render,
  // except Payroll (needs its own hr.payroll.view) and Excel Imports (hr.import, reserved for Super Admin).
  assert.deepEqual(
    renderable,
    hrGroup.items.filter((i) => i.permission !== 'hr.payroll.view' && i.permission !== 'hr.import').map((i) => i.label),
  );
  assert.ok(!renderable.includes('Payroll'));
  assert.ok(!renderable.includes('Excel Imports'));
});

test('Excel Imports is shown to a Super Admin only, never to an HR Manager - even one holding hr.import by mistake is not the gate', () => {
  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const imports = hrGroup.items.find((i) => i.label === 'Excel Imports')!;
  assert.equal(imports.permission, 'hr.import');
  assert.equal(imports.to, '/hr/imports');

  assert.equal(isItemRenderable(imports, permissionSetOf(['dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view']), false), false);
  assert.equal(isItemRenderable(imports, permissionSetOf([]), true), true, 'a Super Admin sees it');
  // (The backend ignores hr.import for every non-Super-Admin role, so a stray grant can never open the page.)
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
      if (item.comingSoon || item.superAdminOnly) continue;
      assert.ok(
        typeof item.permission === 'string' && item.permission.length > 0,
        `"${item.label}" in "${group.label}" has no permission tag - it would be visible to every role`,
      );
    }
  }
});

test('the HR Dashboard link needs hr.dashboard.view; someone with only hr.view still reaches the Employee screens', () => {
  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const dashboard = hrGroup.items.find((i) => i.label === 'Dashboard')!;
  const employees = hrGroup.items.find((i) => i.label === 'Employees')!;
  assert.equal(dashboard.permission, 'hr.dashboard.view');

  const withoutDashboard = permissionSetOf(['hr.view', 'hr.manage']);
  assert.equal(isItemRenderable(dashboard, withoutDashboard, false), false);
  assert.equal(isItemRenderable(employees, withoutDashboard, false), true);
  assert.equal(isItemRenderable(dashboard, permissionSetOf(['hr.dashboard.view']), false), true);
});

test('Companies & Workplaces is the new name, and the Facility Inspections entries need hr.inspections.view', () => {
  const hrGroup = NAV_GROUPS.find((g) => g.label === 'Human Resources')!;
  const labels = hrGroup.items.map((i) => i.label);
  assert.ok(labels.includes('Companies & Workplaces'));
  assert.ok(!labels.includes('Companies & Locations'), 'the old name is gone');

  for (const label of ['Facility Inspections', 'Complaints']) {
    const item = hrGroup.items.find((i) => i.label === label)!;
    assert.equal(item.permission, 'hr.inspections.view');
    assert.equal(isItemRenderable(item, permissionSetOf(['hr.view', 'hr.manage']), false), false);
    assert.equal(isItemRenderable(item, permissionSetOf(['hr.inspections.view']), false), true);
  }

  const report = hrGroup.items.find((i) => i.label === 'Facility Report')!;
  assert.equal(report.permission, 'hr.reports.view');
});

test('the sidebar lists Human Resources, Marketing, Mobile App Management, System in that order, and no group lost an entry', () => {
  const labels = NAV_GROUPS.map((g) => g.label);
  assert.deepEqual(labels, ['Human Resources', 'Marketing', 'Mobile App Management', 'System']);
  assert.ok(labels.indexOf('Marketing') < labels.indexOf('Mobile App Management'));
  assert.ok(labels.indexOf('Human Resources') < labels.indexOf('Mobile App Management'));

  // Only the order of the groups changed: every group still has all of its items and destinations.
  const sizes = Object.fromEntries(NAV_GROUPS.map((g) => [g.label, g.items.length]));
  assert.deepEqual(sizes, { Marketing: 8, 'Human Resources': 37, 'Mobile App Management': 13, System: 6 });
  assert.equal(new Set(NAV_GROUPS.flatMap((g) => g.items.map((i) => `${g.label}|${i.label}|${i.to}`))).size, 64);

  // Permission visibility is unchanged: an HR Manager still sees only Human Resources, a Mobile App Manager only the Mobile App group.
  assert.deepEqual(groupLabels(permissionSetOf(['hr.view']), false), ['Human Resources']);
  assert.deepEqual(groupLabels(permissionSetOf(['customers.view']), false), ['Mobile App Management']);
  assert.deepEqual(groupLabels(permissionSetOf([]), true), labels);
});

test('the Translations page is listed for Super Admin only, whatever other permissions an admin holds', () => {
  const system = NAV_GROUPS.find((group) => group.label === 'System')!;
  const translations = system.items.find((item) => item.to === '/system/translations')!;
  assert.ok(translations.superAdminOnly);

  const everyPermission = (): boolean => true;
  assert.equal(isItemRenderable(translations, everyPermission, false), false);
  assert.equal(isItemRenderable(translations, permissionSetOf(['settings.view', 'admins.manage', 'roles.manage']), false), false);
  assert.equal(isItemRenderable(translations, permissionSetOf([]), true), true);
});

test('an admin who may not open the Dashboard is sent to the first page they can use', () => {
  assert.equal(firstAllowedPath(NAV_GROUPS, permissionSetOf(['hr.dashboard.view', 'hr.view']), false), '/hr');
  assert.equal(firstAllowedPath(NAV_GROUPS, permissionSetOf(['hr.view']), false), '/hr/employees');
  assert.equal(firstAllowedPath(NAV_GROUPS, permissionSetOf(['marketing.view']), false), '/marketing');
  assert.equal(firstAllowedPath(NAV_GROUPS, permissionSetOf([]), false), null, 'nothing allowed: the page says so instead of redirecting');
});

test('a report that needs a second permission is listed only with both, like the page it opens', () => {
  const find = (to: string) => NAV_GROUPS.flatMap((g) => g.items).find((item) => item.to === to)!;
  for (const [to, second] of [
    ['/hr/reports/temporary-replacements', 'hr.view'],
    ['/hr/reports/financial-ledger', 'hr.view'],
    ['/hr/reports/payroll-rollup', 'hr.view'],
    ['/hr/reports/facility', 'hr.inspections.view'],
  ] as const) {
    const item = find(to);
    assert.equal(item.alsoPermission, second);
    assert.equal(isItemRenderable(item, permissionSetOf(['hr.reports.view']), false), false, to + ' with only hr.reports.view');
    assert.equal(isItemRenderable(item, permissionSetOf(['hr.reports.view', second]), false), true, to + ' with both');
    assert.equal(isItemRenderable(item, permissionSetOf([]), true), true, to + ' for Super Admin');
  }
});
