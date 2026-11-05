import test from 'node:test';
import assert from 'node:assert/strict';
import { r1cReviewOf } from '../i18n/r1cApprovals.ts';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { setActiveLanguage } from '../i18n/active.ts';
import { placeholdersOf } from '../i18n/catalog.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import { payrollRelease1c } from '../i18n/messages/payrollRelease1c.ts';
import { ApiClientError } from '../api/client.ts';
import type { SourceChange, SourceChangeCategory, SourceChangeKind } from '../types/payroll.ts';
import { SOMALI_WORDING_CODES, friendlyErrorMessage } from './hrHelpers.ts';
import { MAX_LISTED_SOURCE_CHANGES, NOT_SET_KEY, sourceChangeHeadingValues, sourceChangeView, sourceChangesFromError, sourceValueView, visibleSourceChanges } from './sourceChanges.ts';

const read = (relative: string) => readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');

const CATEGORIES: SourceChangeCategory[] = ['assignment', 'salary', 'replacement', 'absence'];
const KINDS: SourceChangeKind[] = ['added', 'removed', 'changed'];

const change = (over: Partial<SourceChange> = {}): SourceChange => ({
  category: 'assignment',
  change: 'changed',
  id: 7,
  assignment_id: 7,
  employee_number: 'E-12',
  detail: null,
  fields: [{ field: 'status', before: 'active', after: 'ended' }],
  ...over,
});

// ---------------------------------------------------------------- headings: every category x change kind

test('every category and change kind has a heading message whose placeholders are all supplied', () => {
  for (const category of CATEGORIES) {
    for (const kind of KINDS) {
      const row = change({ category, change: kind, id: 9, assignment_id: 7, employee_number: 'E-12', detail: category === 'salary' ? '2026-11-01' : 'E-40' });
      const view = sourceChangeView(row);
      const entry = CATALOGUE[view.headingKey];
      assert.ok(entry, `${category}/${kind} has a catalogue heading`);
      assert.equal(view.headingKey, `warning.sources.${category}.${kind}`);
      assert.deepEqual([...placeholdersOf(entry.en)].sort(), Object.keys(view.headingValues).sort(), `${category}/${kind}: the heading needs exactly the values it is given`);
      assert.deepEqual([...placeholdersOf(entry.so)].sort(), Object.keys(view.headingValues).sort(), `${category}/${kind}: same in Somali`);
    }
  }
});

test('a heading names the employee, and the assignment / cover / effective date of its category', () => {
  assert.deepEqual(sourceChangeHeadingValues(change({ id: 7, assignment_id: 7 })), { employee: 'E-12', id: '7' });
  assert.deepEqual(sourceChangeHeadingValues(change({ category: 'salary', id: null, assignment_id: 7, detail: '2026-11-01' })), { employee: 'E-12', date: '2026-11-01' });
  assert.deepEqual(sourceChangeHeadingValues(change({ category: 'replacement', id: 31, assignment_id: 7, detail: 'E-40' })), { id: '31', worker: 'E-40', employee: 'E-12' });
  assert.deepEqual(sourceChangeHeadingValues(change({ category: 'absence', id: 5, assignment_id: 7 })), { employee: 'E-12', assignment: '7' });
});

test('a fact the server did not send is a dash, never a blank or the word "null"', () => {
  for (const category of CATEGORIES) {
    const values = sourceChangeHeadingValues(change({ category, id: null, assignment_id: null, employee_number: null, detail: null }));
    for (const value of Object.values(values)) assert.equal(value, '-', category);
  }
  assert.equal(sourceChangeHeadingValues(change({ id: null, assignment_id: 3 })).id, '3', 'an assignment without its own id falls back to the assignment id');
  assert.equal(sourceChangeHeadingValues(change({ employee_number: '  ' })).employee, '-');
});

test('an unknown change kind or category still gets a heading instead of breaking the list', () => {
  const kind = sourceChangeView(change({ change: 'moved' as SourceChangeKind }));
  assert.equal(kind.headingKey, 'warning.sources.assignment.changed');
  const category = sourceChangeView(change({ category: 'bonus' as SourceChangeCategory }));
  assert.ok(CATALOGUE[category.headingKey]);
});

// ---------------------------------------------------------------- field rows and values

test('every field the server can name has a label, and its raw values are localized where they are words', () => {
  const fields = ['type', 'status', 'start', 'end', 'replaces', 'amount', 'currency', 'frequency', 'by', 'planned_end', 'approved', 'last_working_day', 'absent_from', 'returned_on', 'terminated_on'];
  for (const field of fields) {
    const view = sourceChangeView(change({ fields: [{ field, before: 'x', after: 'y' }] }));
    assert.ok(view.fields[0].labelKey, `${field} has a label`);
    assert.ok(CATALOGUE[view.fields[0].labelKey!], `${field}: label is in the catalogue`);
  }
  assert.equal(sourceChangeView(change({ fields: [{ field: 'colour', before: 'a', after: 'b' }] })).fields[0].labelKey, null, 'an unknown field keeps its own name');
});

