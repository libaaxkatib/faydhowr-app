import { createContext } from 'react';

import type { Language } from '@/i18n/language';
import type { PluralBase, Translate } from '@/i18n/messages';
import type { Params } from '@/i18n/translate';

export interface I18nContextValue {
  /** The language the whole panel is shown in: English (the default) or Somali. */
  language: Language;
  setLanguage: (language: Language) => void;
  /** Translates into the chosen language. English text is the fallback for anything not translated. */
  t: Translate;
  /** Translates a message with `.one` / `.other` forms for a count. `{n}` is filled in automatically. */
  tCount: (base: PluralBase, count: number, params?: Params) => string;
  /**
   * The Somali label of a stored value (a status, stage, leave type...) under the key `<prefix>.<value>`; undefined in English and when there is none,
   * so `label={tValue('status', row.status)}` keeps a badge showing exactly what it showed before in English.
   */
  tValue: (prefix: string, value: string) => string | undefined;
}

export const I18nContext = createContext<I18nContextValue | null>(null);
