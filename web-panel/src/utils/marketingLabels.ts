import type { MessageKey } from '../i18n/messages/index.ts';

/**
 * The services a XARUN may need are stored (and sent to the server) as English words, so the stored value never changes; only the label shown to
 * the user is translated, through these keys.
 */
export const NEED_LABEL_KEYS: Readonly<Record<string, MessageKey>> = {
  'General Cleaning': 'option.marketing.need.generalCleaning',
  'Deep Cleaning': 'option.marketing.need.deepCleaning',
  'Pest Control': 'option.marketing.need.pestControl',
  'Tree Cutting': 'option.marketing.need.treeCutting',
  'Other Services': 'option.marketing.need.otherServices',
};

/** The label of a stored need in the language `t` translates to; a value with no entry is shown as stored. */
export function needLabel(need: string, t: (key: MessageKey) => string): string {
  const key = NEED_LABEL_KEYS[need];

  return key === undefined ? need : t(key);
}
