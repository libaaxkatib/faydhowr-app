import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { COMPANY_FILTER_KEYS, companyListQuery, hasActiveCompanyFilters, parseCompanyListFilters } from './clientCompanyFilter.ts';
import { activeFilterCount, withParam, withoutFilters } from './urlFilters.ts';

const params = (query: string) => new URLSearchParams(query);

test('the filters are read from the address bar, and an unknown value means "no filter"', () => {
  assert.deepEqual(parseCompanyListFilters(params('q=alpha&category=guri&status=inactive&workplaces=with')), { search: 'alpha', category: 'guri', status: 'inactive', workplaces: 'with' });
  assert.deepEqual(parseCompanyListFilters(params('category=hospital&status=deleted&workplaces=maybe')), { search: '', category: 'all', status: '', workplaces: '' });
  assert.deepEqual(parseCompanyListFilters(params('')), { search: '', category: 'all', status: '', workplaces: '' });
});

test('the search text has its spaces tidied but keeps what was typed', () => {
  assert.equal(parseCompanyListFilters(params('q=%20%20al%20%20pha')).search, 'al pha');
});

test('only the filters that are set are sent, together with the page, and they all apply at once', () => {
  const none = parseCompanyListFilters(params(''));
  assert.deepEqual(companyListQuery(none, 1, 20), { page: 1, per_page: 20 });

  const all = parseCompanyListFilters(params('q=%20alpha%20&category=company&status=active&workplaces=without'));
  assert.deepEqual(companyListQuery(all, 3, 20), { page: 3, per_page: 20, search: 'alpha', category: 'company', status: 'active', workplaces: 'without' });
});

test('an empty result is told apart from "there are no records" by whether any filter is active', () => {
  assert.equal(hasActiveCompanyFilters(parseCompanyListFilters(params(''))), false);
  assert.equal(hasActiveCompanyFilters(parseCompanyListFilters(params('q=%20%20'))), false, 'blank search is no filter');
  for (const query of ['q=x', 'category=guri', 'status=active', 'workplaces=with']) assert.equal(hasActiveCompanyFilters(parseCompanyListFilters(params(query))), true, query);
});

test('changing one filter keeps the others and goes back to page 1; the page change keeps every filter', () => {
  const start = params('q=alpha&category=guri&page=4');
  const changed = withParam(start, 'status', 'active');
  assert.equal(changed.get('q'), 'alpha');
  assert.equal(changed.get('category'), 'guri');
  assert.equal(changed.get('status'), 'active');
  assert.equal(changed.get('page'), null, 'a new filter returns to page 1');

  const paged = withParam(start, 'page', '5');
  assert.deepEqual([paged.get('q'), paged.get('category'), paged.get('page')], ['alpha', 'guri', '5']);
});

test('reset clears exactly the filters and the page, and counts only the filters that hold a value', () => {
  const current = params('q=alpha&category=guri&status=&page=3&other=keep');
  assert.equal(activeFilterCount(current, COMPANY_FILTER_KEYS), 2, 'an empty status and the page do not count');
  const cleared = withoutFilters(current, COMPANY_FILTER_KEYS);
  assert.equal(cleared.toString(), 'other=keep');
  assert.equal(activeFilterCount(cleared, COMPANY_FILTER_KEYS), 0);
});

test('the page asks the server for the list (not the whole list), keeps its state in the address bar and explains an empty result', () => {
  const page = readFileSync(new URL('../features/hrm/ClientCompaniesPage.tsx', import.meta.url), 'utf8');
  assert.ok(page.includes('hrApi.clientCompanies.listPage(query)'));
  assert.ok(!page.includes('filterByCategory('), 'the category is no longer filtered in the browser');
  assert.ok(page.includes('useUrlFilters()'));
  assert.ok(page.includes('<NoMatchState onReset={resetFilters} />'));
  assert.ok(page.includes('<Pagination meta={meta} onPageChange={url.setPage} />'));
  assert.ok(page.includes('<ResultCount matching={meta.total} total={meta.unfiltered_total} filtered={filtered} />'));
  assert.ok(page.includes('placeholderData: (previous) => previous'), 'no flash of an empty page while typing');
  assert.ok(page.includes('<MultiCompanyPolicyCard />'), 'the settings card is still there for the roles that may see it');
});
