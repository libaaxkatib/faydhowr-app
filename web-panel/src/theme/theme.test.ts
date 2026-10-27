import test from 'node:test';
import assert from 'node:assert/strict';

import {
  DEFAULT_THEME_MODE,
  THEME_ATTRIBUTE,
  THEME_MODES,
  THEME_OPTIONS,
  THEME_STORAGE_KEY,
  createThemeController,
  parseThemeMode,
  readStoredThemeMode,
  resolveTheme,
  systemPrefersDark,
  watchSystemTheme,
  writeStoredThemeMode,
  type MatchMediaLike,
  type StorageLike,
  type ThemeMode,
} from './theme.ts';

/** In-memory stand-in for window.localStorage. */
function memoryStorage(initial: Record<string, string> = {}): StorageLike & { data: Record<string, string> } {
  const data = { ...initial };

  return {
    data,
    getItem: (key) => (key in data ? data[key] : null),
    setItem: (key, value) => {
      data[key] = value;
    },
  };
}

/** A storage that throws on every access, like blocked site data or a full quota. */
const brokenStorage: StorageLike = {
  getItem: () => {
    throw new Error('SecurityError');
  },
  setItem: () => {
    throw new Error('QuotaExceededError');
  },
};

/** A fake window.matchMedia whose "dark" answer the test can flip, firing change listeners like the operating system does. */
function fakeSystem(initialDark: boolean, style: 'modern' | 'legacy' = 'modern') {
  let dark = initialDark;
  const listeners = new Set<(event: { matches: boolean }) => void>();
  const matchMedia: MatchMediaLike = (query) => {
    assert.equal(query, '(prefers-color-scheme: dark)');
    const list = {
      get matches() {
        return dark;
      },
    } as ReturnType<MatchMediaLike>;
    if (style === 'modern') {
      list.addEventListener = (_type, listener) => void listeners.add(listener);
      list.removeEventListener = (_type, listener) => void listeners.delete(listener);
    } else {
      list.addListener = (listener) => void listeners.add(listener);
      list.removeListener = (listener) => void listeners.delete(listener);
    }

    return list;
  };

  return {
    matchMedia,
    listenerCount: () => listeners.size,
    set(next: boolean) {
      dark = next;
      listeners.forEach((listener) => listener({ matches: next }));
    },
  };
}

function fakeRoot() {
  const attributes: Record<string, string> = {};

  return { attributes, setAttribute: (name: string, value: string) => void (attributes[name] = value) };
}

// ------------------------------------------------------------------ the choices and their defaults

test('the default is Light, and anything that is not one of the three known values means Light', () => {
  assert.equal(DEFAULT_THEME_MODE, 'light');
  assert.deepEqual([...THEME_MODES], ['light', 'dark', 'system']);
  assert.deepEqual(THEME_OPTIONS.map((option) => option.value), ['light', 'dark', 'system']);
  for (const mode of THEME_MODES) assert.equal(parseThemeMode(mode), mode);
  for (const bad of [null, undefined, '', 'Dark', 'DARK', ' dark', 'auto', 'sepia', 0, 1, true, {}, [], ['dark']]) assert.equal(parseThemeMode(bad), 'light', String(bad));
});

test('System resolves to the operating system, Light and Dark ignore it', () => {
  assert.equal(resolveTheme('system', true), 'dark');
  assert.equal(resolveTheme('system', false), 'light');
  assert.equal(resolveTheme('light', true), 'light');
  assert.equal(resolveTheme('dark', false), 'dark');
});

test('the storage key is its own, so the language preference of the next phase can never overwrite it', () => {
  assert.equal(THEME_STORAGE_KEY, 'fayadhowr.ui.theme');
  assert.notEqual(THEME_STORAGE_KEY, 'fayadhowr.ui.language');
});

// ------------------------------------------------------------------ storage that may be missing, blocked or full

