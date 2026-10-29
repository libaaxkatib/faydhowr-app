import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

/**
 * The colour tokens are the contract of the theme system: src/index.css holds the values of both themes, tailwind.config.ts maps the semantic
 * names onto them. These tests read the real files, so a token cannot be added to one theme only, a colour cannot be hard-coded back into the
 * config, the approved Light palette cannot drift, and the Dark palette cannot lose readable contrast.
 */

const css = readFileSync(new URL('../index.css', import.meta.url), 'utf8');
const indexHtml = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');

// ------------------------------------------------------------------ reading the CSS

function block(selectorPattern: RegExp): string {
  const match = css.match(selectorPattern);
  assert.ok(match, `block not found: ${selectorPattern}`);

  return match[1];
}

/** Variables of one block as { 'primary': '#0E339B', ... } (only `--color-*`, channels converted back to hex). */
function colourVariables(body: string): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [, name, r, g, b] of body.matchAll(/--color-([a-z0-9-]+):\s*(\d{1,3})\s+(\d{1,3})\s+(\d{1,3});/g)) {
    result[name] = '#' + [r, g, b].map((channel) => Number(channel).toString(16).padStart(2, '0')).join('').toUpperCase();
  }

  return result;
}

const light = colourVariables(block(/:root\s*\{([\s\S]*?)\n {2}\}/));
const dark = colourVariables(block(/:root\[data-theme='dark'\]\s*\{([\s\S]*?)\n {2}\}/));

// ------------------------------------------------------------------ contrast (WCAG 2.x)

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((index) => {
    const channel = parseInt(hex.slice(index, index + 2), 16) / 255;

    return channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
  });

  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
  const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);

  return (high + 0.05) / (low + 0.05);
}

// ------------------------------------------------------------------ 1. the Light theme is exactly the approved palette

/** The palette as it was written in tailwind.config.ts before the themes existed (Issue #11 brand colours). Light Mode must not change. */
const APPROVED_LIGHT: Record<string, string> = {
  primary: '#0E339B',
  'primary-text': '#0E339B',
  'primary-ink': '#0A2678',
  'primary-soft': '#E7ECF9',
  secondary: '#00B9B3',
  'secondary-text': '#00B9B3',
  'secondary-soft': '#E0F7F5',
  surface: '#FFFFFF',
  'surface-muted': '#F7F9FC',
  'surface-alt': '#EEF2F9',
  'ink-text': '#1F2937',
  'ink-overlay': '#1F2937',
  'ink-muted-text': '#6B7280',
  'ink-muted-fill': '#6B7280',
  'ink-faint-text': '#94A3B8',
  'ink-faint-fill': '#94A3B8',
  border: '#E5E7EB',
  success: '#178A4C',
  'success-text': '#178A4C',
  'success-soft': '#E1F6EA',
  warning: '#B5730B',
  'warning-text': '#B5730B',
  'warning-soft': '#FCF0DA',
  danger: '#C33232',
  'danger-text': '#C33232',
  'danger-soft': '#FBE6E6',
  sidebar: '#0A1B3F',
  'sidebar-hover': '#13285C',
};

test('Light Mode is exactly the approved brand palette, value for value', () => {
  for (const [name, hex] of Object.entries(APPROVED_LIGHT)) assert.equal(light[name], hex, `--color-${name}`);
  assert.match(css, /--shadow-card:\s*0 1px 2px rgba\(20, 30, 60, 0\.04\), 0 8px 24px -12px rgba\(20, 30, 60, 0\.12\);/, 'the Light card shadow is unchanged');
});

// ------------------------------------------------------------------ 2. both themes define the same tokens

