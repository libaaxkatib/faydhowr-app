// Shows the English sentences the API sends (Laravel validation errors, sign-in and permission errors) in the language the user chose. No React, no
// `@/` imports, so Node can run it. Used by ApiClientError, which every API call goes through.
//
// How a sentence is recognised: the English text of every `validation.laravel.*` and `notify.server.*` catalogue entry is the sentence the server
// produces, with `:name` written as `{name}` (see messages/serverErrors.ts). Each is compiled into an anchored pattern; the values inside the sentence
// (field name, number of characters, date, the other field) are captured and carried into the Somali sentence. A sentence that matches nothing, in any
// language, is returned exactly as the server sent it: a wrong translation is worse than an English sentence.
//
// English is never rewritten: the server's own text is what an English user sees today and keeps seeing.

import type { Language } from './language.ts';
import { CATALOGUE, translateCount, translateKey, translateStoredValue, type MessageKey } from './messages/index.ts';

interface Rule {
  readonly key: MessageKey;
  readonly pattern: RegExp;
  readonly names: readonly string[];
  /** How much of the sentence is fixed text. The sentence with the most fixed text is tried first. */
  readonly fixedLength: number;
}

/**
 * Parameters that hold a number: tells "greater than 5." (a number) from "greater than 5 kilobytes." (a file size), and keeps a one-word sentence such as
 * "must be uppercase." from being read as "must be :size.". A value that is not a number (another field) simply matches nothing and stays as the server
 * wrote it. `:value` is numeric only in the numeric size comparisons; in "required when :other is :value" it is any text.
 */
const NUMERIC = new Set(['min', 'max', 'size', 'digits', 'decimal', 'n']);
const NUMBER = '-?\\d+(?:\\.\\d+)?';

const escapeRegex = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function compile(template: string, numericValue: boolean): { pattern: RegExp; names: string[]; fixedLength: number } {
  const names: string[] = [];
  let source = '';
  let last = 0;
  for (const match of template.matchAll(/\{([A-Za-z0-9_]+)\}/g)) {
    const start = match.index ?? 0;
    source += escapeRegex(template.slice(last, start));
    const name = match[1];
    if (names.includes(name)) {
      source += `\\k<${name}>`; // the same value again (Laravel repeats :attribute in a few sentences)
    } else {
      names.push(name);
      source += NUMERIC.has(name) || (numericValue && name === 'value') ? `(?<${name}>${NUMBER})` : `(?<${name}>.+?)`;
    }
    last = start + match[0].length;
  }
  source += escapeRegex(template.slice(last));

  return { pattern: new RegExp(`^${source}$`, 's'), names, fixedLength: template.replace(/\{[A-Za-z0-9_]+\}/g, '').length };
}

const RULES: readonly Rule[] = (Object.entries(CATALOGUE) as [MessageKey, { en: string }][])
  .filter(([key]) => (key.startsWith('validation.laravel.') || key.startsWith('notify.server.')) && !/\.(one|other)$/.test(key))
  .map(([key, entry]) => ({ key, ...compile(entry.en, key.endsWith('.numeric')) }))
  // "...a date after or equal to {date}." must be tried before "...a date after {date}.", which would also match it.
  .sort((a, b) => b.fixedLength - a.fixedLength);

const MORE_ERRORS = /^(.*\S)\s+\(and (\d+) more errors?\)$/s;

/** Laravel writes the field key with spaces: "employee_category_id" -> "employee category id". */
const FIELD_KEY = /^[a-z][a-z0-9]*(?: [a-z0-9]+)*$/;

/** The Somali name of the field Laravel mentions, or the name exactly as Laravel wrote it when the catalogue has none (or the name is not a plain key). */
function fieldName(language: Language, attribute: string): string {
  if (!FIELD_KEY.test(attribute)) return attribute;

  return translateStoredValue(language, 'validation.field', attribute.replace(/ /g, '_')) ?? attribute;
}

function matchRule(language: Language, text: string): string | null {
  for (const rule of RULES) {
    const match = rule.pattern.exec(text);
    if (!match) continue;
    const groups = match.groups ?? {}; // a sentence without parameters has no named groups at all
    const params: Record<string, string> = {};
    for (const name of rule.names) {
      const value = groups[name] ?? '';
      params[name] = name === 'attribute' ? fieldName(language, value) : value;
    }

    return translateKey(language, rule.key, params);
  }

  return null;
}

/**
 * The message in `language`: Somali when the sentence is one the catalogue knows, otherwise (and always in English) the sentence unchanged.
 * Handles Laravel's "(and 2 more errors)" tail on a summarised first message.
 */
export function localizeServerMessage(language: Language, text: string): string {
  if (language !== 'so' || typeof text !== 'string' || text === '') return text;

  const more = MORE_ERRORS.exec(text);
  if (more) {
    const head = matchRule(language, more[1]);

    return head === null ? text : `${head} ${translateCount(language, 'validation.laravel.moreErrors', Number(more[2]))}`;
  }

  return matchRule(language, text) ?? text;
}

/** The same, for the `errors` object of a validation failure: `{ field: [message, ...] }`. Keys and the number of messages never change. */
export function localizeFieldErrors(language: Language, errors: Record<string, string[]> | undefined): Record<string, string[]> | undefined {
  if (errors === undefined || language !== 'so') return errors;

  return Object.fromEntries(
    Object.entries(errors).map(([field, messages]) => [field, Array.isArray(messages) ? messages.map((text) => localizeServerMessage(language, text)) : messages]),
  );
}
