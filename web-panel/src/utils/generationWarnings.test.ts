import test from 'node:test';
import assert from 'node:assert/strict';
import { r1cReviewOf } from '../i18n/r1cApprovals.ts';

import { CATALOGUE } from '../i18n/messages/index.ts';
import { WARNING_CONDITIONS, WARNING_CONDITION_LABEL, groupWarnings, warningCountOf, warningPerson } from './generationWarnings.ts';
import type { GenerationWarning } from '../types/payroll.ts';

function warning(condition: GenerationWarning['condition'], overrides: Partial<GenerationWarning> = {}): GenerationWarning {
  return { condition, blocking: false, client_company_id: 5, company_name: 'Alpha Co', employee_number: 'E-1', employee_name: 'Asha Warsame', detail: 'Detail from the server.', what_remains: 'Nothing is calculated for those days.', ...overrides };
}

test('warnings are grouped by condition A, B, C, D and empty groups are left out', () => {
  const groups = groupWarnings([warning('C'), warning('A'), warning('C', { employee_name: 'Omar' }), warning('D')]);

  assert.deepEqual(groups.map((g) => g.condition), ['A', 'C', 'D']);
  assert.deepEqual(groups.map((g) => g.warnings.length), [1, 2, 1]);
  assert.deepEqual(groupWarnings([]), []);
});

test('a condition the panel does not know is still shown (under D), never dropped', () => {
  const odd = { ...warning('A'), condition: 'Z' } as unknown as GenerationWarning;

  assert.deepEqual(groupWarnings([odd]).map((g) => g.condition), ['D']);
  assert.equal(groupWarnings([odd, warning('D')])[0].warnings.length, 2);
});

test('every condition has a heading in both languages, with the status the owner gave it (proposals unless one of the 86 approved)', () => {
  assert.deepEqual(WARNING_CONDITIONS, ['A', 'B', 'C', 'D']);
  for (const condition of WARNING_CONDITIONS) {
    const entry = CATALOGUE[WARNING_CONDITION_LABEL[condition]];
    assert.ok(entry.en.trim() && entry.so.trim(), condition);
    assert.notEqual(entry.en, entry.so, condition);
    assert.equal(entry.review, r1cReviewOf(WARNING_CONDITION_LABEL[condition]), condition);
  }
});

test('the person a warning is about is named with the employee number when there is one', () => {
  assert.equal(warningPerson(warning('A')), 'Asha Warsame (E-1)');
  assert.equal(warningPerson(warning('A', { employee_number: null })), 'Asha Warsame');
  assert.equal(warningPerson(warning('A', { employee_name: null })), 'E-1');
  assert.equal(warningPerson(warning('A', { employee_name: null, employee_number: null })), null);
  assert.equal(warningPerson(warning('A', { employee_name: '  ', employee_number: ' ' })), null);
});

test('the count on a company row is the loaded list when it is known, otherwise the overview\'s', () => {
  assert.equal(warningCountOf({ generation_warning_count: 3 }), 3);
  assert.equal(warningCountOf({ generation_warning_count: 3 }, [warning('A')]), 1);
  assert.equal(warningCountOf({}), 0);
  assert.equal(warningCountOf({ generation_warning_count: null }, []), 0);
});
