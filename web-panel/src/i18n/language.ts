// Pure language logic for the Web Panel (no React, no DOM globals, no `@/` imports) so Node's test runner can exercise every branch.
//
// The panel is English by default. Somali is an opt-in choice that applies to EVERY screen (sign-in included). The choice is stored in the browser
// under its own key, which is different from the sign-in token's key and from any other stored setting.

export type Language = 'en' | 'so';

export const LANGUAGE_STORAGE_KEY = 'fayadhowr.ui.language';
export const DEFAULT_LANGUAGE: Language = 'en';
export const LANGUAGES: readonly Language[] = ['en', 'so'];

/** The wording of the selector: the short code on the button and the full name for screen readers. Somali is Latin script, left to right. */
export const LANGUAGE_OPTIONS: readonly { value: Language; code: string; name: string }[] = [
  { value: 'en', code: 'EN', name: 'English' },
  { value: 'so', code: 'SO', name: 'Soomaali' },
];

/** Anything that is not exactly 'en' or 'so' (missing, corrupted, an old or future value) means the default: English. */
export function parseLanguage(value: unknown): Language {
  return value === 'en' || value === 'so' ? value : DEFAULT_LANGUAGE;
}

// ------------------------------------------------------------------ storage (every access may throw: private mode, blocked storage, quota)

export interface LanguageStorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
}

export function readStoredLanguage(storage: LanguageStorageLike | null | undefined): Language {
  try {
    return parseLanguage(storage?.getItem(LANGUAGE_STORAGE_KEY) ?? null);
  } catch {
    return DEFAULT_LANGUAGE;
  }
}

/** True when the choice was saved. A failure is not an error for the user: the choice still applies until the page is closed. */
export function writeStoredLanguage(storage: LanguageStorageLike | null | undefined, language: Language): boolean {
  try {
    if (!storage) return false;
    storage.setItem(LANGUAGE_STORAGE_KEY, language);

    return true;
  } catch {
    return false;
  }
}

// ------------------------------------------------------------------ applying a language to the page

export const LANG_ATTRIBUTE = 'lang';

export interface LanguageRootLike {
  setAttribute(name: string, value: string): void;
}

export function applyLanguageAttribute(root: LanguageRootLike | null | undefined, language: Language): void {
  root?.setAttribute(LANG_ATTRIBUTE, language);
}

// ------------------------------------------------------------------ the controller (one per page; React reads it through useSyncExternalStore)

export interface LanguageState {
  language: Language;
}

export interface LanguageEnvironment {
  storage?: LanguageStorageLike | null;
}

export interface LanguageController {
  getState(): LanguageState;
  setLanguage(language: Language): void;
  /** Re-reads the stored choice (another tab changed it). Does not write anything back. */
  syncFromStorage(): void;
  subscribe(listener: () => void): () => void;
  dispose(): void;
}

export function createLanguageController(environment: LanguageEnvironment): LanguageController {
  const { storage } = environment;
  const listeners = new Set<() => void>();
  let state: LanguageState = { language: readStoredLanguage(storage) };

  function update(language: Language): void {
    if (language === state.language) return;
    state = { language };
    listeners.forEach((listener) => listener());
  }

  return {
    getState: () => state,
    setLanguage(language) {
      const next = parseLanguage(language);
      writeStoredLanguage(storage, next);
      update(next);
    },
    syncFromStorage() {
      update(readStoredLanguage(storage));
    },
    subscribe(listener) {
      listeners.add(listener);

      return () => {
        listeners.delete(listener);
      };
    },
    dispose() {
      listeners.clear();
    },
  };
}
