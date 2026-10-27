import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

import { LANGUAGE_STORAGE_KEY, LANG_ATTRIBUTE, parseLanguage } from './language.ts';

/**
 * index.html carries a tiny script that sets <html lang> before the first paint. It cannot import the TypeScript module, so it repeats its rule.
 * This test runs the real script from the real file against every stored value and page address and requires the same answer as language.ts
 * (Somali applies to every page; the page address plays no part).
 */

const html = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');
// index.html carries one early script per preference (language and colour theme); this file looks at the language one.
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]).filter((script) => script.includes(LANGUAGE_STORAGE_KEY));

test('index.html has exactly one inline language script, before the app bundle, and it reads the language key', () => {
  assert.equal(scripts.length, 1);
  assert.ok(html.indexOf('<script>') < html.indexOf('type="module"'));
  assert.ok(scripts[0].includes(LANGUAGE_STORAGE_KEY), 'reads the same storage key as the app');
  assert.match(html, /<html lang="en">/, 'the static page starts English');
});

type Stored = string | null | 'THROWS';

function runEarlyScript(stored: Stored, pathname: string | null): Record<string, string> {
  const attributes: Record<string, string> = {};
  const window = {
    get localStorage() {
      if (stored === 'THROWS') throw new Error('SecurityError');

      return { getItem: (key: string) => (key === LANGUAGE_STORAGE_KEY ? stored : null) };
    },
    location: pathname === null ? undefined : { pathname },
    matchMedia: () => ({ matches: false }),
  };
  const document = { documentElement: { setAttribute: (name: string, value: string) => void (attributes[name] = value) } };
  vm.runInNewContext(scripts[0], { window, document });

  return attributes;
}

test('the early script agrees with language.ts for every stored value and every page address', () => {
  const storedValues: Stored[] = [null, 'en', 'so', 'SO', 'somali', '', 'THROWS'];
  const paths: (string | null)[] = ['/hr', '/hr/employees', '/hr/employees/3', '/dashboard', '/system/admins', '/mobile-app/customers', '/marketing', '/hrx', '/', null];
  let combinations = 0;
  for (const stored of storedValues) {
    for (const path of paths) {
      const language = parseLanguage(stored === 'THROWS' ? null : stored);
      assert.equal(runEarlyScript(stored, path)[LANG_ATTRIBUTE], language, `stored=${stored} path=${path}`);
      combinations++;
    }
  }
  assert.equal(combinations, 70);
});

test('Somali is set before paint for a saved Somali choice on any page, English otherwise', () => {
  for (const path of ['/hr/employees', '/system/settings', '/marketing', '/login', '/dashboard']) assert.equal(runEarlyScript('so', path)[LANG_ATTRIBUTE], 'so', path);
  assert.equal(runEarlyScript(null, '/hr')[LANG_ATTRIBUTE], 'en');
  assert.equal(runEarlyScript('en', '/hr')[LANG_ATTRIBUTE], 'en');
  assert.equal(runEarlyScript('THROWS', '/hr')[LANG_ATTRIBUTE], 'en', 'blocked storage: English');
});

test('the early script sets only the lang attribute', () => {
  assert.deepEqual(Object.keys(runEarlyScript('so', '/hr')), [LANG_ATTRIBUTE]);
});