test('every colour token exists in both themes, and the Dark theme invents none of its own', () => {
  const lightNames = Object.keys(light).sort();
  const darkNames = Object.keys(dark).sort();
  assert.ok(lightNames.length >= Object.keys(APPROVED_LIGHT).length);
  assert.deepEqual(darkNames, lightNames);
  assert.match(css, /:root\[data-theme='dark'\]\s*\{[\s\S]*?--shadow-card:/, 'Dark has its own card shadow');
  assert.match(css, /:root\s*\{\s*color-scheme: light;/, 'native controls are light in Light Mode');
  assert.match(css, /:root\[data-theme='dark'\]\s*\{\s*color-scheme: dark;/, 'native controls (date pickers, selects, scrollbars) are dark in Dark Mode');
});

// ------------------------------------------------------------------ 3. Tailwind maps names onto the variables, never onto literal colours

interface TailwindConfigShape {
  theme: { extend: { colors: Record<string, Record<string, string>>; textColor: Record<string, Record<string, string>>; borderColor: Record<string, string> } };
}
const configUrl = pathToFileURL(new URL('../../tailwind.config.ts', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')).href;
const { default: tailwind } = (await import(configUrl)) as { default: TailwindConfigShape };

function leafValues(group: Record<string, string | Record<string, string>>): string[] {
  return Object.values(group).flatMap((value) => (typeof value === 'string' ? [value] : Object.values(value)));
}

test('every colour in the Tailwind config is a theme variable that both themes define (opacity utilities keep working)', () => {
  const extend = tailwind.theme.extend;
  const values = [...leafValues(extend.colors), ...leafValues(extend.textColor), ...leafValues(extend.borderColor)];
  assert.equal(values.length, 36, 'the config maps the full semantic palette: 27 fills (20 interface colours + 7 report-chart colours), 8 text colours, 1 default border');
  for (const value of values) {
    const match = value.match(/^rgb\(var\(--color-([a-z0-9-]+)\) \/ <alpha-value>\)$/);
    assert.ok(match, `not a theme variable (hard-coded colour?): ${value}`);
    assert.ok(match[1] in light, `--color-${match[1]} is missing from Light`);
    assert.ok(match[1] in dark, `--color-${match[1]} is missing from Dark`);
  }
});

test('text and fills are separate where one name plays both roles, and the modal scrim can never turn light', () => {
  const { colors, textColor } = tailwind.theme.extend;
  // text-primary is the link colour, bg-primary the button fill.
  assert.equal(textColor.primary.DEFAULT, 'rgb(var(--color-primary-text) / <alpha-value>)');
  assert.equal(colors.primary.DEFAULT, 'rgb(var(--color-primary) / <alpha-value>)');
  assert.notEqual(dark['primary-text'], dark.primary);
  // bg-ink/40 (the dialog scrim) must not follow the light text colour of Dark Mode.
  assert.equal(colors.ink.DEFAULT, 'rgb(var(--color-ink-overlay) / <alpha-value>)');
  assert.equal(dark['ink-overlay'], '#000000');
  assert.notEqual(dark['ink-overlay'], dark['ink-text']);
  // the grey chart bars are fills, not text colours.
  assert.equal(colors.ink.faint, 'rgb(var(--color-ink-faint-fill) / <alpha-value>)');
  assert.equal(tailwind.theme.extend.borderColor.DEFAULT, 'rgb(var(--color-border) / <alpha-value>)', 'a bare `border` follows the theme');
});

test('the early-paint style in index.html uses the Dark page colour', () => {
  const match = indexHtml.match(/html\[data-theme='dark'\]\s*\{\s*background-color:\s*(#[0-9a-fA-F]{6})/);
  assert.ok(match, 'dark fallback background found in index.html');
  assert.equal(match[1].toUpperCase(), dark['surface-muted']);
});

// ------------------------------------------------------------------ 4. contrast

interface Pair {
  name: string;
  foreground: string;
  background: string;
  /** WCAG minimum: 4.5 for text, 3 for large text, icons and component boundaries. */
  minimum: number;
  /** The foreground is white at this opacity (the panel writes some text as text-white/80 on a filled background). */
  alpha?: number;
}

const WHITE = 'white';
const SURFACES = ['surface', 'surface-muted', 'surface-alt'];
const PAIRS: Pair[] = [
  ...SURFACES.flatMap((s) =>
    (['ink-text', 'ink-muted-text', 'ink-faint-text', 'primary-text'] as const).map((fg) => ({ name: `${fg} on ${s}`, foreground: fg, background: s, minimum: 4.5 })),
  ),
  { name: 'primary-text on primary-soft', foreground: 'primary-text', background: 'primary-soft', minimum: 4.5 },
  { name: 'secondary-text on surface', foreground: 'secondary-text', background: 'surface', minimum: 4.5 },
  { name: 'secondary-text on secondary-soft', foreground: 'secondary-text', background: 'secondary-soft', minimum: 4.5 },
  { name: 'success-text on surface', foreground: 'success-text', background: 'surface', minimum: 4.5 },
  { name: 'success-text on success-soft', foreground: 'success-text', background: 'success-soft', minimum: 4.5 },
  { name: 'warning-text on surface', foreground: 'warning-text', background: 'surface', minimum: 4.5 },
  { name: 'warning-text on warning-soft', foreground: 'warning-text', background: 'warning-soft', minimum: 4.5 },
  { name: 'danger-text on surface', foreground: 'danger-text', background: 'surface', minimum: 4.5 },
  { name: 'danger-text on danger-soft', foreground: 'danger-text', background: 'danger-soft', minimum: 4.5 },
  { name: 'white on primary (buttons, avatar)', foreground: WHITE, background: 'primary', minimum: 4.5 },
  { name: 'white at 80% on primary (active filter chips)', foreground: WHITE, background: 'primary', minimum: 4.5, alpha: 0.8 },
  { name: 'white on primary-ink', foreground: WHITE, background: 'primary-ink', minimum: 4.5 },
  { name: 'white on danger (buttons)', foreground: WHITE, background: 'danger', minimum: 4.5 },
  { name: 'white on success', foreground: WHITE, background: 'success', minimum: 4.5 },
  { name: 'white on warning (dashboard icons)', foreground: WHITE, background: 'warning', minimum: 3 },
  { name: 'white on secondary (dashboard icons)', foreground: WHITE, background: 'secondary', minimum: 3 },
  { name: 'white on sidebar (menu text)', foreground: WHITE, background: 'sidebar', minimum: 4.5 },
  { name: 'focus ring on surface', foreground: 'focus', background: 'surface', minimum: 3 },
  { name: 'focus ring on page', foreground: 'focus', background: 'surface-muted', minimum: 3 },
  { name: 'primary button against the page', foreground: 'primary', background: 'surface-muted', minimum: 3 },
];

/** The foreground of a pair as an opaque colour: white blended over its background when the pair has an opacity. */
function foregroundOf(theme: Record<string, string>, pair: Pair): string {
  const foreground = colourOf(theme, pair.foreground);
  if (pair.alpha === undefined) return foreground;
  const background = colourOf(theme, pair.background);
  const blended = [1, 3, 5].map((index) => Math.round(parseInt(foreground.slice(index, index + 2), 16) * pair.alpha! + parseInt(background.slice(index, index + 2), 16) * (1 - pair.alpha!)));

  return '#' + blended.map((channel) => channel.toString(16).padStart(2, '0')).join('');
}

function colourOf(theme: Record<string, string>, name: string): string {
  if (name === WHITE) return '#FFFFFF';
  assert.ok(name in theme, `unknown token ${name}`);

  return theme[name];
}

test('Dark Mode: every text, button and icon pairing meets its WCAG contrast minimum', () => {
  for (const pair of PAIRS) {
    const ratio = contrast(foregroundOf(dark, pair), colourOf(dark, pair.background));
    assert.ok(ratio >= pair.minimum, `Dark: ${pair.name} is ${ratio.toFixed(2)}:1, needs ${pair.minimum}:1`);
  }
});

/**
 * Light Mode is the approved brand palette and is deliberately unchanged, but some of its existing pairings are below WCAG (for example the
 * faint grey text, and the teal accent used as text). They are recorded here with their exact current ratio so that (a) nothing NEW is allowed to
 * fall short in Light, and (b) they stay visible as a known, owner-decidable list rather than being silently ignored.
 */
const KNOWN_LIGHT_SHORTFALLS: Record<string, number> = {
  'ink-muted-text on surface-alt': 4.31,
  'ink-faint-text on surface': 2.56,
  'ink-faint-text on surface-muted': 2.43,
  'ink-faint-text on surface-alt': 2.28,
  'secondary-text on surface': 2.44,
  'secondary-text on secondary-soft': 2.19,
  'success-text on surface': 4.4,
  'success-text on success-soft': 3.89,
  'warning-text on surface': 3.87,
  'warning-text on warning-soft': 3.43,
  'white on success': 4.4,
  'white on secondary (dashboard icons)': 2.44,
};

test('Light Mode: no pairing falls short of WCAG except the known, unchanged brand-palette shortfalls', () => {
  const seen = new Set<string>();
  for (const pair of PAIRS) {
    const ratio = contrast(foregroundOf(light, pair), colourOf(light, pair.background));
    if (ratio >= pair.minimum) continue;
    const recorded = KNOWN_LIGHT_SHORTFALLS[pair.name];
    assert.ok(recorded !== undefined, `Light: ${pair.name} is ${ratio.toFixed(2)}:1 (needs ${pair.minimum}:1) and is not a known shortfall`);
    assert.ok(Math.abs(ratio - recorded) < 0.01, `Light: ${pair.name} changed from ${recorded}:1 to ${ratio.toFixed(2)}:1`);
    seen.add(pair.name);
  }
  assert.deepEqual([...seen].sort(), Object.keys(KNOWN_LIGHT_SHORTFALLS).sort(), 'every recorded shortfall is still real (remove entries that get fixed)');
});

test('Dark Mode is never harder to read than Light Mode for the same text pairing', () => {
  for (const pair of PAIRS.filter((p) => p.foreground !== 'focus' && p.foreground !== WHITE && !p.name.includes('against'))) {
    const darkRatio = contrast(foregroundOf(dark, pair), colourOf(dark, pair.background));
    const lightRatio = contrast(foregroundOf(light, pair), colourOf(light, pair.background));
    if (pair.name.startsWith('primary-text') || pair.name.startsWith('ink-text')) continue; // both are far above the minimum in each theme
    assert.ok(darkRatio >= Math.min(lightRatio, pair.minimum), `${pair.name}: Dark ${darkRatio.toFixed(2)} vs Light ${lightRatio.toFixed(2)}`);
  }
});

test('the sign-in logo is the white wordmark in Dark Mode only: Light Mode and the asset are untouched', () => {
  assert.match(css, /:root\[data-theme='dark'\]\s+\.logo-auto\s*\{\s*filter:\s*brightness\(0\)\s+invert\(1\);/);
  assert.equal([...css.matchAll(/\.logo-auto\s*\{/g)].length, 1, 'only one rule, and it is scoped to Dark');
  // The wordmark that is 1.65:1 in Dark (dark blue on the page colour) is what makes the white treatment necessary.
  assert.ok(contrast('#103090', dark['surface-muted']) < 2, 'the logo blue is too dim on the Dark page');
});
