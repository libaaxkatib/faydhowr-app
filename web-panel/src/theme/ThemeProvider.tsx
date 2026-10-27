import { useEffect, useMemo, useSyncExternalStore, type ReactNode } from 'react';

import { getBrowserThemeController } from '@/theme/browserThemeController';
import { THEME_STORAGE_KEY } from '@/theme/theme';
import { ThemeContext, type ThemeContextValue } from '@/theme/themeContext';

/**
 * Makes the chosen theme available to the panel. The page itself is themed by the `data-theme` attribute that the controller (and, before React
 * starts, the small script in index.html) writes on <html>; this provider only lets components read and change the choice, and keeps several
 * open tabs in step when the choice is changed in one of them.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const controller = getBrowserThemeController();
  const state = useSyncExternalStore(controller.subscribe, controller.getState);

  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      // key === null: the whole storage was cleared in another tab.
      if (event.key === THEME_STORAGE_KEY || event.key === null) controller.syncFromStorage();
    };
    window.addEventListener('storage', onStorage);

    return () => window.removeEventListener('storage', onStorage);
  }, [controller]);

  const value = useMemo<ThemeContextValue>(() => ({ mode: state.mode, resolved: state.resolved, setMode: controller.setMode }), [state, controller]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
