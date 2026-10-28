import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import vm from 'node:vm';

import tailwind from '../../tailwind.config.ts';
import { LANGUAGE_STORAGE_KEY, LANGUAGES, readStoredLanguage, writeStoredLanguage, type Language } from '../i18n/language.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { THEME_MODES, THEME_OPTIONS, THEME_STORAGE_KEY, createThemeController, readStoredThemeMode, type ThemeMode } from './theme.ts';

/**
 * The colour theme and the language are two independent preferences that share one page. These checks run them together: every combination
 * persists, neither can overwrite or reset the other, both early scripts in index.html work side by side, the switcher's own text comes from the
 * translation catalogue, and no source file uses a colour that is not a theme token.
 */

class MemoryStorage {
  readonly values = new Map<string, string>();
  getItem(key: string): string | null {
    return this.values.get(key) ?? null;
  }
  setItem(key: string, value: string): void {
    this.values.set(key, value);
  }
}

const noSystem = () => ({ matches: false });
const root = () => ({ attributes: {} as Record<string, string>, setAttribute(name: string, value: string) { this.attributes[name] = value; } });

// ------------------------------------------------------------------ independence

test('every combination of language and colour theme is saved, and survives a reload', () => {
  for (const language of LANGUAGES) {
    for (const mode of THEME_MODES) {
      const storage = new MemoryStorage();
      writeStoredLanguage(storage, language);
      createThemeController({ storage, matchMedia: noSystem, root: root() }).setMode(mode);

      // "reload": new objects over the same storage
      assert.equal(readStoredLanguage(storage), language, `${language}/${mode}: language`);
      const reloaded = createThemeController({ storage, matchMedia: noSystem, root: root() });
      assert.equal(reloaded.getState().mode, mode, `${language}/${mode}: theme`);
    }
  }
});

test('switching the theme never touches the language, in either language', () => {
  for (const language of LANGUAGES) {
    const storage = new MemoryStorage();
    writeStoredLanguage(storage, language);
    const before = storage.values.get(LANGUAGE_STORAGE_KEY);
    const controller = createThemeController({ storage, matchMedia: noSystem, root: root() });
    for (const mode of [...THEME_MODES, 'dark', 'light'] as ThemeMode[]) controller.setMode(mode);

    assert.equal(storage.values.get(LANGUAGE_STORAGE_KEY), before, `${language} language value`);
    assert.equal(readStoredLanguage(storage), language);
    assert.deepEqual([...storage.values.keys()].sort(), [LANGUAGE_STORAGE_KEY, THEME_STORAGE_KEY].sort(), 'only the two preference keys exist');
  }
});

test('switching the language never touches the theme, and a language switch does not change what is painted', () => {
  for (const mode of THEME_MODES) {
    const storage = new MemoryStorage();
    const page = root();
    const controller = createThemeController({ storage, matchMedia: noSystem, root: page });
    controller.setMode(mode);
    const painted = page.attributes['data-theme'];
    for (const language of [...LANGUAGES, 'en'] as Language[]) writeStoredLanguage(storage, language);

    assert.equal(readStoredThemeMode(storage), mode);
    assert.equal(page.attributes['data-theme'], painted);
  }
});

test('the two preferences use different keys and different html attributes', () => {
  assert.notEqual(THEME_STORAGE_KEY, LANGUAGE_STORAGE_KEY);
  assert.equal(THEME_STORAGE_KEY, 'fayadhowr.ui.theme');
  assert.equal(LANGUAGE_STORAGE_KEY, 'fayadhowr.ui.language');
});

test('the default is unchanged by this work: Light, and English', () => {
  const storage = new MemoryStorage();
  const controller = createThemeController({ storage, matchMedia: () => ({ matches: true }), root: root() });
  assert.deepEqual(controller.getState(), { mode: 'light', resolved: 'light' }, 'Light even on a dark operating system until the user chooses System');
  assert.equal(readStoredLanguage(storage), 'en');
  assert.equal(storage.values.size, 0, 'a first visit writes nothing');
});

// ------------------------------------------------------------------ index.html: two early scripts

const html = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]);

