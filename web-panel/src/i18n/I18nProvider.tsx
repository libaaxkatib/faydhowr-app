import { useEffect, useMemo, useSyncExternalStore, type ReactNode } from 'react';

import { setActiveLanguage } from '@/i18n/active';
import { getBrowserLanguageController } from '@/i18n/browserLanguageController';
import { I18nContext, type I18nContextValue } from '@/i18n/i18nContext';
import { applyLanguageAttribute, LANGUAGE_STORAGE_KEY } from '@/i18n/language';
import { translateCount, translateKey, translateStoredValue } from '@/i18n/messages';

/**
 * Makes the chosen language available to the whole panel (every screen, sign-in included). The choice is stored in the browser under its own key.
 *
 * The language is also handed to `tNow` (for code that is not a component) while rendering, before any child renders, and to <html lang>
 * (the small script in index.html sets the same value before the first paint).
 */
export function I18nProvider({ children }: { children: ReactNode }) {
  const controller = getBrowserLanguageController();
  const { language } = useSyncExternalStore(controller.subscribe, controller.getState);
  setActiveLanguage(language);

  useEffect(() => {
    applyLanguageAttribute(document.documentElement, language);
  }, [language]);

  // Keeps several open tabs in step when the choice is changed in one of them.
  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      // key === null: the whole storage was cleared in another tab.
      if (event.key === LANGUAGE_STORAGE_KEY || event.key === null) controller.syncFromStorage();
    };
    window.addEventListener('storage', onStorage);

    return () => window.removeEventListener('storage', onStorage);
  }, [controller]);

  const value = useMemo<I18nContextValue>(
    () => ({
      language,
      setLanguage: controller.setLanguage,
      t: (key, params) => translateKey(language, key, params),
      tCount: (base, count, params) => translateCount(language, base, count, params),
      tValue: (prefix, value) => translateStoredValue(language, prefix, value),
    }),
    [language, controller],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}
