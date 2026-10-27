// Pure helpers for choosing and creating positions inside the Office Staff form (no React, no network, no `@/` imports)
// so they can be unit-tested by Node's native test runner (positions.test.ts). The BACKEND is the real guard against
// duplicate names; these let the form notice an existing position first instead of failing after a round trip.
//
// A position never has a salary: nothing here knows about money, so choosing or creating a position can never fill in or
// change a salary field.

import { tNow } from '../i18n/active.ts';

/** Tidy a typed name: no leading/trailing spaces and no doubled spaces, exactly as the server stores it. */
export function normalisePositionName(name: string): string {
  return name.replace(/\s+/g, ' ').trim();
}

/** Letter case and spacing do not make a different position: "hr  officer" is "HR Officer". */
export function positionKey(name: string): string {
  return normalisePositionName(name).toLowerCase();
}

interface NamedPosition {
  id: number;
  name: string;
}

export function findPositionByName<T extends NamedPosition>(positions: readonly T[], name: string): T | null {
  const key = positionKey(name);
  if (key === '') return null;

  return positions.find((position) => positionKey(position.name) === key) ?? null;
}

/** Why this name cannot be created right now, or null when it can. */
export function positionNameError(name: string): string | null {
  const tidy = normalisePositionName(name);
  if (tidy === '') return tNow('validation.company.enterPositionName');
  if (tidy.length > 150) return tNow('validation.company.positionNameTooLong');

  return null;
}

/** What to do when the user asks to create a position: reuse an existing one, create a new one, or report a problem. */
export type CreatePositionDecision =
  | { kind: 'invalid'; message: string }
  | { kind: 'use_existing'; id: number; name: string }
  | { kind: 'create'; name: string };

export function decideCreatePosition(positions: readonly NamedPosition[], typedName: string): CreatePositionDecision {
  const problem = positionNameError(typedName);
  if (problem) return { kind: 'invalid', message: problem };

  const existing = findPositionByName(positions, typedName);

  return existing ? { kind: 'use_existing', id: existing.id, name: existing.name } : { kind: 'create', name: normalisePositionName(typedName) };
}

/** The nine positions the Office Staff workflow expects, in the order the owner listed them. */
export const OFFICE_STAFF_POSITION_NAMES = [
  'Administrative Officer',
  'HR Officer',
  'Marketing Officer',
  'Accountant',
  'Sales Officer',
  'Customer Service Officer',
  'Secretary / Receptionist',
  'Driver',
  'Office Cleaner',
] as const;