test('enum values and yes / no use catalogue messages; dates, amounts and numbers are left exactly as sent', () => {
  assert.deepEqual(sourceValueView('type', 'permanent'), { kind: 'key', key: 'option.assignmentType.permanent' });
  assert.deepEqual(sourceValueView('type', 'replacement'), { kind: 'key', key: 'option.assignmentType.replacement' });
  assert.deepEqual(sourceValueView('status', 'active'), { kind: 'key', key: 'status.active' });
  assert.deepEqual(sourceValueView('status', 'ended'), { kind: 'key', key: 'option.sources.value.ended' });
  assert.deepEqual(sourceValueView('status', 'cancelled'), { kind: 'key', key: 'option.sources.value.cancelled' });
  assert.deepEqual(sourceValueView('frequency', 'monthly'), { kind: 'key', key: 'option.frequency.monthly' });
  assert.deepEqual(sourceValueView('frequency', 'weekly'), { kind: 'key', key: 'option.frequency.weekly' });
  assert.deepEqual(sourceValueView('frequency', 'daily'), { kind: 'key', key: 'option.frequency.daily' });
  assert.deepEqual(sourceValueView('approved', 'yes'), { kind: 'key', key: 'option.yes' });
  assert.deepEqual(sourceValueView('approved', 'no'), { kind: 'key', key: 'option.no' });
  for (const key of ['option.assignmentType.permanent', 'status.active', 'option.sources.value.ended', 'option.frequency.daily', 'option.yes', 'option.no'] as const) assert.ok(CATALOGUE[key], key);

  assert.deepEqual(sourceValueView('start', '2026-11-01'), { kind: 'text', text: '2026-11-01' });
  assert.deepEqual(sourceValueView('amount', '350.00'), { kind: 'text', text: '350.00' });
  assert.deepEqual(sourceValueView('currency', 'USD'), { kind: 'text', text: 'USD' });
  assert.deepEqual(sourceValueView('by', 'E-40'), { kind: 'text', text: 'E-40' });
  assert.deepEqual(sourceValueView('replaces', '12'), { kind: 'text', text: '#12' });
  assert.deepEqual(sourceValueView('status', 'on_hold'), { kind: 'text', text: 'on_hold' }, 'a word this screen does not know is shown as sent');
  // The same word means the same thing only for its own field: "yes" in a date field is just text.
  assert.deepEqual(sourceValueView('end', 'yes'), { kind: 'text', text: 'yes' });
});

test('an empty or missing value reads "not set" in the language of the screen', () => {
  for (const raw of [null, undefined, '', '   ']) assert.deepEqual(sourceValueView('end', raw), { kind: 'key', key: NOT_SET_KEY });
  assert.equal(CATALOGUE[NOT_SET_KEY].en, 'Not set');
  const added = sourceChangeView(change({ change: 'added', fields: [{ field: 'end', before: null, after: null }, { field: 'start', before: null, after: '2026-11-01' }] }));
  assert.deepEqual(added.fields[0].before, { kind: 'key', key: NOT_SET_KEY });
  assert.deepEqual(added.fields[1], { field: 'start', labelKey: 'option.sources.field.start', before: { kind: 'key', key: NOT_SET_KEY }, after: { kind: 'text', text: '2026-11-01' } });
});

test('a change without fields has a heading and no rows', () => {
  assert.deepEqual(sourceChangeView(change({ fields: [] })).fields, []);
  assert.deepEqual(sourceChangeView({ ...change(), fields: undefined as unknown as SourceChange['fields'] }).fields, []);
});

// ---------------------------------------------------------------- the 50 cap and the "showing the first N of {total}" line

test('at most 50 changes are listed and the real total is reported when there are more', () => {
  const many = Array.from({ length: 50 }, (_, index) => change({ id: index + 1 }));
  const full = visibleSourceChanges(many, 50);
  assert.equal(MAX_LISTED_SOURCE_CHANGES, 50);
  assert.equal(full.changes.length, 50);
  assert.equal(full.truncated, false);
  assert.equal(full.countLine, null);

  const more = visibleSourceChanges(many, 137);
  assert.equal(more.changes.length, 50);
  assert.equal(more.total, 137);
  assert.equal(more.truncated, true);
  assert.deepEqual(more.countLine, { key: 'warning.sources.showingFirst', values: { shown: 50, total: 137 } });

  // Even if a server sent more than 50, the screen shows 50 and still states the real number.
  const over = visibleSourceChanges([...many, change({ id: 51 }), change({ id: 52 })]);
  assert.equal(over.changes.length, 50);
  assert.equal(over.total, 52);
  assert.deepEqual(over.countLine?.values, { shown: 50, total: 52 });
});

