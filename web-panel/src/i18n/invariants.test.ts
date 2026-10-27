import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { setActiveLanguage } from './active.ts';
import { LANGUAGES, type Language } from './language.ts';
import { CATALOGUE } from './messages/index.ts';
import { ASSIGNABLE_ADMIN_ROLES } from '../types/system.ts';
import { NAV_GROUPS } from '../layouts/nav.config.ts';
import { isGroupVisible, isItemRenderable, isRealItemVisible } from '../layouts/navVisibility.ts';
import { gateAllows, MODULE_ACTION_PERMISSION } from '../utils/permissionGate.ts';
import { differenceText, overrideWarning, paymentDiffers, toCents } from '../utils/temporaryPayment.ts';
import { canDownload, downloadQuery, fileName } from '../utils/paymentListFilter.ts';

/**
 * Showing the panel in Somali changes words only. These checks run the same logic once per language and require identical results, so a translation
 * can never change who may see or do something, nor any amount, file name or query that reaches the server.
 */

function inEachLanguage<T>(run: () => T): Record<Language, T> {
  const results = {} as Record<Language, T>;
  for (const language of LANGUAGES) {
    setActiveLanguage(language);
    results[language] = run();
  }
  setActiveLanguage('en');

  return results;
}

// ------------------------------------------------------------------ RBAC

test('the roles an admin can be given are unchanged and never include the Super Admin, in either language', () => {
  const { en, so } = inEachLanguage(() => ASSIGNABLE_ADMIN_ROLES.map((role) => role.value));
  assert.deepEqual(en, ['hr_manager', 'marketing_manager', 'mobile_app_manager']);
  assert.deepEqual(so, en);
  assert.ok(!en.includes('super_admin' as never));
});

test('which sidebar groups and items a person sees does not depend on the language', () => {
  const profiles: { name: string; has: (key: string) => boolean; superAdmin: boolean }[] = [
    { name: 'super admin', has: () => false, superAdmin: true },
    { name: 'no permissions', has: () => false, superAdmin: false },
    { name: 'hr.view only', has: (key) => key === 'hr.view', superAdmin: false },
    { name: 'marketing', has: (key) => key.startsWith('marketing.'), superAdmin: false },
    { name: 'everything but super admin', has: () => true, superAdmin: false },
  ];
  for (const profile of profiles) {
    const { en, so } = inEachLanguage(() =>
      NAV_GROUPS.map((group) => [
        group.label,
        isGroupVisible(group, profile.has, profile.superAdmin),
        group.items.map((item) => [item.label, item.to, item.permission ?? null, isRealItemVisible(item, profile.has, profile.superAdmin), isItemRenderable(item, profile.has, profile.superAdmin)]),
      ]),
    );
    assert.deepEqual(so, en, profile.name);
  }
});

test('the navigation targets and permission keys are plain data: translating a label never touches them', () => {
  for (const group of NAV_GROUPS) {
    for (const item of group.items) {
      assert.ok(item.to.startsWith('/'), item.label);
      if (item.permission) assert.match(item.permission, /^[a-z_]+(\.[a-z_]+)+$/, `${item.label}: permission key`);
      assert.notEqual(item.to, item.labelKey ? CATALOGUE[item.labelKey].so : undefined);
    }
  }
});

test('the permission gate for action buttons asks for the same keys in both languages', () => {
  assert.deepEqual(MODULE_ACTION_PERMISSION, { hr: 'hr.manage', marketing: 'marketing.manage' });
  const asked = inEachLanguage(() => {
    const keys: string[] = [];
    gateAllows('hr', (key) => (keys.push(key), true), () => true);
    gateAllows('marketing', (key) => (keys.push(key), true), () => true, 'marketing.assign');

    return keys;
  });
  assert.deepEqual(asked.en, ['hr.manage', 'marketing.assign']);
  assert.deepEqual(asked.so, asked.en);
});

// ------------------------------------------------------------------ payroll

test('amounts, differences and the payment-list query are identical in both languages; only the words differ', () => {
  const numbers = inEachLanguage(() => ({
    cents: [toCents('12.50'), toCents(12.5), toCents(''), toCents(null), toCents('abc')],
    differs: [paymentDiffers('100.00', '100.00'), paymentDiffers('100.00', '90.00'), paymentDiffers(null, 5), paymentDiffers('10.005', '10.01')],
    query: downloadQuery('2026-10', 'company', '7'),
    allQuery: downloadQuery('2026-10', 'all', ''),
    file: fileName('2026-10', null),
    canAll: canDownload('all', '', undefined),
  }));
  assert.deepEqual(numbers.so, numbers.en);

  const words = inEachLanguage(() => [differenceText('100.00', '90.00'), overrideWarning('100.00', '90.00')]);
  assert.notDeepEqual(words.so, words.en, 'the words follow the language');
  for (const text of [...words.so, ...words.en]) assert.ok(text && /(100\.00|90\.00|10\.00)/.test(text), 'the amounts stay in the text');
});

test('the Excel payment list keeps its English column headings: the backend defines them and the panel does not translate them', () => {
  const php = readFileSync(new URL('../../../backend/app/Support/Hr/Payroll/MonthlyPaymentReportExporter.php', import.meta.url), 'utf8');
  const headers = /PAYMENT_LIST_HEADERS = \[\s*([^\]]+?)\s*\];/.exec(php);
  assert.ok(headers);
  assert.deepEqual(
    [...headers[1].matchAll(/'([^']+)'/g)].map((m) => m[1]),
    ['Employee', 'NUMBER', 'COMPANY OR GURI', 'Type', 'Pay amount', 'Row type', 'Linked employee', 'Cover period', 'Paid to date', 'Balance'],
  );
});

// ------------------------------------------------------------------ the language layer itself

test('no message is shown as empty in either language', () => {
  for (const [key, entry] of Object.entries(CATALOGUE)) {
    assert.notEqual(entry.en.trim(), '', `${key}: English is empty`);
  }
});
