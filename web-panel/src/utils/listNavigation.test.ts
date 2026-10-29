import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Wiring guards for the 2026-10-29 benchmark pass (the real behaviour is checked in the browser QA): numbered pages under every list, the Customers list
// keeping its search/filter/page in the address bar, and the Marketing dashboard follow-up numbers opening the list behind them.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('every list shares one Pagination that offers numbered pages and a go-to-page box', () => {
  const source = read('../components/ui/Pagination.tsx');
  assert.ok(source.includes('pageWindow(current_page, last_page)'));
  assert.ok(source.includes("aria-current={item === current_page ? 'page' : undefined}"), 'the current page is announced');
  assert.ok(source.includes('last_page > 7'), 'the go-to-page box appears once the page buttons stop showing every page');
  assert.ok(source.includes('<nav aria-label'), 'the strip is a labelled navigation region');
});

test('the Customers list keeps search, status and page in the address bar, and its detail page returns to it', () => {
  const list = read('../features/customers/CustomersListPage.tsx');
  assert.ok(list.includes('useUrlFilters()'));
  assert.doesNotMatch(list, /useState<CustomerStatus/, 'status is no longer local state');
  assert.doesNotMatch(list, /const \[page, setPage\]/, 'page is no longer local state');
  assert.ok(list.includes('returnState(window.location.pathname, window.location.search'));
  const detail = read('../features/customers/CustomerDetailPage.tsx');
  assert.ok(detail.includes("origin?.fromPath?.startsWith('/mobile-app/customers')"), 'only a customers address is trusted as the way back');
});

test('the Marketing dashboard follow-up numbers open the follow-ups list', () => {
  const source = read('../features/marketing/MarketingDashboardPage.tsx');
  assert.ok(source.includes('to="/marketing/follow-ups?tab=overdue"'));
  assert.match(source, /todays_follow_ups[^\n]*to="\/marketing\/follow-ups"/);
});
