import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage } from '../i18n/active.ts';
import { categoryTileLabel, categoryTileTo } from './hrDashboardLinks.ts';

test('the Office Staff category tile says "(Category)" in the language of the screen; other categories and the link are untouched', () => {
  assert.equal(categoryTileLabel('Office Staff'), 'Office Staff (Category)');
  assert.equal(categoryTileLabel('  office staff '), 'Office Staff (Category)');
  assert.equal(categoryTileLabel('Cleaner'), 'Cleaner');
  try {
    setActiveLanguage('so');
    assert.equal(categoryTileLabel('Office Staff'), 'Shaqaalaha Xafiiska (Qayb)');
    assert.equal(categoryTileLabel('Cleaner'), 'Cleaner');
    assert.equal(categoryTileTo(4), '/hr/employees?employee_category_id=4');
  } finally {
    setActiveLanguage('en');
  }
});
