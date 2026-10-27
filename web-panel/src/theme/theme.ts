// Pure theme logic for the Web Panel (no React, no DOM globals, no `@/` imports) so Node's test runner can exercise every branch.
//
// Three user choices are stored under their own key: "light" (the default), "dark", and "system" (follow the operating system and react when it
// changes). The choice is resolved to the one of two themes that is actually painted and written to <html data-theme="...">; every colour in the
// panel is a CSS variable that switches on that attribute (see src/index.css and tailwind.config.ts). The language preference (a later phase)
// will use its own key and attribute and can never overwrite this one.

export type ThemeMode = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'fayadhowr.ui.theme';
export const THEME_ATTRIBUTE = 'data-theme';
export const DEFAULT_THEME_MODE: ThemeMode = 'light';
export const DARK_COLOR_SCHEME_QUERY = '(prefers-color-scheme: dark)';

export const THEME_MODES: readonly ThemeMode[] = ['light', 'dark', 'system'];

/** The choices of the selector, in display order. Their visible and accessible names are in the translation catalogue (`option.theme.*`). */
export const THEME_OPTIONS: readonly { value: ThemeMode }[] = [{ value: 'light' }, { value: 'dark' }, { value: 'system' }];

/** Anything that is not exactly one of the three known values (missing, corrupted, an old or future value) means the default: Light. */
export function parseThemeMode(value: unknown): ThemeMode {
  return value === 'light' || value === 'dark' || value === 'system' ? value : DEFAULT_THEME_MODE;
}

export function resolveTheme(mode: ThemeMode, systemPrefersDark: boolean): ResolvedTheme {
  if (mode === 'system') return systemPrefersDark ? 'dark' : 'light';

  return mode;
}

// ------------------------------------------------------------------ storage (every access may throw: private mode, blocked storage, quota)

export interface StorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
}

export function readStoredThemeMode(storage: StorageLike | null | undefined): ThemeMode {
  try {
    return parseThemeMode(storage?.getItem(THEME_STORAGE_KEY) ?? null);
  } catch {
    return DEFAULT_THEME_MODE;
  }
}

/** True when the choice was saved. A failure is not an error for the user: the choice still applies until the page is closed. */
export function writeStoredThemeMode(storage: StorageLike | null | undefined, mode: ThemeMode): boolean {
  try {
    if (!storage) return false;
    storage.setItem(THEME_STORAGE_KEY, mode);

    return true;
  } catch {
    return false;
  }
}

// ------------------------------------------------------------------ the operating system's preference

export interface MediaQueryListLike {
  matches: boolean;
  addEventListener?: (type: 'change', listener: (event: { matches: boolean }) => void) => void;
  removeEventListener?: (type: 'change', listener: (event: { matches: boolean }) => void) => void;
  /** Older Safari only. */
  addListener?: (listener: (event: { matches: boolean }) => void) => void;
  removeListener?: (listener: (event: { matches: boolean }) => void) => void;
}

export type MatchMediaLike = (query: string) => MediaQueryListLike;

export function systemPrefersDark(matchMedia: MatchMediaLike | null | undefined): boolean {
  try {
    return matchMedia?.(DARK_COLOR_SCHEME_QUERY).matches === true;
  } catch {
    return false;
  }
}

/** Calls `onChange` whenever the operating system switches between light and dark. Returns the function that stops listening. */
export function watchSystemTheme(matchMedia: MatchMediaLike | null | undefined, onChange: (prefersDark: boolean) => void): () => void {
  let query: MediaQueryListLike | undefined;
  try {
    query = matchMedia?.(DARK_COLOR_SCHEME_QUERY);
  } catch {
    return () => {};
  }
  if (!query) return () => {};

  const listener = (event: { matches: boolean }) => onChange(event.matches === true);
  if (typeof query.addEventListener === 'function') {
    query.addEventListener('change', listener);

    return () => query?.removeEventListener?.('change', listener);
  }
  if (typeof query.addListener === 'function') {
    query.addListener(listener);

    return () => query?.removeListener?.(listener);
  }

  return () => {};
}

// ------------------------------------------------------------------ applying a theme to the page

export interface ThemeRootLike {
  setAttribute(name: string, value: string): void;
}

export function applyResolvedTheme(root: ThemeRootLike | null | undefined, theme: ResolvedTheme): void {
  root?.setAttribute(THEME_ATTRIBUTE, theme);
}

// ------------------------------------------------------------------ the controller (one per page; React reads it through useSyncExternalStore)

export interface ThemeState {
  mode: ThemeMode;
  resolved: ResolvedTheme;
}

export interface ThemeEnvironment {
  storage?: StorageLike | null;
  matchMedia?: MatchMediaLike | null;
  root?: ThemeRootLike | null;
}

export interface ThemeController {
  getState(): ThemeState;
  setMode(mode: ThemeMode): void;
  /** Re-reads the stored choice (another tab changed it). Does not write anything back. */
  syncFromStorage(): void;
  subscribe(listener: () => void): () => void;
  dispose(): void;
}

export function createThemeController(environment: ThemeEnvironment): ThemeController {
  const { storage, matchMedia, root } = environment;
  const listeners = new Set<() => void>();
  let prefersDark = systemPrefersDark(matchMedia);
  let state: ThemeState = buildState(readStoredThemeMode(storage));
  applyResolvedTheme(root, state.resolved);

  function buildState(mode: ThemeMode): ThemeState {
    return { mode, resolved: resolveTheme(mode, prefersDark) };
  }

  function update(next: ThemeState): void {
    if (next.mode === state.mode && next.resolved === state.resolved) return;
    state = next;
    applyResolvedTheme(root, state.resolved);
    listeners.forEach((listener) => listener());
  }

  const stopWatching = watchSystemTheme(matchMedia, (dark) => {
    prefersDark = dark;
    update(buildState(state.mode));
  });

  return {
    getState: () => state,
    setMode(mode) {
      const next = parseThemeMode(mode);
      writeStoredThemeMode(storage, next);
      update(buildState(next));
    },
    syncFromStorage() {
      update(buildState(readStoredThemeMode(storage)));
    },
    subscribe(listener) {
      listeners.add(listener);

      return () => {
        listeners.delete(listener);
      };
    },
    dispose() {
      stopWatching();
      listeners.clear();
    },
  };
}