test('the total is never smaller than what is shown, and a missing list is empty', () => {
  assert.equal(visibleSourceChanges([change(), change()], 1).total, 2);
  assert.equal(visibleSourceChanges([change()], undefined).total, 1);
  assert.equal(visibleSourceChanges([change()], null).countLine, null);
  assert.deepEqual(visibleSourceChanges(null, 0), { changes: [], total: 0, truncated: false, countLine: null });
  assert.deepEqual(visibleSourceChanges(undefined, Number.NaN), { changes: [], total: 0, truncated: false, countLine: null });
});

test('the "showing the first N" line fills in both numbers in English and Somali', () => {
  const entry = CATALOGUE['warning.sources.showingFirst'];
  assert.deepEqual(placeholdersOf(entry.en).sort(), ['shown', 'total']);
  assert.deepEqual(placeholdersOf(entry.so).sort(), ['shown', 'total']);
});

// ---------------------------------------------------------------- the 409 refusal

test('a refused Review / Finalize carries the list in errors.changes; only PAYROLL_SOURCES_CHANGED is read', () => {
  const body = { changes: [change(), change({ category: 'salary', id: null, detail: '2026-11-01' })], total: 63 };
  const error = new ApiClientError(409, 'The source data changed.', 'PAYROLL_SOURCES_CHANGED', body as unknown as Record<string, string[]>);
  const parsed = sourceChangesFromError(error);
  assert.equal(parsed?.changes.length, 2);
  assert.equal(parsed?.total, 63);

  // The nested list never breaks the validation-message handling of the client (Somali too).
  try {
    setActiveLanguage('so');
    assert.doesNotThrow(() => error.fieldErrors);
    assert.equal(sourceChangesFromError(error)?.total, 63);
  } finally {
    setActiveLanguage('en');
  }

  assert.equal(sourceChangesFromError(new ApiClientError(409, 'x', 'PAYROLL_INPUTS_CHANGED', body as unknown as Record<string, string[]>)), null);
  assert.equal(sourceChangesFromError(new ApiClientError(409, 'x', 'PAYROLL_SOURCES_CHANGED')), null);
  assert.equal(sourceChangesFromError(new ApiClientError(409, 'x', 'PAYROLL_SOURCES_CHANGED', { changes: [] } as unknown as Record<string, string[]>)), null);
  assert.equal(sourceChangesFromError(new ApiClientError(409, 'x', 'PAYROLL_SOURCES_CHANGED', { changes: ['not a change'] } as unknown as Record<string, string[]>)), null);
  assert.equal(sourceChangesFromError(null), null);
  assert.equal(sourceChangesFromError(new Error('boom')), null);
  // A total that the server left out falls back to the number of changes it listed.
  assert.equal(sourceChangesFromError({ code: 'PAYROLL_SOURCES_CHANGED', rawFieldErrors: { changes: [change()] } })?.total, 1);
});

