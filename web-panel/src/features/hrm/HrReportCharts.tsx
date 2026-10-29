import { Link } from 'react-router-dom';

import { BarList, type BarItem } from '@/components/charts/BarList';
import { ChartCard } from '@/components/charts/ChartCard';
import { KpiTile } from '@/components/charts/KpiTile';
import { DonutChart, ShareBar, type ShareItem } from '@/components/charts/ShareChart';
import { useI18n } from '@/i18n/useI18n';
import { formatNumber } from '@/utils/formatters';
import { drawable, moneyNumber } from '@/utils/chartMath';
import type { HrReportsSummary } from '@/types/employee';

/** The order people move through, so the same status keeps the same colour from one report to the next. */
const STATUS_ORDER = ['applicant', 'recruitment', 'practical', 'waiting', 'approved', 'active', 'inactive'];

/** Each status keeps one colour with a meaning: active is green, waiting amber, inactive grey; the steps before them take distinct, non-red colours. */
const STATUS_LOOK: Record<string, Pick<ShareItem, 'tone' | 'colorIndex'>> = {
  applicant: { colorIndex: 0 },
  recruitment: { colorIndex: 1 },
  practical: { colorIndex: 4 },
  approved: { colorIndex: 6 },
  waiting: { tone: 'warning' },
  active: { tone: 'success' },
  inactive: { tone: 'neutral' },
};

/**
 * How a stored value is written for the person looking at the report. The HR Reports page builds these (it holds the approved Somali and English wording of
 * statuses, stages, genders, attendance, ratings and separation reasons); the charts only place the words they are given.
 */
export interface HrReportLabels {
  status: (value: string) => string;
  stage: (value: string) => string;
  gender: (value: string) => string;
  attendance: (value: string) => string;
  rating: (value: string) => string;
  separation: (value: string) => string;
}

const sum = (values: readonly number[]) => values.reduce((total, value) => total + drawable(value), 0);

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mt-6">
      <h2 className="mb-3 font-display text-xs font-bold uppercase tracking-wide text-ink-faint">{title}</h2>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">{children}</div>
    </section>
  );
}

/**
 * The visual HR report: headline figures first, then the same breakdowns the report always had, drawn as bars, rings and progress bars. Every figure
 * comes from the report summary the server returns for the chosen period and filters; nothing is estimated here. The pay sections only exist when the
 * server sent them (callers without pay access never receive them), and a chart without data says so instead of drawing an empty frame.
 */
