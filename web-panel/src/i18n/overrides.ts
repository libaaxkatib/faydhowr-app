// Wording edited by Super Admin, laid over the built-in catalogue (no React, no `@/` imports).
//
//  - An override replaces the English and/or the Somali text of one existing message. Overrides for keys the catalogue does not have are ignored.
//  - An override that adds, drops or renames a `{placeholder}` of the built-in text is ignored for that language, so an edit can never break a
//    screen that fills in a name, a count or a date. The editor refuses such a text too, so this is a second safety net.
//  - With no overrides the built-in catalogue object itself is returned, unchanged.

import { placeholdersOf, type Catalogue, type Entry } from './catalog.ts';

export interface Override {
  readonly en?: string;
  readonly so?: string;
}

export type Overrides = Readonly<Record<string, Override>>;

type Listener = () => void;

let current: Overrides = {};
let version = 0;
const listeners = new Set<Listener>();
let memo: { base: Catalogue; version: number; merged: Catalogue } | null = null;

export function getOverrides(): Overrides {
  return current;
}

export function getOverridesVersion(): number {
  return version;
}

export function setOverrides(next: Overrides): void {
  current = next;
  version += 1;
  listeners.forEach((listener) => listener());
}

export function subscribeOverrides(listener: Listener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

/** True when `text` carries exactly the placeholders `builtIn` does. */
export function keepsPlaceholders(builtIn: string, text: string): boolean {
  const wanted = placeholdersOf(builtIn);
  const given = placeholdersOf(text);

  return wanted.length === given.length && wanted.every((name, index) => name === given[index]);
}

export function applyOverrides(base: Catalogue, overrides: Overrides): Catalogue {
  const keys = Object.keys(overrides);
  if (keys.length === 0) return base;

  const merged: Record<string, Entry> = { ...base };
  for (const key of keys) {
    const entry = base[key];
    const edit = overrides[key];
    if (!entry || !edit) continue;

    const en = typeof edit.en === 'string' && edit.en.trim() !== '' && keepsPlaceholders(entry.en, edit.en) ? edit.en : entry.en;
    const so = typeof edit.so === 'string' && edit.so.trim() !== '' && keepsPlaceholders(entry.en, edit.so) ? edit.so : entry.so;
    if (en !== entry.en || so !== entry.so) merged[key] = { ...entry, en, so };
  }

  return merged;
}

/** The catalogue the panel shows right now: the built-in one with the current overrides on top (rebuilt only when the overrides change). */
export function effectiveCatalogue(base: Catalogue): Catalogue {
  if (memo && memo.base === base && memo.version === version) return memo.merged;
  const merged = applyOverrides(base, current);
  memo = { base, version, merged };

  return merged;
}

/** Reads the `overrides` object of the API response defensively: anything that is not text is dropped. */
export function parseOverrides(raw: unknown): Overrides {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};

  const out: Record<string, Override> = {};
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (!value || typeof value !== 'object') continue;
    const { en, so } = value as { en?: unknown; so?: unknown };
    const edit: { en?: string; so?: string } = {};
    if (typeof en === 'string' && en.trim() !== '') edit.en = en;
    if (typeof so === 'string' && so.trim() !== '') edit.so = so;
    if (edit.en !== undefined || edit.so !== undefined) out[key] = edit;
  }

  return out;
}