test('PAYROLL_SOURCES_CHANGED reads as a plain sentence: the server\'s own in English, ours in Somali', () => {
  assert.ok(SOMALI_WORDING_CODES.has('PAYROLL_SOURCES_CHANGED'));
  const entry = CATALOGUE['notify.error.PAYROLL_SOURCES_CHANGED'];
  assert.equal(entry.review, r1cReviewOf('notify.error.PAYROLL_SOURCES_CHANGED'), 'owner-approved on 2026-11-05 (one of the 86)');
  assert.equal(friendlyErrorMessage({ code: 'PAYROLL_SOURCES_CHANGED', message: 'The source data of Acme changed.' }, 'fallback'), 'The source data of Acme changed.');
  assert.equal(friendlyErrorMessage({ code: 'PAYROLL_SOURCES_CHANGED' }, 'fallback'), 'fallback');
  try {
    setActiveLanguage('so');
    assert.equal(friendlyErrorMessage({ code: 'PAYROLL_SOURCES_CHANGED', message: 'The source data of Acme changed.' }, 'fallback'), entry.so);
  } finally {
    setActiveLanguage('en');
  }
  assert.ok(!/[{}"]/.test(entry.en) && !/[{}]/.test(entry.so), 'no JSON in the wording');
});

// ---------------------------------------------------------------- the catalogue file

test('every guard message has English and Somali and is one of the 86 owner-approved R1C messages, and the Generate pointer reuses the locked label through a placeholder', () => {
  for (const [key, entry] of Object.entries(payrollRelease1c)) {
    assert.equal(entry.review, r1cReviewOf(key), key);
    assert.equal(entry.review, 'approved', key);
    assert.ok(entry.en.trim() !== '' && entry.so.trim() !== '', key);
    assert.deepEqual(placeholdersOf(entry.so).sort(), placeholdersOf(entry.en).sort(), `${key}: placeholders`);
  }
  assert.deepEqual(placeholdersOf(payrollRelease1c['warning.sources.pointer'].en), ['action']);
  assert.ok(!payrollRelease1c['warning.sources.pointer'].en.includes('Generate / refresh draft'), 'the locked label is never retyped');
  assert.equal(CATALOGUE['action.generateDraft'].review, 'approved');
});

// ---------------------------------------------------------------- the screens (source level)

test('the notice shows the title, the blocking sentence, the pointer to Generate and the list; the Generate dialog gets the gentler variant', () => {
  const panel = read('../features/hrm/GenerationWarningsPanel.tsx');
  assert.ok(panel.includes('data.source_inputs_changed === true'));
  assert.ok(panel.includes('data.source_changes') && panel.includes('data.source_changes_total'));
  assert.ok(panel.includes('data.deduction_inputs_changed === true'), 'the penalties / advances notice is still shown, next to this one');
  assert.ok(panel.includes("t('warning.sources.title')") && panel.includes("t('warning.sources.blocking')"));
  assert.ok(panel.includes("t('warning.sources.pointer', { action: t('action.generateDraft') })"), 'the pointer uses the locked Generate label');
  assert.ok(panel.includes("t('warning.sources.generate')"), 'the Generate dialog says generating brings the changes in');
  assert.ok(panel.includes('role="alert"') && panel.includes('data-testid="source-inputs-changed"'));
  assert.ok(panel.includes('sourceChangeView(change)') && panel.includes('visibleSourceChanges(changes, total)') && panel.includes('visible.countLine'));
  assert.ok(panel.includes("t(view.headingKey, view.headingValues)"));

  const generate = read('../features/hrm/GenerateCompanyDialog.tsx');
  assert.ok(generate.includes('context="generate"'), 'the Generate dialog uses the gentler variant');
  const action = read('../features/hrm/SectionActionDialog.tsx');
  assert.ok(action.includes('<GenerationWarningsPanel period={period} company={company}'), 'Review and Finalize use the blocking variant (the default context)');
  assert.ok(!action.includes('context="generate"'));
});

test('the Temporary Replacement warnings (conditions A to D) are untouched: they never block', () => {
  const panel = read('../features/hrm/GenerationWarningsPanel.tsx');
  assert.ok(panel.includes('<GenerationWarningsList warnings={data.warnings} />'));
  const action = read('../features/hrm/SectionActionDialog.tsx');
  assert.ok(!/disabled=\{[^}]*(source|warning)/i.test(action), 'no button is disabled by a notice or a warning');
});

test('a refused Review / Finalize keeps its dialog open with the server\'s list, and the types and the API carry the new fields', () => {
  const sections = read('../features/hrm/CompanySectionsPanel.tsx');
  assert.ok(sections.includes('sourceChangesFromError(err)'));
  assert.ok(sections.includes('friendlyErrorMessage(err'));
  assert.ok(sections.includes('refusedChanges={refusal?.sectionId === reviewing.id ? refusal : null}'));
  assert.ok(sections.includes('refusedChanges={refusal?.sectionId === finalizing.id ? refusal : null}'));
  const dialog = read('../features/hrm/SectionActionDialog.tsx');
  assert.ok(dialog.includes('<SourceInputsNotice context="action" changes={refusedChanges.changes} total={refusedChanges.total} />'));
  const types = read('../types/payroll.ts');
  assert.ok(types.includes('source_inputs_changed?: boolean;') && types.includes('source_changes_total?: number;') && types.includes('source_changes?: SourceChange[];'));
  assert.ok(types.includes('export interface SourceChange {') && types.includes('export interface SourceChangesErrorBody'));
  const hr = read('../utils/hrHelpers.ts');
  assert.ok(hr.includes("'PAYROLL_SOURCES_CHANGED'"));
});

test('the new area is registered in the catalogue', () => {
  const index = read('../i18n/messages/index.ts');
  assert.ok(index.includes("import { payrollRelease1c } from './payrollRelease1c.ts';"));
  assert.ok(index.includes('...payrollRelease1c,'));
  for (const key of Object.keys(payrollRelease1c)) assert.ok(CATALOGUE[key as keyof typeof CATALOGUE], key);
});
