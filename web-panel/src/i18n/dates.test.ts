import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { mediumDate, mediumDateTime, monthYearLabel, SOMALI_MONTHS, SOMALI_MONTHS_SHORT } from './dates.ts';
import { formatDate, formatDateTime, formatDurationSince } from '../utils/formatters.ts';
import { periodLabel } from '../utils/companyEmployees.ts';
import { setActiveLanguage } from './active.ts';

describe('dates', () => {
  it('keeps the exact English output', () => {
    assert.equal(monthYearLabel('en', 2026, 9), 'October 2026');
    assert.equal(mediumDate('en', new Date(2026, 9, 1)), 'Oct 1, 2026');
    assert.equal(mediumDateTime('en', new Date(2026, 9, 1, 15, 45)), 'Oct 1, 2026, 3:45 PM');
  });

  it('writes Somali months and a 24-hour clock', () => {
    assert.equal(monthYearLabel('so', 2026, 9), 'Oktoobar 2026');
    assert.equal(mediumDate('so', new Date(2026, 9, 1)), '1 Okt 2026');
    assert.equal(mediumDateTime('so', new Date(2026, 9, 1, 15, 45)), '1 Okt 2026, 15:45');
    assert.equal(mediumDateTime('so', new Date(2026, 9, 1, 0, 5)), '1 Okt 2026, 00:05');
  });

  it('has twelve distinct months in both lists', () => {
    assert.equal(new Set(SOMALI_MONTHS).size, 12);
    assert.equal(new Set(SOMALI_MONTHS_SHORT).size, 12);
  });

  it('follows the language that is showing, and falls back to English', () => {
    const iso = '2026-10-04T10:00:00';
    setActiveLanguage('so');
    try {
      assert.equal(formatDate(iso), '4 Okt 2026');
      assert.equal(formatDateTime(iso), '4 Okt 2026, 10:00');
      assert.equal(periodLabel('2026-10'), 'Oktoobar 2026');
      assert.equal(formatDurationSince(new Date(Date.now() - 21 * 86400000).toISOString()), '21 maalmood');
      assert.equal(formatDurationSince(new Date(Date.now() - 86400000).toISOString()), '1 maalin');
    } finally {
      setActiveLanguage('en');
    }
    assert.equal(formatDate(iso), 'Oct 4, 2026');
    assert.equal(periodLabel('2026-10'), 'October 2026');
    assert.equal(formatDurationSince(new Date(Date.now() - 21 * 86400000).toISOString()), '21 days');
  });
});