test('a stored choice is read back, and a missing, invalid or unreadable one gives Light', () => {
  assert.equal(readStoredThemeMode(memoryStorage({ [THEME_STORAGE_KEY]: 'dark' })), 'dark');
  assert.equal(readStoredThemeMode(memoryStorage({ [THEME_STORAGE_KEY]: 'system' })), 'system');
  assert.equal(readStoredThemeMode(memoryStorage()), 'light');
  assert.equal(readStoredThemeMode(memoryStorage({ [THEME_STORAGE_KEY]: 'neon' })), 'light');
  assert.equal(readStoredThemeMode(brokenStorage), 'light');
  assert.equal(readStoredThemeMode(null), 'light');
  assert.equal(readStoredThemeMode(undefined), 'light');
});

test('saving reports whether it worked and never throws', () => {
  const storage = memoryStorage();
  assert.equal(writeStoredThemeMode(storage, 'dark'), true);
  assert.equal(storage.data[THEME_STORAGE_KEY], 'dark');
  assert.equal(writeStoredThemeMode(brokenStorage, 'dark'), false);
  assert.equal(writeStoredThemeMode(null, 'dark'), false);
});

// ------------------------------------------------------------------ the operating system's preference

test('the operating-system preference is read safely', () => {
  assert.equal(systemPrefersDark(fakeSystem(true).matchMedia), true);
  assert.equal(systemPrefersDark(fakeSystem(false).matchMedia), false);
  assert.equal(systemPrefersDark(null), false);
  assert.equal(systemPrefersDark(undefined), false);
  assert.equal(
    systemPrefersDark(() => {
      throw new Error('matchMedia unavailable');
    }),
    false,
  );
});

test('changes of the operating-system preference are delivered, with modern and old Safari APIs, and stop after unsubscribing', () => {
  for (const style of ['modern', 'legacy'] as const) {
    const system = fakeSystem(false, style);
    const seen: boolean[] = [];
    const stop = watchSystemTheme(system.matchMedia, (dark) => seen.push(dark));
    assert.equal(system.listenerCount(), 1, style);
    system.set(true);
    system.set(false);
    assert.deepEqual(seen, [true, false], style);
    stop();
    assert.equal(system.listenerCount(), 0, style);
    system.set(true);
    assert.deepEqual(seen, [true, false], `${style}: nothing after unsubscribing`);
  }
  // No matchMedia at all, or one that throws: a harmless no-op.
  assert.doesNotThrow(() => watchSystemTheme(null, () => {})());
  assert.doesNotThrow(() =>
    watchSystemTheme(() => {
      throw new Error('boom');
    }, () => {})(),
  );
});

// ------------------------------------------------------------------ the controller

test('a first visit is Light: the page is marked light and nothing is written to storage', () => {
  const storage = memoryStorage();
  const root = fakeRoot();
  const controller = createThemeController({ storage, matchMedia: fakeSystem(true).matchMedia, root });

  assert.deepEqual(controller.getState(), { mode: 'light', resolved: 'light' }, 'a dark operating system does not change the default');
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'light');
  assert.deepEqual(storage.data, {});
});