export function HrReportCharts({ data, labels }: { data: HrReportsSummary; labels: HrReportLabels }) {
  const { t } = useI18n();

  const statusLabel = labels.status;
  const statusKeys = [...Object.keys(data.status_breakdown)].sort((a, b) => {
    const ia = STATUS_ORDER.indexOf(a);
    const ib = STATUS_ORDER.indexOf(b);

    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
  });
  const statusItems: ShareItem[] = statusKeys.map((status) => ({ key: status, label: statusLabel(status), value: data.status_breakdown[status as keyof typeof data.status_breakdown] ?? 0, ...STATUS_LOOK[status] }));
  const people = sum(statusItems.map((item) => item.value));
  const active = data.status_breakdown.active ?? 0;
  const waiting = data.status_breakdown.waiting ?? 0;

  const pipelineItems: BarItem[] = Object.entries(data.pipeline_breakdown).map(([stage, total]) => ({ key: stage, label: labels.stage(stage), value: total ?? 0 }));
  const genderItems: ShareItem[] = Object.entries(data.active_by_gender).map(([gender, total]) => ({ key: gender, label: labels.gender(gender), value: total ?? 0 }));

  const workplaceItems: BarItem[] = data.workplace_breakdown.map((place) => {
    const hasCapacity = place.capacity !== null && place.capacity > 0;

    return {
      key: place.id,
      label: place.location_type === 'office' ? place.name : `${place.client_company_name} · ${place.name}`,
      value: place.total,
      valueText: place.capacity !== null ? `${formatNumber(place.total)} / ${formatNumber(place.capacity)}` : formatNumber(place.total),
      // With a capacity the bar is the share of its places that are filled (and turns red when it is over capacity); without one it is just the head count.
      max: hasCapacity ? place.capacity! : undefined,
      tone: hasCapacity && place.total > place.capacity! ? 'danger' : undefined,
      hint: hasCapacity ? t('option.reports.capacityUsed', { count: place.total, capacity: place.capacity! }) : undefined,
    };
  });
  if (data.assignments_without_workplace > 0) {
    workplaceItems.push({ key: 'no-workplace', label: t('option.reports.noWorkplaceYet'), value: data.assignments_without_workplace, tone: 'warning' });
  }

  const money = <T extends { id: number; name: string }>(rows: readonly T[], total: (row: T) => string, count: (row: T) => number): BarItem[] =>
    rows.map((row) => ({ key: row.id, label: row.name, value: moneyNumber(total(row)), valueText: `${total(row)} (${formatNumber(count(row))})` }));

  const hasPay =
    data.company_salary_totals !== undefined ||
    data.payment_totals_by_department !== undefined ||
    data.penalty_totals_by_department !== undefined ||
    data.advance_totals_by_department !== undefined ||
    data.replacement_totals_by_company !== undefined;

  const wait = data.waiting_duration_buckets;
  const waitingItems: BarItem[] = [
    { key: 'u7', label: t('option.reports.waitingUnder7'), value: wait.under_7_days, tone: 'success' },
    { key: '7', label: t('option.reports.waiting7to30'), value: wait.seven_to_30_days },
    { key: '30', label: t('option.reports.waiting30to90'), value: wait.thirty_to_90_days, tone: 'warning' },
    { key: '90', label: t('option.reports.waitingOver90'), value: wait.over_90_days, tone: 'danger' },
    { key: 'na', label: t('option.reports.waitingUnavailable'), value: wait.unavailable, tone: 'neutral' },
  ];

  const progress = data.workforce_requests_progress;
  const progressItems: BarItem[] = progress.map((request) => ({
    key: request.id,
    label: t('option.reports.requestWhere', { name: request.work_location_name ?? t('option.reports.requestNoPlace') }),
    value: request.matched_count,
    max: request.quantity_needed,
    valueText: t('option.reports.requestMatched', { matched: request.matched_count, needed: request.quantity_needed }),
    tone: request.quantity_needed > 0 && request.matched_count >= request.quantity_needed ? 'success' : undefined,
  }));

  const attendanceItems: BarItem[] = Object.entries(data.attendance_breakdown).map(([status, total]) => ({ key: status, label: labels.attendance(status), value: total ?? 0 }));
  const reviewItems: BarItem[] = Object.entries(data.reviews_by_rating).map(([rating, total]) => ({ key: rating, label: labels.rating(rating), value: total ?? 0 }));
  const separationItems: BarItem[] = Object.entries(data.separation_reason_breakdown).map(([reason, total]) => ({ key: reason, label: labels.separation(reason), value: total ?? 0 }));
  if (data.rehire_count_in_range > 0) separationItems.push({ key: 'rehired', label: t('option.reports.rehiredInRange'), value: data.rehire_count_in_range, tone: 'success' });

  const empty = t('option.reports.chartEmpty');

  return (
    <div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <KpiTile label={t('option.reports.kpiRegistrations')} value={formatNumber(data.registrations_in_range)} hint={`${data.range.from} – ${data.range.to}`} icon="plus" />
        <KpiTile label={t('option.reports.kpiPeople')} value={formatNumber(people)} hint={t('option.reports.kpiPeopleHint')} icon="users" />
        <KpiTile label={t('option.reports.kpiActive')} value={formatNumber(active)} icon="check" tone="success" />
        <KpiTile label={t('option.reports.kpiWaiting')} value={formatNumber(waiting)} icon="calendar" />
        <KpiTile
          label={t('option.reports.noWorkplaceYet')}
          value={formatNumber(data.assignments_without_workplace)}
          hint={data.assignments_without_workplace > 0 ? t('option.reports.kpiNeedsAttention') : undefined}
          icon="map-pin"
          tone={data.assignments_without_workplace > 0 ? 'warning' : 'default'}
        />
      </div>

      <Section title={t('option.reports.sectionPeople')}>
        <ChartCard title={t('option.reports.peopleByStatus')} empty={people === 0} emptyText={empty}>
          <DonutChart items={statusItems} label={t('option.reports.peopleByStatus')} centerLabel={t('option.reports.totalLabel')} />
        </ChartCard>
        <ChartCard title={t('option.reports.pipelineBreakdown')} empty={pipelineItems.length === 0} emptyText={t('option.reports.pipelineEmpty')}>
          <BarList items={pipelineItems} label={t('option.reports.pipelineBreakdown')} />
        </ChartCard>
        <ChartCard title={t('option.reports.activeByGenderChart')} empty={genderItems.length === 0} emptyText={t('option.reports.activeByGenderEmpty')}>
          <ShareBar items={genderItems} label={t('option.reports.activeByGenderChart')} />
        </ChartCard>
        <ChartCard title={t('option.reports.byCategory')} empty={data.category_breakdown.length === 0} emptyText={empty}>
          <BarList items={data.category_breakdown.map((category) => ({ key: category.id, label: category.name, value: category.total }))} label={t('option.reports.byCategory')} />
        </ChartCard>
        <ChartCard title={t('option.reports.byDepartment')} empty={data.department_breakdown.length === 0} emptyText={t('option.reports.departmentsEmpty')} className="lg:col-span-2">
          <BarList items={data.department_breakdown.map((department) => ({ key: department.id, label: department.name, value: department.total }))} label={t('option.reports.byDepartment')} />
        </ChartCard>
      </Section>

      <Section title={t('option.reports.sectionWorkplaces')}>
        <ChartCard title={t('option.reports.byWorkplace')} empty={workplaceItems.length === 0} emptyText={empty} className="lg:col-span-2">
          <BarList items={workplaceItems} label={t('option.reports.byWorkplace')} limit={10} />
        </ChartCard>
        <ChartCard title={t('option.reports.requestsByCompany')} empty={data.workforce_requests_by_company.length === 0} emptyText={t('option.reports.requestsEmpty')}>
          <BarList items={data.workforce_requests_by_company.map((company) => ({ key: company.name, label: company.name, value: company.total }))} label={t('option.reports.requestsByCompany')} />
        </ChartCard>
      </Section>

      {hasPay && (
        <Section title={t('option.reports.sectionPay')}>
          {data.company_salary_totals !== undefined && (
            <ChartCard title={t('option.reports.salaryByCompany')} empty={data.company_salary_totals.length === 0} emptyText={t('option.reports.salaryByCompanyEmpty')}>
              <BarList items={money(data.company_salary_totals, (r) => r.total_salary, (r) => r.total_assignments)} label={t('option.reports.salaryByCompany')} />
            </ChartCard>
          )}
          {data.payment_totals_by_department !== undefined && (
            <ChartCard title={t('option.reports.paymentsByDepartment')} empty={data.payment_totals_by_department.length === 0} emptyText={empty}>
              <BarList items={money(data.payment_totals_by_department, (r) => r.total_paid, (r) => r.total_payments)} label={t('option.reports.paymentsByDepartment')} />
            </ChartCard>
          )}
          {data.penalty_totals_by_department !== undefined && (
            <ChartCard title={t('option.reports.penaltiesByDepartment')} empty={data.penalty_totals_by_department.length === 0} emptyText={t('option.reports.penaltiesEmpty')}>
              <BarList items={money(data.penalty_totals_by_department, (r) => r.total_deducted, (r) => r.total_penalties)} label={t('option.reports.penaltiesByDepartment')} fillClass="bg-chart-6" />
            </ChartCard>
          )}
          {data.advance_totals_by_department !== undefined && (
            <ChartCard title={t('option.reports.advancesByDepartment')} empty={data.advance_totals_by_department.length === 0} emptyText={t('option.reports.advancesEmpty')}>
              <BarList items={money(data.advance_totals_by_department, (r) => r.total_advanced, (r) => r.total_advances)} label={t('option.reports.advancesByDepartment')} fillClass="bg-chart-3" />
            </ChartCard>
          )}
          {data.replacement_totals_by_company !== undefined && (
            <ChartCard title={t('option.reports.replacementsByCompany')} empty={data.replacement_totals_by_company.length === 0} emptyText={t('option.reports.replacementsEmpty')}>
              <BarList
                items={data.replacement_totals_by_company.map((company) => ({ key: company.name, label: company.name, value: moneyNumber(company.total_paid), valueText: `${company.total_paid} (${formatNumber(company.total_replacements)})` }))}
                label={t('option.reports.replacementsByCompany')}
                fillClass="bg-chart-5"
              />
            </ChartCard>
          )}
        </Section>
      )}

      <Section title={t('option.reports.sectionWaiting')}>
        <ChartCard title={t('option.reports.waitingDuration')} empty={sum(waitingItems.map((item) => item.value)) === 0} emptyText={empty}>
          <BarList items={waitingItems} label={t('option.reports.waitingDuration')} />
        </ChartCard>
        <ChartCard title={t('option.reports.waitingByCategory')} empty={data.waiting_by_category.length === 0} emptyText={t('option.reports.waitingByCategoryEmpty')}>
          <BarList items={data.waiting_by_category.map((category) => ({ key: category.id, label: category.name, value: category.total }))} label={t('option.reports.waitingByCategory')} />
        </ChartCard>
        <ChartCard title={t('option.reports.requestProgress')} empty={progressItems.length === 0} emptyText={t('option.reports.requestsEmpty')}>
          <BarList items={progressItems} label={t('option.reports.requestProgress')} limit={6} />
          <Link to="/hr/workforce-requests" className="mt-3 inline-block text-xs font-semibold text-primary hover:underline">
            {t('option.reports.requestsLink')}
          </Link>
        </ChartCard>
      </Section>

      <Section title={t('option.reports.sectionPeopleMovements')}>
        <ChartCard title={t('option.reports.attendanceBreakdown')} empty={attendanceItems.length === 0} emptyText={t('option.reports.attendanceEmpty')}>
          <BarList items={attendanceItems} label={t('option.reports.attendanceBreakdown')} colorByPosition />
        </ChartCard>
        <ChartCard title={t('option.reports.reviewsByRating')} empty={reviewItems.length === 0} emptyText={t('option.reports.reviewsEmpty')}>
          <BarList items={reviewItems} label={t('option.reports.reviewsByRating')} colorByPosition />
        </ChartCard>
        <ChartCard title={t('option.reports.separationsRehires')} empty={separationItems.length === 0} emptyText={t('option.reports.separationsEmpty')}>
          <BarList items={separationItems} label={t('option.reports.separationsRehires')} />
        </ChartCard>
        <ChartCard title={t('option.reports.supervisorOffice')} empty={data.supervisors_since_range === 0 && data.office_staff_by_department.length === 0} emptyText={empty}>
          <BarList
            items={[
              { key: 'supervisors', label: t('option.reports.nominatedSupervisors'), value: data.supervisors_since_range },
              ...data.office_staff_by_department.map((department) => ({ key: `office-${department.id}`, label: t('option.reports.officeDepartment', { name: department.name }), value: department.total })),
            ]}
            label={t('option.reports.supervisorOffice')}
          />
        </ChartCard>
      </Section>
    </div>
  );
}
