/**
 * Pure helpers of the "source data changed" notice: the assignments, salary rows, Temporary Replacement covers and absence windows a company's payroll draft was
 * calculated from changed after it was generated. Unlike the Temporary Replacement warnings this STOPS Review and Finalize (409 PAYROLL_SOURCES_CHANGED) until
 * the company is generated again. The server sends raw facts (a category, what happened, the employee, raw field values); the helpers here turn them into
 * catalogue keys and values so the screen can show them in English or Somali. No React and no wording of their own: every visible text is a catalogue message.
 */

import type { MessageKey } from '../i18n/messages/index.ts';
import type { Params } from '../i18n/translate.ts';
import type { SourceChange, SourceChangeCategory, SourceChangeKind } from '../types/payroll.ts';

/** The most changes the server lists (and the screen shows); the real total is always reported next to them. */
export const MAX_LISTED_SOURCE_CHANGES = 50;

/** The server's error code when Review or Finalize is refused because the source data changed. */
export const SOURCES_CHANGED_CODE = 'PAYROLL_SOURCES_CHANGED';

/** A value ready to show: either a catalogue message (an enum, yes/no, "not set") or text exactly as the server sent it (a date, a number, a currency). */
export type SourceValueView = { kind: 'key'; key: MessageKey } | { kind: 'text'; text: string };

export interface SourceFieldView {
  /** The server's field name, such as `planned_end`. */
  field: string;
  /** The catalogue label of the field, or null for a field this screen does not know (then `field` is shown as it is). */
  labelKey: MessageKey | null;
  before: SourceValueView;
  after: SourceValueView;
}

export interface SourceChangeView {
  headingKey: MessageKey;
  headingValues: Params;
  fields: SourceFieldView[];
}

const HEADING: Record<SourceChangeCategory, Record<SourceChangeKind, MessageKey>> = {
  assignment: {
    added: 'warning.sources.assignment.added',
    removed: 'warning.sources.assignment.removed',
    changed: 'warning.sources.assignment.changed',
  },
  salary: {
    added: 'warning.sources.salary.added',
    removed: 'warning.sources.salary.removed',
    changed: 'warning.sources.salary.changed',
  },
  replacement: {
    added: 'warning.sources.replacement.added',
    removed: 'warning.sources.replacement.removed',
    changed: 'warning.sources.replacement.changed',
  },
  absence: {
    added: 'warning.sources.absence.added',
    removed: 'warning.sources.absence.removed',
    changed: 'warning.sources.absence.changed',
  },
};

const FIELD_LABEL: Readonly<Record<string, MessageKey>> = {
  type: 'option.sources.field.type',
  status: 'option.sources.field.status',
  start: 'option.sources.field.start',
  end: 'option.sources.field.end',
  replaces: 'option.sources.field.replaces',
  amount: 'option.sources.field.amount',
  currency: 'option.sources.field.currency',
  frequency: 'option.sources.field.frequency',
  by: 'option.sources.field.by',
  planned_end: 'option.sources.field.plannedEnd',
  approved: 'option.sources.field.approved',
  last_working_day: 'option.sources.field.lastWorkingDay',
  absent_from: 'option.sources.field.absentFrom',
  returned_on: 'option.sources.field.returnedOn',
  terminated_on: 'option.sources.field.terminatedOn',
};

/** Fields whose raw value is a fixed word of the server; every other field's value (dates, amounts, numbers) is shown as it is. */
const ENUM_VALUE: Readonly<Record<string, Readonly<Record<string, MessageKey>>>> = {
  type: {
    permanent: 'option.assignmentType.permanent',
    replacement: 'option.assignmentType.replacement',
  },
  status: {
    active: 'status.active',
    ended: 'option.sources.value.ended',
    cancelled: 'option.sources.value.cancelled',
  },
  frequency: {
    monthly: 'option.frequency.monthly',
    weekly: 'option.frequency.weekly',
    daily: 'option.frequency.daily',
  },
  approved: {
    yes: 'option.yes',
    no: 'option.no',
  },
};

/** The message that stands for "no value" (the thing was added or removed, or the field was empty). */
export const NOT_SET_KEY: MessageKey = 'option.label.notSet';

