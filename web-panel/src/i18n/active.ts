// The language the page is showing right now, for code that is not a React component (utility functions, mutation callbacks, toast messages).
// Components should use `useI18n()` instead, which also re-renders them when the language changes.
//
// It starts as English, so every unit test and every screen outside HR gets the English text exactly as before; the provider moves it to Somali
// only while an HR screen is showing and Somali is the chosen language.

import { DEFAULT_LANGUAGE, type Language } from './language.ts';
import { translateCount, translateKey, translateStoredValue, type MessageKey, type PluralBase } from './messages/index.ts';
import type { Params } from './translate.ts';

let active: Language = DEFAULT_LANGUAGE;

export function setActiveLanguage(language: Language): void {
  active = language;
}

export function getActiveLanguage(): Language {
  return active;
}

export function tNow(key: MessageKey, params?: Params): string {
  return translateKey(active, key, params);
}

/** The Somali label of a stored value (see translateValue), or undefined in English / when there is none. */
export function tValueNow(prefix: string, value: string): string | undefined {
  return translateStoredValue(active, prefix, value);
}

export function tNowCount(base: PluralBase, count: number, params?: Params): string {
  return translateCount(active, base, count, params);
}
