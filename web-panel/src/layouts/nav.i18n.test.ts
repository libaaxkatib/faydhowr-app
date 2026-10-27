import test from 'node:test';
import assert from 'node:assert/strict';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { NAV_GROUPS } from './nav.config.ts';

/**
 * The Marketing, Mobile App and System sidebar groups can be shown in Somali: each label carries a catalogue key, and nothing else about the
 * entries (route, permission, icon, exact-match, coming-soon) changed. Dashboard, XARUN, PROJECT and CMS deliberately stay as written.
 */

const STAYS_AS_WRITTEN = new Set(['Dashboard', 'XARUN', 'PROJECT', 'CMS']);

function group(label: string) {
  const found = NAV_GROUPS.find((candidate) => candidate.label === label);
  assert.ok(found, `${label} group is missing`);

  return found;
}

test('the group titles keep their English wording and have a Somali wording', () => {
  const expected: Record<string, string> = { Marketing: 'Suuqgeynta', 'Mobile App Management': 'Maamulka App-ka Mobaylka', System: 'Nidaamka' };
  for (const [label, somali] of Object.entries(expected)) {
    const key = group(label).labelKey;
    assert.ok(key, `${label} has no label key`);
    assert.equal(CATALOGUE[key].en, label);
    assert.equal(CATALOGUE[key].so, somali);
  }
});

test('every item outside Human Resources has a key whose English is its current label, except the four that stay as written', () => {
  for (const current of NAV_GROUPS.filter((candidate) => candidate.label !== 'Human Resources')) {
    for (const item of current.items) {
      if (STAYS_AS_WRITTEN.has(item.label)) {
        assert.equal(item.labelKey, undefined, `${current.label} / ${item.label} must not be translated`);
        continue;
      }
      assert.ok(item.labelKey, `${current.label} / ${item.label} has no key`);
      assert.equal(CATALOGUE[item.labelKey].en, item.label, `${current.label} / ${item.label}`);
      assert.notEqual(CATALOGUE[item.labelKey].so.trim(), '', `${item.label}: Somali is empty`);
    }
  }
});

test('routes, permissions, icons and flags of the Marketing, Mobile App and System items are unchanged', () => {
  const summary = (label: string) =>
    group(label).items.map((item) => [item.label, item.to, item.icon, item.permission ?? null, item.end ?? false, item.comingSoon ?? false]);

  assert.deepEqual(summary('Marketing'), [
    ['Dashboard', '/marketing', 'megaphone', 'marketing.view', true, false],
    ['XARUN', '/marketing/xarun', 'megaphone', 'marketing.view', false, false],
    ['PROJECT', '/marketing/project', 'briefcase', 'marketing.view', false, false],
    ['Follow-ups', '/marketing/follow-ups', 'bell', 'marketing.view', false, false],
    ['Teams', '/marketing/teams', 'users', 'marketing.view', false, false],
    ['Employees', '/marketing/employees', 'list', 'marketing.view', false, false],
    ['Commission', '/marketing/commission', 'credit-card', 'marketing.commission.view', false, false],
    ['Reports', '/marketing/reports', 'bar-chart', 'marketing.reports.view', false, false],
  ]);
  assert.deepEqual(summary('Mobile App Management'), [
    ['Customers', '/mobile-app/customers', 'users', 'customers.view', false, false],
    ['Services', '/mobile-app/services', 'star', null, false, true],
    ['Products', '/mobile-app/products', 'box', null, false, true],
    ['Bookings', '/mobile-app/bookings', 'calendar', null, false, true],
    ['Quotations', '/mobile-app/quotations', 'file-text', null, false, true],
    ['Orders', '/mobile-app/orders', 'cart', null, false, true],
    ['Payments', '/mobile-app/payments', 'credit-card', null, false, true],
    ['Inventory', '/mobile-app/inventory', 'box', null, false, true],
    ['Reviews', '/mobile-app/reviews', 'star', null, false, true],
    ['Notifications', '/mobile-app/notifications', 'bell', null, false, true],
    ['CMS', '/mobile-app/cms', 'image', null, false, true],
    ['Reports', '/mobile-app/reports', 'bar-chart', null, false, true],
    ['Accounting', '/mobile-app/accounting', 'credit-card', null, false, true],
  ]);
  assert.deepEqual(summary('System'), [
    ['Admin Users', '/system/admins', 'users', 'admins.manage', false, false],
    ['Roles & Permissions', '/system/roles', 'shield', 'roles.manage', false, false],
    ['Settings', '/system/settings', 'settings', 'settings.view', false, false],
    ['Audit Log', '/system/audit-log', 'list', 'admins.manage', false, false],
    ['Data Issues & Reconciliation', '/system/data-issues', 'alert-triangle', 'reconciliation.view', false, false],
  ]);
});

test('the group order is unchanged', () => {
  assert.deepEqual(
    NAV_GROUPS.map((candidate) => candidate.label),
    ['Human Resources', 'Marketing', 'Mobile App Management', 'System'],
  );
});

test('the shell and customers messages carry the English text the screens showed before', () => {
  const english: Record<string, string> = {
    'action.shell.signOut': 'Sign out',
    'action.shell.signIn': 'Sign in',
    'action.shell.changePassword': 'Change password',
    'action.shell.changePasswordSubmit': 'Change Password',
    'option.shell.signInTitle': 'Sign in to the Web Panel',
    'option.shell.internalOnly': 'Fayadhowr Web Panel — internal use only',
    'nav.shell.planned': 'Planned',
    'option.shell.nothingYet': 'Nothing here yet',
    'option.shell.searchPlaceholder': 'Search…',
    'warning.shell.accessRestricted': 'Access restricted',
    'confirm.customers.deleteBody': 'This soft-deletes {name}. The record can be restored later.',
    'confirm.customers.restoreBody': 'Restore {name} to Active status.',
    'notify.shell.requestFailed': 'Request failed with status {status}.',
  };
  for (const [key, text] of Object.entries(english)) assert.equal(CATALOGUE[key as keyof typeof CATALOGUE].en, text, key);
});

test('every dashboard card label the server sends has a Somali wording, and the customer statuses and roles are all covered', () => {
  for (const label of ['Bookings', 'Quotations', 'Orders', 'Payments', 'Revenue', 'Products', 'Customers']) {
    assert.ok(`status.shell.widget.${label}` in CATALOGUE, label);
  }
  for (const status of ['ACTIVE', 'INACTIVE', 'BLOCKED', 'DELETED']) assert.ok(`option.customers.status.${status}` in CATALOGUE, status);
  for (const role of ['super_admin', 'manager', 'sales', 'inventory', 'accountant', 'hr_manager', 'hr_employee', 'marketing_manager', 'marketing_employee', 'mobile_app_manager']) {
    assert.ok(`status.shell.role.${role}` in CATALOGUE, role);
  }
});
