import test from 'node:test';
import assert from 'node:assert/strict';

import { categoryCounts, filterByCategory, isInternalOffice, parseCategoryFilter, visibleAliases } from './clientCompanyFilter.ts';

/** Run with: node --test src/utils/clientCompanyFilter.test.ts */

const COMPANIES = [
  { id: 1, name: 'ALFARAJ', category: 'company' },
  { id: 2, name: 'HUMANCARE', category: 'company' },
  { id: 3, name: 'GURI ASAD', category: 'guri' },
  { id: 4, name: 'Kinsi', category: 'guri' },
  { id: 5, name: 'ABDI HUSEIN', category: 'guri' },
];

test('All shows every record, Company and Guri show only their own category', () => {
  assert.deepEqual(filterByCategory(COMPANIES, 'all').map((c) => c.id), [1, 2, 3, 4, 5]);
  assert.deepEqual(filterByCategory(COMPANIES, 'company').map((c) => c.name), ['ALFARAJ', 'HUMANCARE']);
  assert.deepEqual(filterByCategory(COMPANIES, 'guri').map((c) => c.name), ['GURI ASAD', 'Kinsi', 'ABDI HUSEIN']);
});

test('filtering never changes, renames or reorders records and never mutates the input', () => {
  const before = JSON.stringify(COMPANIES);
  const guri = filterByCategory(COMPANIES, 'guri');
  assert.equal(JSON.stringify(COMPANIES), before);
  assert.equal(guri[0], COMPANIES[2], 'same objects, exact names preserved');
  assert.notEqual(filterByCategory(COMPANIES, 'all'), COMPANIES, 'returns a copy');
});

test('counts equal the rows each filter shows, and company + guri add up to all', () => {
  const counts = categoryCounts(COMPANIES);
  assert.deepEqual(counts, { all: 5, company: 2, guri: 3 });
  for (const filter of ['all', 'company', 'guri'] as const) {
    assert.equal(counts[filter], filterByCategory(COMPANIES, filter).length);
  }
  assert.equal(counts.company + counts.guri, counts.all);
});

test('an empty list gives zero counts and empty results', () => {
  assert.deepEqual(categoryCounts([]), { all: 0, company: 0, guri: 0 });
  assert.deepEqual(filterByCategory([], 'guri'), []);
});

test('a hundred-record list keeps every category count accurate', () => {
  const list = Array.from({ length: 100 }, (_, i) => ({ id: i, name: `C${i}`, category: i % 4 === 0 ? 'guri' : 'company' }));
  const counts = categoryCounts(list);
  assert.deepEqual(counts, { all: 100, company: 75, guri: 25 });
  assert.equal(filterByCategory(list, 'guri').length, 25);
});

test('the URL filter accepts only known categories, anything else means All', () => {
  assert.equal(parseCategoryFilter('category=guri'), 'guri');
  assert.equal(parseCategoryFilter('category=company'), 'company');
  assert.equal(parseCategoryFilter('category=all'), 'all');
  assert.equal(parseCategoryFilter(''), 'all');
  assert.equal(parseCategoryFilter('category=office'), 'all');
  assert.equal(parseCategoryFilter('category=GURI'), 'all');
  assert.equal(parseCategoryFilter(new URLSearchParams('category=guri')), 'guri');
});

test('the Fayadhowr Office location is recognised as internal, client locations are not', () => {
  assert.equal(isInternalOffice({ location_type: 'office' }), true);
  assert.equal(isInternalOffice({ location_type: 'client' }), false);
  assert.equal(isInternalOffice({}), false);
});

test('an alias that only repeats the name is not shown, and spellings are shown once', () => {
  assert.deepEqual(visibleAliases('5G HOTEL', [{ alias: '5G HOTEL' }, { alias: '5GHOTEL' }, { alias: '5ghotel' }, { alias: '5G Hotel (old)' }]), ['5GHOTEL', '5G Hotel (old)']);
  assert.deepEqual(visibleAliases('Alpha', undefined), []);
});