function runBothScripts(languageValue: string | null, themeValue: string | null, systemDark: boolean): Record<string, string> {
  const attributes: Record<string, string> = {};
  const window = {
    localStorage: { getItem: (key: string) => (key === LANGUAGE_STORAGE_KEY ? languageValue : key === THEME_STORAGE_KEY ? themeValue : null) },
    matchMedia: () => ({ matches: systemDark }),
  };
  const document = { documentElement: { setAttribute: (name: string, value: string) => void (attributes[name] = value) } };
  for (const script of scripts) vm.runInNewContext(script, { window, document });

  return attributes;
}

test('index.html has both early scripts, each before the app bundle, and each sets only its own attribute', () => {
  assert.equal(scripts.length, 2);
  assert.equal(scripts.filter((script) => script.includes(LANGUAGE_STORAGE_KEY)).length, 1);
  assert.equal(scripts.filter((script) => script.includes(THEME_STORAGE_KEY)).length, 1);
  assert.ok(html.lastIndexOf('<script>') < html.indexOf('type="module"'));
  for (const script of scripts) {
    const sets = [...script.matchAll(/setAttribute\('([a-z-]+)'/g)].map((match) => match[1]);
    assert.equal(sets.length, 1, 'one attribute per script');
  }
});

test('saved language and saved theme are both applied before the first paint, in every combination', () => {
  for (const [language, expectedLang] of [['so', 'so'], ['en', 'en'], [null, 'en'], ['garbage', 'en']] as const) {
    for (const [theme, system, expectedTheme] of [['dark', false, 'dark'], ['light', true, 'light'], ['system', true, 'dark'], ['system', false, 'light'], [null, true, 'light'], ['garbage', true, 'light']] as const) {
      assert.deepEqual(runBothScripts(language, theme, system), { lang: expectedLang, 'data-theme': expectedTheme }, `${language}/${theme}/${system}`);
    }
  }
});

// ------------------------------------------------------------------ the switcher is translated

const toggle = readFileSync(new URL('../components/ui/ThemeToggle.tsx', import.meta.url), 'utf8');

test('the switcher takes its group name and each mode name from the catalogue, in both languages', () => {
  for (const key of ['option.theme.group', 'option.theme.light', 'option.theme.dark', 'option.theme.system'] as const) {
    const entry = CATALOGUE[key];
    assert.ok(entry.en.trim() && entry.so.trim(), key);
    assert.notEqual(entry.en, entry.so, `${key}: Somali differs from English`);
    assert.equal(entry.review, 'proposed', `${key}: new wording is a proposal`);
  }
  assert.equal(THEME_OPTIONS.length, 3);
  assert.ok(!/aria-label="[A-Za-z]/.test(toggle) && !/title="[A-Za-z]/.test(toggle), 'no English text is written into the switcher');
  for (const mode of THEME_MODES) assert.ok(toggle.includes(`'option.theme.${mode}'`), `${mode} has a catalogue key in the switcher`);
});

test('the three switcher names in Somali are distinct, so a screen reader can tell the options apart', () => {
  const names = ['option.theme.light', 'option.theme.dark', 'option.theme.system'].map((key) => CATALOGUE[key as 'option.theme.light'].so);
  assert.equal(new Set(names).size, 3);
});

// ------------------------------------------------------------------ every colour in the source is a theme token

function sourceFiles(directory: URL): URL[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const child = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);
    if (entry.isDirectory()) return sourceFiles(child);

    return entry.name.endsWith('.tsx') && !entry.name.endsWith('.test.tsx') ? [child] : [];
  });
}

const SRC = new URL('../', import.meta.url);
const files = sourceFiles(SRC).map((file) => ({ path: file.pathname.split('/src/')[1], text: readFileSync(file, 'utf8') }));

/** The utility names the Tailwind config defines for the semantic colours: 'primary', 'primary-soft', 'ink-muted', 'border', ... */
function definedColourNames(): Set<string> {
  const names = new Set<string>();
  const extend = (tailwind as { theme: { extend: { colors: Record<string, Record<string, string>>; textColor: Record<string, Record<string, string>> } } }).theme.extend;
  for (const group of [extend.colors, extend.textColor]) {
    for (const [name, shades] of Object.entries(group)) {
      for (const shade of Object.keys(shades)) names.add(shade === 'DEFAULT' ? name : `${name}-${shade}`);
    }
  }

  return names;
}

