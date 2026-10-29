import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Wiring guards for the visual HR and Marketing reports (the drawing itself is checked in the browser QA). They make sure the charts keep a text
// alternative, that no section the reports always had was dropped while drawing them as charts, and that pay sections still only show when the server
// sent them.
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');
const hr = read('../features/hrm/HrReportCharts.tsx');
const marketing = read('../features/marketing/MarketingReportCharts.tsx');

test('every figure the HR report always showed is still on the page', () => {
  for (const field of [
    'registrations_in_range', 'status_breakdown', 'pipeline_breakdown', 'category_breakdown', 'department_breakdown', 'workplace_breakdown',
    'assignments_without_workplace', 'company_salary_totals', 'penalty_totals_by_department', 'advance_totals_by_department', 'waiting_duration_buckets',
    'waiting_by_category', 'workforce_requests_by_company', 'active_by_gender', 'replacement_totals_by_company', 'separation_reason_breakdown',
    'rehire_count_in_range', 'supervisors_since_range', 'office_staff_by_department', 'attendance_breakdown', 'reviews_by_rating',
  ]) {
    assert.ok(hr.includes(`data.${field}`), `${field} is still shown`);
  }
});

test('every figure the Marketing report always showed is still on the page', () => {
  for (const field of ['total_records', 'status_breakdown', 'type_breakdown', 'team_breakdown', 'employee_breakdown']) assert.ok(marketing.includes(`data.${field}`), `${field} is still shown`);
});

test('the pay sections of the HR report appear only when the server sent them (callers without pay access never receive them)', () => {
  for (const field of ['company_salary_totals', 'payment_totals_by_department', 'penalty_totals_by_department', 'advance_totals_by_department', 'replacement_totals_by_company']) {
    assert.ok(hr.includes(`data.${field} !== undefined`), `${field} is guarded`);
  }
  assert.ok(hr.includes('{hasPay && ('), 'the whole pay section is hidden when none of its parts was sent');
});

test('a chart without data says so instead of drawing an empty frame', () => {
  const card = read('../components/charts/ChartCard.tsx');
  assert.ok(card.includes('empty ? <p className="text-sm text-ink-muted">{emptyText}</p>'));
  assert.ok(hr.includes('empty=') && marketing.includes('empty='));
});

test('every chart has its figures written out as text, and the bars themselves are decoration', () => {
  const bars = read('../components/charts/BarList.tsx');
  assert.ok(bars.includes('<ul aria-label={label}'), 'the list is named');
  assert.ok(bars.includes('{item.valueText ?? formatNumber(item.value)}'), 'the figure is text beside the bar');
  assert.ok(bars.includes('aria-hidden="true"'), 'the drawn bar is hidden from screen readers (the text says it all)');

  const share = read('../components/charts/ShareChart.tsx');
  assert.ok(share.includes('role="img"') && share.includes('aria-label={chartSummary(label, parts)}'), 'rings and share bars have a spoken summary');
  assert.ok(share.includes('<Legend items={parts} total={total} label={label} />'), 'and a written key with every figure and percentage');
  assert.ok(share.includes('{percentOf(item.value, total)}%'), 'percentages are written, not only drawn');
});

test('colours come from theme variables, so Light and Dark both stay readable, and animation respects reduced motion', () => {
  const math = read('./chartMath.ts');
  assert.ok(math.includes("'bg-chart-1'") && math.includes("'stroke-chart-1'"), 'colours are theme classes, never colour values');
  assert.ok(read('../../tailwind.config.ts').includes("1: color('chart-1')"));
  const css = read('../index.css');
  assert.equal((css.match(/--color-chart-1:/g) ?? []).length, 2, 'a Light and a Dark value');
  assert.ok(read('../components/charts/BarList.tsx').includes('motion-safe:transition'));
});

test('the pages use the charts and keep their filters and permissions', () => {
  assert.ok(read('../features/hrm/HrReportsPage.tsx').includes('<HrReportCharts data={data} labels={labels} />'));
  assert.ok(read('../features/marketing/MarketingReportsPage.tsx').includes('<MarketingReportCharts data={data} />'));
  assert.ok(read('../features/hrm/HrReportsPage.tsx').includes('hrApi.reportsSummary('));
  assert.ok(read('../features/marketing/MarketingReportsPage.tsx').includes('marketingApi.reportsSummary('));
  const app = read('../App.tsx');
  assert.match(app, /RequirePermission permission="marketing\.reports\.view"/);
});

test('a team member sees follow-up figures only when the server sent them (older responses still render)', () => {
  assert.ok(marketing.includes('const followUps = data.follow_up_summary;'));
  assert.ok(marketing.includes('{followUps && ('));
});
