import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

import { THEME_ATTRIBUTE, THEME_STORAGE_KEY, parseThemeMode, resolveTheme } from './theme.ts';

/**
 * index.html carries a tiny script that sets <html data-theme> before the first paint (so a reload never flashes the wrong theme). It cannot
 * import the TypeScript module, so it repeats its rules. This test runs the real script from the real file against every combination of stored
 * value and operating-system preference and requires the same answer as src/theme/theme.ts.
 */

const html = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');
// index.html carries one early script per preference (colour theme and language); this file looks at the theme one.
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]).filter((script) => script.includes(THEME_STORAGE_KEY));

test('index.html has exactly one inline early-theme script, placed in <head> before the app bundle', () => {
  assert.equal(scripts.length, 1);
  assert.ok(html.indexOf('<script>') < html.indexOf('type="module"'), 'runs before the app script');
  assert.ok(html.indexOf('<script>') < html.indexOf('<body>'), 'runs before the body is parsed');
  assert.ok(scripts[0].includes(THEME_STORAGE_KEY), 'reads the same storage key as the app');
});

type Stored = string | null | 'THROWS';
type System = boolean | 'THROWS';

/** Runs the inline script in a sandbox that looks like a browser and returns the data-theme it set (or null if it set nothing). */
function runEarlyScript(stored: Stored, system: System): string | null {
  const attributes: Record<string, string> = {};
  const window = {
    get localStorage() {
      if (stored === 'THROWS') throw new Error('SecurityError');

      return { getItem: (key: string) => (key === THEME_STORAGE_KEY ? stored : null) };
    },
    matchMedia: () => {
      if (system === 'THROWS') throw new Error('matchMedia unavailable');

      return { matches: system };
    },
  };
  const document = { documentElement: { setAttribute: (name: string, value: string) => void (attributes[name] = value) } };
  vm.runInNewContext(scripts[0], { window, document });

  return attributes[THEME_ATTRIBUTE] ?? null;
}

test('the early script agrees with theme.ts for every stored value and every operating-system preference', () => {
  const storedValues: Stored[] = [null, 'light', 'dark', 'system', 'Dark', 'neon', '', 'THROWS'];
  const systems: System[] = [true, false, 'THROWS'];
  let combinations = 0;
  for (const stored of storedValues) {
    for (const system of systems) {
      const mode = parseThemeMode(stored === 'THROWS' ? null : stored);
      const prefersDark = system === true;
      assert.equal(runEarlyScript(stored, system), resolveTheme(mode, prefersDark), `stored=${stored} system=${system}`);
      combinations++;
    }
  }
  assert.equal(combinations, 24);
});

test('a first visit is Light even when the operating system is dark, and System follows the system', () => {
  assert.equal(runEarlyScript(null, true), 'light');
  assert.equal(runEarlyScript('system', true), 'dark');
  assert.equal(runEarlyScript('system', false), 'light');
  assert.equal(runEarlyScript('dark', false), 'dark');
  assert.equal(runEarlyScript('THROWS', true), 'light', 'blocked storage: default Light');
});
