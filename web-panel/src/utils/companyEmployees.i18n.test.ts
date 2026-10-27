import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { CATALOGUE } from '../i18n/messages/index.ts';
import {
  ASSIGNMENT_TYPE_OPTIONS,
  SHIFT_OPTIONS,
  assignmentShiftLabel,
  assignmentTypeLabel,
  eventLabel,
  EVENT_LABELS,
  startDateChangeWarning,
  validateAssignForm,
  validateReplaceForm,
  validateReturnForm,
  validateStopForm,
} from './companyEmployees.ts';

const emptyAssign = { employee_id: '' as const, assignment_type: '' as const, position_id: '' as const, start_date: '', salary_amount: '', salary_currency: '' };

function inSomali<T>(run: () => T): T {
  try {
    setActiveLanguage('so');
    return run();
  } finally {
    setActiveLanguage('en');
  }
}

test('assignment type and shift labels are English by default and the approved Somali labels on a Somali HR screen', () => {
  assert.equal(assignmentTypeLabel('replacement'), 'Replacement');
  assert.equal(assignmentShiftLabel('night'), 'Night shift');
  assert.equal(inSomali(() => assignmentTypeLabel('replacement')), 'Beddelaad');
  assert.equal(inSomali(() => assignmentShiftLabel('day')), 'Shaqada Maalinta');
  assert.equal(inSomali(() => assignmentShiftLabel(null)), CATALOGUE['option.company.shiftNotRecorded'].so);
  for (const option of [...ASSIGNMENT_TYPE_OPTIONS, ...SHIFT_OPTIONS]) assert.equal(CATALOGUE[option.labelKey].en, option.label);
});

test('form checks keep their English text and speak Somali on a Somali HR screen', () => {
  assert.equal(validateAssignForm(emptyAssign).employee_id, 'Search and choose a registered employee.');
  assert.equal(inSomali(() => validateAssignForm(emptyAssign).employee_id), CATALOGUE['validation.company.chooseEmployee'].so);
  assert.equal(inSomali(() => validateStopForm('', '', '2026-10-01').reason), CATALOGUE['validation.company.stopReason'].so);
  assert.match(inSomali(() => validateStopForm('2026-09-01', 'enough', '2026-10-01').end_date ?? ''), /2026-10-01/);
  assert.match(inSomali(() => validateReturnForm('2026-10-01', '2026-10-01', { amount: '', currency: '', confirmed: false }).return_date ?? ''), /2026-10-01/);
  const replace = { replacement_employee_id: 3 as const, replacement_date: '2026-09-01', original_last_day: '', assignment_type: 'permanent' as const, position_id: 1 as const, salary_amount: '100', salary_currency: 'USD' };
  assert.match(inSomali(() => validateReplaceForm(replace, '2026-10-01', 9).replacement_date ?? ''), /2026-10-01/);
});

test('the start-date warning is translated with its dates in place', () => {
  assert.match(startDateChangeWarning('2026-10-10', '2026-08-20') ?? '', /^Changing the start date changes the payable days of every month from 2026-08 on\./);
  const somali = inSomali(() => startDateChangeWarning('2026-10-10', '2026-08-20')) ?? '';
  assert.ok(somali.includes('2026-08') && somali.includes('2026-10-10') && !somali.includes('{'));
  assert.equal(inSomali(() => startDateChangeWarning('2026-10-10', '2026-10-10')), null);
});

test('history event labels keep their English text and speak Somali on a Somali HR screen', () => {
  assert.equal(eventLabel('salary_changed'), 'Salary changed');
  assert.equal(eventLabel('something_new'), 'something_new');
  for (const event of Object.keys(EVENT_LABELS)) {
    assert.equal(CATALOGUE[`option.company.event.${event}` as keyof typeof CATALOGUE].en, EVENT_LABELS[event]);
    assert.notEqual(inSomali(() => eventLabel(event)), '');
  }
  assert.equal(inSomali(() => eventLabel('salary_changed')), CATALOGUE['option.company.event.salary_changed'].so);
  assert.equal(inSomali(() => eventLabel('something_new')), 'something_new');
});
