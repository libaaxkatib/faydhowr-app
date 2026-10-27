import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { scanSource, unallowed, type AllowEntry } from './untranslatedScan.ts';
import { CATALOGUE } from './messages/index.ts';

/**
 * Whole-panel coverage: every user-visible English text in a screen, a dialog or a helper goes through the catalogue, unless it is listed with a
 * reason in untranslated-allowlist.json (proper names, technical terms, the English default of a map that is translated by stored value at render).
 */

const SRC = fileURLToPath(new URL('../', import.meta.url));
const ALLOW = JSON.parse(readFileSync(new URL('./untranslated-allowlist.json', import.meta.url), 'utf8')) as AllowEntry[];

function files(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) return entry.name === 'i18n' ? [] : files(path);

    return /\.(ts|tsx)$/.test(entry.name) && !/\.test\.ts$/.test(entry.name) ? [path] : [];
  });
}

const sources = files(SRC).map((path) => ({ path: relative(SRC, path).replace(/\\/g, '/'), text: readFileSync(path, 'utf8') }));

test('no screen, dialog or helper shows English text that bypasses the catalogue', () => {
  const found = sources.flatMap((source) => scanSource(source.path, source.text));
  const remaining = unallowed(found, ALLOW);
  assert.deepEqual(
    remaining.map((f) => `${f.file}:${f.line} [${f.kind}] ${f.text.slice(0, 80)}`),
    [],
  );
  assert.ok(sources.length > 150, `only ${sources.length} files were scanned`);
});

test('every allowlist entry has a reason, names a file that exists, and still matches something (no stale entries)', () => {
  const found = sources.flatMap((source) => scanSource(source.path, source.text));
  for (const entry of ALLOW) {
    assert.ok(entry.reason.trim().length >= 10, `${entry.file}: "${entry.text}" has no reason`);
    assert.ok(sources.some((source) => source.path === entry.file), `${entry.file} does not exist`);
    assert.ok(found.some((f) => f.file === entry.file && (entry.text === '*' || f.text === entry.text)), `${entry.file}: "${entry.text}" matches nothing any more; remove it`);
  }
});

test('every Somali text differs from its English, except the listed names and acronyms that are the same in both languages', () => {
  const sameInBoth = new Set([
    'option.company.filterGuri', 'option.payroll.fieldGuri', 'status.facility.uniformCount', 'option.system.issueModule.hr', 'option.system.issueModule.marketing',
    'status.system.role.super_admin', 'status.system.role.hr_manager', 'status.system.role.marketing_manager', 'status.system.role.mobile_app_manager',
    'option.system.settingsCategory.smtp', 'option.system.settingField.company.facebook', 'option.system.settingField.company.instagram',
    'option.system.settingField.company.whatsapp', 'option.system.permissionGroup.hr', 'option.system.permissionGroup.marketing',
    'validation.field.facebook', 'validation.field.instagram', 'validation.field.whatsapp',
  ]);
  const identical = Object.entries(CATALOGUE).filter(([key, entry]) => entry.so.trim() === entry.en.trim() && !sameInBoth.has(key));
  assert.deepEqual(identical.map(([key]) => key), []);
});

test('no message has an empty Somali text (an empty one would silently fall back to English)', () => {
  const empty = Object.entries(CATALOGUE).filter(([, entry]) => entry.so.trim() === '');
  assert.deepEqual(empty.map(([key]) => key), []);
});
