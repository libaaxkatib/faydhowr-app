import { createThemeController, type StorageLike, type ThemeController } from '@/theme/theme';

let controller: ThemeController | null = null;

/** Reading `window.localStorage` itself can throw (blocked site data), so even the lookup is guarded. */
function browserStorage(): StorageLike | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

/**
 * The one controller for this page. A module-level singleton, not component state, so React StrictMode (which runs initialisers twice in
 * development) cannot leave a second operating-system listener behind.
 */
export function getBrowserThemeController(): ThemeController {
  if (controller === null) {
    controller = createThemeController({
      storage: browserStorage(),
      matchMedia: typeof window.matchMedia === 'function' ? window.matchMedia.bind(window) : null,
      root: document.documentElement,
    });
  }

  return controller;
}
