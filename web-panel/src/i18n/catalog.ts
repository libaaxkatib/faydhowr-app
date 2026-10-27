// The shape of the translation catalogue (no React, no `@/` imports). Each message carries its English text and its Somali text side by side, so a
// missing Somali string is a type error, and the two can never drift into different files.
//
// Keys look like `<category>.<area>.<name>` (the area is left out for the shared, owner-approved vocabulary). The category is the first segment.

export const CATEGORIES = ['nav', 'status', 'action', 'option', 'warning', 'confirm', 'validation', 'notify'] as const;
export type Category = (typeof CATEGORIES)[number];

/**
 * approved   - the owner approved this exact Somali text (the id points at the review list).
 * owner-term - the owner supplied the wording, but its review is still open; it must not be treated as final.
 * proposed   - Somali written for this implementation from the approved vocabulary; not yet reviewed by the owner.
 */
export type Review = 'approved' | 'owner-term' | 'proposed';

export interface Entry {
  readonly en: string;
  readonly so: string;
  readonly review: Review;
  /** The owner's review-list id, such as HR-ACT-019. */
  readonly id?: string;
  readonly note?: string;
}

export type Catalogue = Readonly<Record<string, Entry>>;

/** Somali written for this implementation (not yet reviewed). */
export function message(en: string, so: string, note?: string): Entry {
  return note === undefined ? { en, so, review: 'proposed' } : { en, so, review: 'proposed', note };
}

/** Somali the owner approved (or supplied) under a review-list id. */
export function approved(id: string, en: string, so: string, review: 'approved' | 'owner-term' = 'approved', note?: string): Entry {
  return note === undefined ? { en, so, review, id } : { en, so, review, id, note };
}

/** Keeps the literal keys so `MessageKey` is the exact set of keys. */
export function defineMessages<const T extends Catalogue>(entries: T): T {
  return entries;
}

export const PLACEHOLDER_PATTERN = /\{([A-Za-z0-9_]+)\}/g;

export function placeholdersOf(text: string): string[] {
  return [...text.matchAll(PLACEHOLDER_PATTERN)].map((match) => match[1]).sort();
}

export function categoryOf(key: string): string {
  return key.split('.')[0];
}