/** One raw value of one field, localized where it is an enum / yes-no / empty; dates and numbers are left exactly as the server sent them. */
export function sourceValueView(field: string, raw: string | null | undefined): SourceValueView {
  if (raw === null || raw === undefined || raw.trim() === '') return { kind: 'key', key: NOT_SET_KEY };

  const key = ENUM_VALUE[field]?.[raw];
  if (key) return { kind: 'key', key };
  // The assignment that this one replaces is an assignment number.
  if (field === 'replaces') return { kind: 'text', text: `#${raw}` };

  return { kind: 'text', text: raw };
}

const MISSING = '-';

function filled(value: string | null | undefined): string {
  return value === null || value === undefined || value.trim() === '' ? MISSING : value;
}

function numberText(...candidates: (number | null | undefined)[]): string {
  const found = candidates.find((value) => value !== null && value !== undefined);

  return found === undefined || found === null ? MISSING : String(found);
}

/**
 * What the heading of one change needs: the employee number (a salary row also its effective date, a Temporary cover its own id and the covering worker's
 * number, an absence the assignment it belongs to). A fact the server did not send is a dash, never a blank or the word "null".
 */
export function sourceChangeHeadingValues(change: SourceChange): Params {
  switch (change.category) {
    case 'assignment':
      return { employee: filled(change.employee_number), id: numberText(change.id, change.assignment_id) };
    case 'salary':
      return { employee: filled(change.employee_number), date: filled(change.detail) };
    case 'replacement':
      return { id: numberText(change.id), worker: filled(change.detail), employee: filled(change.employee_number) };
    case 'absence':
      return { employee: filled(change.employee_number), assignment: numberText(change.assignment_id) };
  }
}

/** One change as catalogue keys and values. A category or change kind this screen does not know yet falls back to the generic "changed" heading of the category. */
export function sourceChangeView(change: SourceChange): SourceChangeView {
  const headings = HEADING[change.category] ?? HEADING.assignment;
  const fields = (change.fields ?? []).map((row): SourceFieldView => ({
    field: row.field,
    labelKey: FIELD_LABEL[row.field] ?? null,
    before: sourceValueView(row.field, row.before),
    after: sourceValueView(row.field, row.after),
  }));

  return { headingKey: headings[change.change] ?? headings.changed, headingValues: sourceChangeHeadingValues(change), fields };
}

export interface VisibleSourceChanges {
  /** At most `MAX_LISTED_SOURCE_CHANGES` changes, in the order the server sent them. */
  changes: SourceChange[];
  /** The real number of changes (never smaller than what is shown). */
  total: number;
  truncated: boolean;
  /** The "Showing the first N of {total}" line, or null when everything is shown. */
  countLine: { key: MessageKey; values: Params } | null;
}

/** The changes to list and the line that says how many exist, so a long list is never mistaken for the whole story. */
export function visibleSourceChanges(changes: readonly SourceChange[] | null | undefined, total?: number | null): VisibleSourceChanges {
  const all = changes ?? [];
  const shown = all.slice(0, MAX_LISTED_SOURCE_CHANGES);
  const real = Math.max(typeof total === 'number' && Number.isFinite(total) ? Math.trunc(total) : 0, all.length);
  const truncated = real > shown.length;

  return {
    changes: shown,
    total: real,
    truncated,
    countLine: truncated ? { key: 'warning.sources.showingFirst', values: { shown: shown.length, total: real } } : null,
  };
}

function isSourceChange(value: unknown): value is SourceChange {
  if (typeof value !== 'object' || value === null) return false;
  const row = value as Record<string, unknown>;

  return typeof row.category === 'string' && typeof row.change === 'string' && Array.isArray(row.fields);
}

/**
 * The changes carried by a refused Review / Finalize (409 PAYROLL_SOURCES_CHANGED: `errors = { changes, total }`), or null when the error is another one or
 * carries no list. Read from the untouched body of the error so the nested list is never treated as validation messages.
 */
export function sourceChangesFromError(error: unknown): { changes: SourceChange[]; total: number } | null {
  if ((error as { code?: unknown } | null | undefined)?.code !== SOURCES_CHANGED_CODE) return null;
  const body = (error as { rawFieldErrors?: unknown }).rawFieldErrors as { changes?: unknown; total?: unknown } | undefined | null;
  if (!body || !Array.isArray(body.changes)) return null;

  const changes = body.changes.filter(isSourceChange);
  if (changes.length === 0) return null;

  return { changes, total: Math.max(typeof body.total === 'number' ? body.total : 0, changes.length) };
}
