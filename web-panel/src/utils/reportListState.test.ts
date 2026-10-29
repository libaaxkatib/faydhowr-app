import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Wiring guards for the report list pages that keep their filters, date range and page in the address bar (2026-10-29). The behaviour itself (Back, reload,
// reset, empty result) is checked in the browser QA; these stop the pages from quietly going back to component state.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

const PAGES = [
  'LeaveReportPage',
  'PerformanceReportPage',
  'FinancialLedgerPage',
  'WorkforceRequestHistoryPage',
  'TemporaryReplacementHistoryPage',
];

for (const name of PAGES) {
  test(`${name}: filters and page live in the address bar, with reset, a result count and a no-match state`, () => {
    const page = read(`../features/hrm/${name}.tsx`);

    assert.ok(page.includes('useUrlFilters()'), 'state is in the address bar');
    assert.doesNotMatch(page, /const \[page, setPage\] = useState/, 'the page number is not component state');
    assert.doesNotMatch(page, /useState<[^>]*>\(''\)|useState\(''\)/, 'no filter is component state');
    assert.ok(page.includes('<ResetFiltersButton'), 'a reset button');
    assert.ok(page.includes('<ListResultBar matching={data.meta.total} filtered={filtered} />'), 'a result count');
    assert.ok(page.includes("filtered ? t('warning.common.noMatchTitle')"), 'an empty result caused by filters is told apart from "no records"');
    assert.ok(page.includes('emptyAction={filtered ?'), 'and offers the way back');
  });
}

test('the date range of the report pages is read from and written to the address bar', () => {
  const hook = read('../hooks/useDateRangePreset.ts');
  assert.ok(hook.includes('useUrlParamUpdater()'));
  assert.doesNotMatch(hook, /useState/, 'no component state');
  for (const name of ['LeaveReportPage', 'PerformanceReportPage', 'FinancialLedgerPage', 'TemporaryReplacementHistoryPage']) {
    assert.ok(read(`../features/hrm/${name}.tsx`).includes('...DATE_RANGE_KEYS'), `${name}: reset also clears the date range`);
  }
});

// Regression (2026-10-29): react-router's functional setSearchParams gives every updater the parameters of the render the event started in, so a handler that
// changed a filter and then called setPage(1) lost the filter - the search box would not take typing. Every change goes through the composing updater.
test('address-bar changes compose: the hooks never call react-router\'s functional setSearchParams directly', () => {
  const updater = read('../hooks/useUrlParamUpdater.ts');
  assert.ok(updater.includes('pending = applyChange(rendered.current, pending, change)'), 'each change starts from the result of the one before it, across hook instances');
  assert.ok(updater.includes('pending = null'), 'and forgets it once the router has rendered the new address');
  assert.ok(updater.includes('setParams(pending.next, { replace: true })'), 'it passes a value, not a function of stale parameters');
  for (const hook of ['useUrlFilters', 'useDateRangePreset']) {
    const source = read(`../hooks/${hook}.ts`);
    assert.ok(source.includes('useUrlParamUpdater()'), hook);
    assert.doesNotMatch(source, /setParams\(\(/, `${hook} does not use the functional form`);
    assert.doesNotMatch(source, /useSearchParams/, `${hook} does not read the router parameters directly`);
  }
});

test('the reports keep their filter fields named for screen readers', () => {
  for (const name of ['LeaveReportPage', 'PerformanceReportPage', 'FinancialLedgerPage', 'TemporaryReplacementHistoryPage']) {
    const page = read(`../features/hrm/${name}.tsx`);
    assert.ok(page.includes("aria-label={t('option.reports.dateFrom')}") && page.includes("aria-label={t('option.reports.dateTo')}"), name);
  }
});
