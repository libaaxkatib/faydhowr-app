import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { blankDeepCleaning, deepCleaningGapText, formatCalendarDate, monthLabel, shortMonth, validateDeepCleaning } from './facility.ts';

const TODAY = '2026-10-27';

test('Deep Cleaning wording is English by default and exactly what the screen showed before', () => {
  assert.equal(deepCleaningGapText({ state: 'completed', months_without: 0 }), 'Done this month');
  assert.equal(deepCleaningGapText({ state: 'not_completed', months_without: 1 }), 'Not done yet this month');
  assert.equal(deepCleaningGapText({ state: 'overdue_multiple', months_without: 3 }), 'No Deep Cleaning for 3 months in a row');
  assert.equal(deepCleaningGapText({ state: 'no_history', months_without: 0 }), 'Never recorded');
  assert.equal(monthLabel('2026-10'), 'October 2026');
  assert.equal(shortMonth('2026-08'), 'Aug');
  assert.equal(formatCalendarDate('2026-10-05'), 'Oct 5, 2026');
  assert.deepEqual(validateDeepCleaning({ ...blankDeepCleaning(), choice: 'completed' }, null, TODAY), ['Deep Cleaning: enter the date it was completed.']);
  assert.deepEqual(validateDeepCleaning({ ...blankDeepCleaning(), choice: 'completed', completedOn: '2026-11-01' }, null, TODAY), ['Deep Cleaning: the completion date cannot be in the future.']);
});

test('Deep Cleaning wording, months and dates follow the language an HR screen shows; stored values never change', () => {
  try {
    setActiveLanguage('so');
    assert.equal(deepCleaningGapText({ state: 'completed', months_without: 0 }), 'Bishan waa la sameeyey');
    assert.equal(deepCleaningGapText({ state: 'overdue_multiple', months_without: 3 }), 'Nadiifin qoto dheer ma jirin 3 bilood oo isku xigta');
    assert.equal(deepCleaningGapText({ state: 'no_history', months_without: 0 }), 'Weligeed lama diiwaangelin');
    assert.equal(monthLabel('2026-10'), 'Oktoobar 2026');
    assert.equal(shortMonth('2026-08'), 'Ago');
    assert.equal(formatCalendarDate('2026-10-05'), '5 Okt 2026');
    assert.equal(formatCalendarDate(null), '—');
    assert.equal(monthLabel('not-a-month'), 'not-a-month');
    const problems = validateDeepCleaning({ ...blankDeepCleaning(), choice: 'completed' }, null, TODAY);
    assert.equal(problems.length, 1);
    assert.match(problems[0], /^Nadiifinta qoto dheer:/);
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(monthLabel('2026-10'), 'October 2026');
});