test('switching between Light, Dark and System updates the page and is saved', () => {
  const storage = memoryStorage();
  const root = fakeRoot();
  const controller = createThemeController({ storage, matchMedia: fakeSystem(true).matchMedia, root });
  let notified = 0;
  controller.subscribe(() => notified++);

  controller.setMode('dark');
  assert.deepEqual(controller.getState(), { mode: 'dark', resolved: 'dark' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'dark');
  assert.equal(storage.data[THEME_STORAGE_KEY], 'dark');

  controller.setMode('system');
  assert.deepEqual(controller.getState(), { mode: 'system', resolved: 'dark' }, 'the operating system is dark');
  assert.equal(storage.data[THEME_STORAGE_KEY], 'system');

  controller.setMode('light');
  assert.deepEqual(controller.getState(), { mode: 'light', resolved: 'light' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'light');
  assert.equal(notified, 3);
});

test('the choice survives a refresh: a new controller on the same storage starts with it', () => {
  const storage = memoryStorage();
  createThemeController({ storage, matchMedia: fakeSystem(false).matchMedia, root: fakeRoot() }).setMode('dark');

  const root = fakeRoot();
  const afterRefresh = createThemeController({ storage, matchMedia: fakeSystem(false).matchMedia, root });
  assert.deepEqual(afterRefresh.getState(), { mode: 'dark', resolved: 'dark' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'dark', 'applied immediately, before anything renders');
});

test('System follows the operating system live, Light and Dark do not', () => {
  const system = fakeSystem(false);
  const root = fakeRoot();
  const controller = createThemeController({ storage: memoryStorage({ [THEME_STORAGE_KEY]: 'system' }), matchMedia: system.matchMedia, root });
  let notified = 0;
  controller.subscribe(() => notified++);
  assert.equal(controller.getState().resolved, 'light');

  system.set(true);
  assert.deepEqual(controller.getState(), { mode: 'system', resolved: 'dark' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'dark');
  system.set(false);
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'light');
  assert.equal(notified, 2);

  controller.setMode('light');
  notified = 0;
  system.set(true);
  assert.equal(controller.getState().resolved, 'light', 'Light ignores the operating system');
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'light');
  assert.equal(notified, 0);

  controller.setMode('dark');
  system.set(false);
  assert.equal(controller.getState().resolved, 'dark', 'Dark ignores the operating system');
});

test('unreadable or unwritable storage never breaks the switch: the choice applies until the page is closed', () => {
  const root = fakeRoot();
  const controller = createThemeController({ storage: brokenStorage, matchMedia: fakeSystem(false).matchMedia, root });
  assert.deepEqual(controller.getState(), { mode: 'light', resolved: 'light' });

  assert.doesNotThrow(() => controller.setMode('dark'));
  assert.deepEqual(controller.getState(), { mode: 'dark', resolved: 'dark' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'dark');

  // No storage object at all, and no matchMedia, no root: still a working controller.
  const bare = createThemeController({});
  assert.doesNotThrow(() => bare.setMode('system'));
  assert.deepEqual(bare.getState(), { mode: 'system', resolved: 'light' });
});

test('an invalid value passed to setMode becomes Light and an unchanged choice does not notify', () => {
  const controller = createThemeController({ storage: memoryStorage(), matchMedia: fakeSystem(false).matchMedia, root: fakeRoot() });
  let notified = 0;
  controller.subscribe(() => notified++);

  controller.setMode('neon' as unknown as ThemeMode);
  assert.equal(controller.getState().mode, 'light');
  assert.equal(notified, 0, 'it was already Light');

  const before = controller.getState();
  controller.setMode('light');
  assert.equal(controller.getState(), before, 'the same state object: React does not re-render');
});

test('a change made in another tab is picked up without being written back', () => {
  const storage = memoryStorage();
  const root = fakeRoot();
  const controller = createThemeController({ storage, matchMedia: fakeSystem(false).matchMedia, root });
  let notified = 0;
  controller.subscribe(() => notified++);

  storage.data[THEME_STORAGE_KEY] = 'dark'; // what the other tab saved
  controller.syncFromStorage();
  assert.deepEqual(controller.getState(), { mode: 'dark', resolved: 'dark' });
  assert.equal(root.attributes[THEME_ATTRIBUTE], 'dark');
  assert.equal(notified, 1);

  delete storage.data[THEME_STORAGE_KEY]; // the other tab cleared the storage
  controller.syncFromStorage();
  assert.equal(controller.getState().mode, 'light');
  assert.deepEqual(storage.data, {}, 'syncing never writes');
});

test('unsubscribing and disposing stop all notifications and the operating-system listener', () => {
  const system = fakeSystem(false);
  const controller = createThemeController({ storage: memoryStorage({ [THEME_STORAGE_KEY]: 'system' }), matchMedia: system.matchMedia, root: fakeRoot() });
  let notified = 0;
  const stop = controller.subscribe(() => notified++);

  stop();
  controller.setMode('dark');
  assert.equal(notified, 0);

  controller.setMode('system');
  assert.equal(system.listenerCount(), 1);
  controller.dispose();
  assert.equal(system.listenerCount(), 0);
});
