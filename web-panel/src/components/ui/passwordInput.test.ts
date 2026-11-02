import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { passwordInputType, toggledVisibility } from '../../utils/passwordVisibility.ts';

const SRC = fileURLToPath(new URL('../../', import.meta.url));
const component = readFileSync(join(SRC, 'components/ui/PasswordInput.tsx'), 'utf8');

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sources(path);
    return /\.(tsx|ts)$/.test(name) && !/\.test\.ts$/.test(name) ? [path] : [];
  });
}

test('a password is hidden by default and the eye only flips what is drawn', () => {
  assert.equal(passwordInputType(false), 'password');
  assert.equal(passwordInputType(true), 'text');
  assert.equal(toggledVisibility(false), true);
  assert.equal(toggledVisibility(true), false);
  assert.equal(toggledVisibility(toggledVisibility(false)), false, 'two clicks bring it back to hidden');
});

test('the component starts hidden, never submits a form, keeps focus in the field and announces its state', () => {
  assert.match(component, /useState\(false\)/, 'hidden by default');
  assert.match(component, /<button\s[^>]*type="button"/s, 'the toggle can never submit a form');
  assert.match(component, /onMouseDown=\{\(event\) => event\.preventDefault\(\)\}/, 'a click does not take focus away from the field');
  assert.match(component, /aria-pressed=\{visible\}/);
  assert.match(component, /aria-label=\{visible \? t\('action\.shell\.hidePassword'\) : t\('action\.shell\.showPassword'\)\}/);
  assert.match(component, /aria-controls=\{rest\.id\}/);
  assert.match(component, /\{\.\.\.rest\}/, 'name, autoComplete, value, minLength and the rest reach the input untouched');
  assert.doesNotMatch(component, /console\.|localStorage|sessionStorage|fetch\(|track\(/, 'the password is never logged, stored or sent from here');
});

test('every password field of the panel is the shared component: no raw password input is left anywhere', () => {
  const offenders: string[] = [];

  for (const file of sources(SRC)) {
    if (file.endsWith(`${sep}PasswordInput.tsx`)) continue;
    const text = readFileSync(file, 'utf8');
    if (/type=(\{)?['"]password['"]/.test(text)) offenders.push(relative(SRC, file));
  }

  assert.deepEqual(offenders, [], 'use <PasswordInput> instead of <input type="password">');
});

test('the login, change password, reset password, admin, customer, marketing member and settings forms use it', () => {
  for (const file of [
    'features/auth/LoginPage.tsx',
    'features/auth/ChangePasswordDialog.tsx',
    'features/system/ResetPasswordDialog.tsx',
    'features/system/AdminFormDialog.tsx',
    'features/customers/CustomerFormDialog.tsx',
    'features/marketing/AddTeamMemberDialog.tsx',
    'features/system/SettingsPage.tsx',
  ]) {
    const text = readFileSync(join(SRC, file), 'utf8');
    assert.match(text, /<PasswordInput\b/, `${file} renders <PasswordInput>`);
    assert.match(text, /from '@\/components\/ui\/PasswordInput'/, `${file} imports it`);
  }

  const login = readFileSync(join(SRC, 'features/auth/LoginPage.tsx'), 'utf8');
  assert.match(login, /autoComplete="current-password"/, 'the login field keeps its password-manager hint');
  assert.match(readFileSync(join(SRC, 'features/marketing/AddTeamMemberDialog.tsx'), 'utf8'), /autoComplete="new-password"/);
});
