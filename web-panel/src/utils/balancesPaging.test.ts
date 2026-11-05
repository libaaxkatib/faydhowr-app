import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

import { balanceTotalsView, parseBalanceFilters } from './deductions.ts';
import { payrollRelease1b } from '../i18n/messages/payrollRelease1b.ts';

// The Outstanding balances list is paged on the server (owner decision, 2026-11-05): every record is reachable, the count is the real one, and a truncated
// answer is said so. The screen holds the page in the address bar like every other list and still sends the company / kind / leaver filters.
const page = readFileSync(new URL('../features/hrm/OutstandingBalancesPage.tsx', import.meta.url), 'utf8');
const api = readFileSync(new URL('../api/hr.ts', import.meta.url), 'utf8');

test('the page asks the server for one page at a time and keeps the page in the address bar', () => {
  assert.match(page, /const PAGE_SIZE = 50;/);
  assert.match(page, /queryKey: \['deduction-balances', filters, url\.page\]/);
  assert.match(page, /hrApi\.payrollRuns\.deductionBalances\(filters, url\.page, PAGE_SIZE\)/);
  assert.match(page, /<Pagination meta=\{meta\} onPageChange=\{url\.setPage\} \/>/);
});

test('the client sends page and per_page together with the filters and returns the real meta', () => {
  assert.match(api, /deductionBalances: async \(filters: DeductionBalanceFilters, page = 1, perPage = 50\)/);
  assert.match(api, /leavers_only: filters\.leavers_only \? 1 : undefined,\s+page,\s+per_page: perPage,/);
  assert.match(api, /return \{ data, meta: meta as DeductionBalanceMeta \}/);
});

test('a truncated answer is shown, not hidden, and the message is a proposed English + Somali pair', () => {
  assert.match(page, /meta\?\.truncated && \(\s*<p role="alert"[^>]*data-testid="balances-truncated">\{t\('warning\.deduction\.truncated'\)\}/);
  const message = payrollRelease1b['warning.deduction.truncated'];
  assert.ok(message.en.length > 20 && message.so.length > 20);
  assert.equal(message.review, 'proposed');
});

test('the filters still work: company, kind and leavers are read from the address bar, and the totals come from the whole filtered set', () => {
  const get = (values: Record<string, string>) => (key: string) => values[key] ?? '';
  assert.deepEqual(parseBalanceFilters(get({ company: '7', kind: 'advance', leavers: '1' })), { client_company_id: 7, kind: 'advance', leavers_only: true });
  assert.deepEqual(parseBalanceFilters(get({})), {});
  // The count shown is the server's total for the filtered set (not the number of rows on the page).
  const totals = balanceTotalsView({ totals: { count: 2100, owed_cents: 210000, leaver_count: 25, leaver_owed_cents: 2500 } });
  assert.equal(totals.count, 2100);
  assert.equal(totals.leaverCount, 25);
  assert.match(page, /<ListResultBar matching=\{totals\.count\}/);
});
