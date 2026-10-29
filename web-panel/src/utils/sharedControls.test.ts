import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// 2026-10-29: the language switcher, the theme toggle and the other header controls belong to every signed-in person, whatever their role. They are
// shown by the shared header with no role or permission check around them, and the choices are kept in the browser (not in the account), so they
// survive navigation and reload for the HR Manager exactly as for the Super Admin. The real behaviour is checked in the browser QA; these guard the wiring.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('the header shows the language switcher and the theme toggle with no role or permission condition', () => {
  const header = read('../layouts/Header.tsx');
  assert.ok(header.includes('<LanguageSwitcher'));
  assert.ok(header.includes('<ThemeToggle'));
  for (const forbidden of ['usePermissions', 'useEffectivePermissions', 'isSuperAdmin', 'hasPermission', 'PermissionGate', 'admin?.role ===', 'admin.role ===']) {
    assert.ok(!header.includes(forbidden), `the header must not depend on ${forbidden}`);
  }
});

test('the app layout does not hide the header for any role', () => {
  const layout = read('../layouts/AppLayout.tsx');
  assert.ok(layout.includes('<Header'));
  for (const forbidden of ['isSuperAdmin', 'hasPermission', 'PermissionGate']) assert.ok(!layout.includes(forbidden), `the layout must not depend on ${forbidden}`);
});

test('the language and the theme are kept in the browser, not in the account, so every role keeps them', () => {
  assert.ok(read('../i18n/language.ts').includes('fayadhowr.ui.language'));
  assert.ok(read('../theme/theme.ts').includes('fayadhowr.ui.theme'));
});

test('a role without dashboard.view does not call the dashboard on every page', () => {
  const source = read('../hooks/usePermissions.ts');
  assert.ok(source.includes("hasPermission('dashboard.view')"));
  assert.ok(source.includes('enabled: mayAskDashboard'));
});

test('the payroll approval text no longer asks for a second person', () => {
  const source = read('../i18n/messages/payroll.ts');
  const start = source.indexOf("'confirm.payroll.approveBody'");
  const text = source.slice(start, source.indexOf("'confirm.payroll.theMonth'"));
  assert.ok(text.length > 100);
  assert.doesNotMatch(text, /different person|reviewer must approve/i);
  assert.doesNotMatch(text, /qof ka duwan/i);
});
