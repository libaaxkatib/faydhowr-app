import { createLanguageController, type LanguageController, type LanguageStorageLike } from '@/i18n/language';

let controller: LanguageController | null = null;

/** Reading `window.localStorage` itself can throw (blocked site data), so even the lookup is guarded. */
function browserStorage(): LanguageStorageLike | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

/** The one controller for this page; a module-level singleton so React StrictMode cannot create a second one. */
export function getBrowserLanguageController(): LanguageController {
  if (controller === null) controller = createLanguageController({ storage: browserStorage() });

  return controller;
}
