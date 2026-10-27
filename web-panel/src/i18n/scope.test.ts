import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

/**
 * One translation mechanism for the whole panel. These checks read the source files and fail if the language code starts depending on anything
 * outside itself, if a screen decides its text with its own "if Somali" test instead of the catalogue, or if the language choice is not offered on the
 * sign-in page.
 */

const SRC = new URL('../', import.meta.url);

function sourceFiles(directory: URL): URL[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const child = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);

    return entry.isDirectory() ? sourceFiles(child) : /\.(ts|tsx)$/.test(entry.name) ? [child] : [];
  });
}

const relative = (file: URL) => file.pathname.split('/src/')[1];

test('the pure translation modules import nothing from the application (so Node can run them)', () => {
  for (const name of ['language.ts', 'catalog.ts', 'translate.ts', 'active.ts', 'dates.ts']) {
    const text = readFileSync(new URL(`i18n/${name}`, SRC), 'utf8');
    assert.ok(!/from\s+['"]@\//.test(text), `${name} must not use "@/" imports`);
  }
});

test('the language code does not read or write any other stored setting', () => {
  for (const file of sourceFiles(new URL('i18n/', SRC))) {
    if (relative(file).endsWith('.test.ts') || relative(file).includes('/baseline/')) continue;
    const text = readFileSync(file, 'utf8');
    assert.ok(!text.includes('fayadhowr.ui.theme'), `${relative(file)} must not mention another setting's storage key`);
    assert.ok(!/localStorage\.(?:get|set|remove)Item\(\s*['"](?!fayadhowr\.ui\.language)/.test(text), `${relative(file)} must only touch its own storage key`);
  }
});

test('no screen decides its own text with a language test: every text goes through the catalogue', () => {
  let scanned = 0;
  for (const file of sourceFiles(SRC)) {
    const path = relative(file);
    if (path.startsWith('i18n/') || path.endsWith('.test.ts')) continue;
    scanned++;
    const text = readFileSync(file, 'utf8');
    assert.ok(!/\blanguage\s*(?:===|!==|==)\s*['"](?:so|en)['"]/.test(text), `${path} must not branch on the language; use t() / tValue()`);
    assert.ok(!/\buseI18n\(\)\.language\s*(?:===|!==)/.test(text), `${path} must not branch on the language`);
  }
  assert.ok(scanned > 100, `only ${scanned} files were scanned`);
});

test('the sign-in page offers the language switcher', () => {
  const login = readFileSync(new URL('features/auth/LoginPage.tsx', SRC), 'utf8');
  assert.ok(login.includes('LanguageSwitcher'));
});