const TOKEN_ROOTS = ['primary', 'secondary', 'surface', 'ink', 'border', 'success', 'warning', 'danger', 'sidebar'];
const UTILITY = /\b(?:bg|text|border(?:-[trblxy])?|ring|ring-offset|divide|fill|stroke|from|to|via|placeholder|outline|accent|caret|decoration)-((?:primary|secondary|surface|ink|border|success|warning|danger|sidebar)(?:-[a-z]+)?)(?=[\s/"'`}:]|$)/g;

test('every semantic colour class used in a screen exists in the theme (a misspelt token would be invisible in both themes)', () => {
  const defined = definedColourNames();
  const unknown: string[] = [];
  for (const file of files) {
    for (const match of file.text.matchAll(UTILITY)) {
      if (!defined.has(match[1])) unknown.push(`${file.path}: ${match[0]}`);
    }
  }
  assert.deepEqual([...new Set(unknown)], []);
  assert.ok(TOKEN_ROOTS.every((root) => defined.has(root) || [...defined].some((name) => name.startsWith(`${root}-`))));
});

test('no screen hard-codes a colour: only theme tokens, white text on filled controls, and the always-dark sidebar', () => {
  const offenders: string[] = [];
  const palette = /\b(?:bg|text|border|ring|divide|fill|stroke|from|to|via|placeholder|outline)-(?:gray|slate|zinc|neutral|stone|red|green|blue|yellow|amber|orange|emerald|teal|cyan|sky|indigo|violet|purple|pink|rose|lime|black)(?:-\d{2,3})?\b/g;
  // The three saturated KPI icon tiles hold a white icon and keep the same colour in both themes.
  const kpiTiles = new Set(['bg-purple-500', 'bg-teal-600', 'bg-orange-500']);
  for (const file of files) {
    for (const match of file.text.matchAll(palette)) if (!kpiTiles.has(match[0])) offenders.push(`${file.path}: ${match[0]}`);
    for (const match of file.text.matchAll(/(?<![\w-])(?:bg|border)-white(?!\/)\b/g)) offenders.push(`${file.path}: ${match[0]}`);
    for (const match of file.text.matchAll(/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/g)) offenders.push(`${file.path}: ${match[0]}`);
    for (const match of file.text.matchAll(/style=\{\{[^}]*(?:color|background|fill|stroke)/g)) offenders.push(`${file.path}: inline ${match[0].slice(0, 40)}`);
  }
  assert.deepEqual([...new Set(offenders)], []);
});

test('white text is only ever put on a filled colour, never on a surface that turns light in Light Mode', () => {
  const offenders: string[] = [];
  for (const file of files) {
    for (const line of file.text.split('\n')) {
      if (!/\btext-white\b/.test(line)) continue;
      const onSurface = /\b(?:bg-surface|bg-surface-alt|bg-surface-muted|bg-primary-soft|bg-success-soft|bg-danger-soft|bg-warning-soft|bg-secondary-soft)\b/.test(line.replace(/\?[^:]*:/g, ''));
      if (onSurface && !/\?|:/.test(line)) offenders.push(`${file.path}: ${line.trim().slice(0, 120)}`);
    }
  }
  assert.deepEqual(offenders, []);
});

// ------------------------------------------------------------------ Dark sidebar group labels

function luminance([r, g, b]: number[]): number {
  const [lr, lg, lb] = [r, g, b].map((v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
}

test('the Dark sidebar group labels reach 4.5:1 against the Dark sidebar', () => {
  const css = readFileSync(new URL('../index.css', import.meta.url), 'utf8');
  const sidebar = readFileSync(new URL('../layouts/Sidebar.tsx', import.meta.url), 'utf8');
  assert.match(sidebar, /sidebar-group-label/);
  const rule = css.match(/:root\[data-theme='dark'\] \.sidebar-group-label \{\s*color: rgb\(255 255 255 \/ ([0-9.]+)\);/);
  assert.ok(rule, 'a Dark-only colour rule for .sidebar-group-label exists');
  const alpha = Number(rule[1]);
  const darkBlock = css.slice(css.indexOf(":root[data-theme='dark'] {"));
  const bg = darkBlock.match(/--color-sidebar: (\d+) (\d+) (\d+);/)!.slice(1).map(Number);
  const fg = bg.map((channel) => 255 * alpha + channel * (1 - alpha));
  const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
  assert.ok((hi + 0.05) / (lo + 0.05) >= 4.5);
});
