import { BarList, type BarItem } from '@/components/charts/BarList';
import { ChartCard } from '@/components/charts/ChartCard';
import { KpiTile } from '@/components/charts/KpiTile';
import { DonutChart, ShareBar, type ShareItem } from '@/components/charts/ShareChart';
import { useI18n } from '@/i18n/useI18n';
import { formatNumber } from '@/utils/formatters';
import { drawable, titleCase } from '@/utils/chartMath';
import type { ChartTone } from '@/utils/chartMath';
import type { MarketingReportsSummary } from '@/types/marketing';

/** A record status keeps its meaning in every chart: done is green, cancelled red, pending amber; quotation takes the next colour in turn. */
const RECORD_STATUS_ORDER = ['pending', 'quotation', 'done', 'cancelled'];
const RECORD_STATUS_TONE: Record<string, ChartTone | undefined> = { pending: 'warning', done: 'success', cancelled: 'danger' };
const FOLLOW_UP_TONE: Record<string, ChartTone | undefined> = { completed: 'success', rescheduled: 'warning' };

const byOrder = (order: readonly string[]) => (a: string, b: string) => (order.indexOf(a) === -1 ? 99 : order.indexOf(a)) - (order.indexOf(b) === -1 ? 99 : order.indexOf(b));

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mt-6">
      <h2 className="mb-3 font-display text-xs font-bold uppercase tracking-wide text-ink-faint">{title}</h2>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">{children}</div>
    </section>
  );
}

/**
 * The visual Marketing report: headline figures, the records by status and by type (XARUN and PROJECT side by side), the follow-ups of those records
 * and who has how much open work. All figures come from the report summary for the chosen period and filters, with the same team scope as before:
 * a team member only ever sees their own teams' numbers. A chart without data says so instead of drawing an empty frame.
 */
export function MarketingReportCharts({ data }: { data: MarketingReportsSummary }) {
  const { t, tValue } = useI18n();
  const empty = t('option.reports.chartEmpty');

  const statusLabel = (status: string) => tValue('status.marketing.record', status) ?? titleCase(status);
  const typeLabel = (type: string) => (type === 'xarun' ? 'XARUN' : 'PROJECT');
  const toShare = (counts: Partial<Record<string, number>>, order: readonly string[], label: (key: string) => string, tones: Record<string, ChartTone | undefined>): ShareItem[] =>
    Object.keys(counts)
      .sort(byOrder(order))
      .map((key) => ({ key, label: label(key), value: counts[key] ?? 0, tone: tones[key] }));

  const statusItems = toShare(data.status_breakdown, RECORD_STATUS_ORDER, statusLabel, RECORD_STATUS_TONE);
  const typeItems: ShareItem[] = Object.keys(data.type_breakdown).map((type) => ({ key: type, label: typeLabel(type), value: data.type_breakdown[type as keyof typeof data.type_breakdown] ?? 0 }));
  const typeStatus: Record<string, Partial<Record<string, number>>> = data.type_status_breakdown ?? {};
  const followUps = data.follow_up_summary;

  const teamItems: BarItem[] = data.team_breakdown.map((team) => ({ key: team.id, label: team.name, value: team.total }));
  const employeeItems: BarItem[] = [...data.employee_breakdown].sort((a, b) => b.total - a.total).map((employee) => ({ key: employee.admin_id, label: employee.admin_name ?? '—', value: employee.total }));

  const workloadItems: BarItem[] = (followUps?.workload ?? []).map((row, index) => ({
    key: row.admin_id ?? `none-${index}`,
    label: row.admin_name ?? t('option.marketing.workloadUnassigned'),
    value: row.open,
    tone: row.overdue > 0 ? 'warning' : undefined,
    hint: row.overdue > 0 ? t('option.marketing.workloadOverdue', { count: row.overdue }) : undefined,
  }));

  return (
    <div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile label={t('option.marketing.kpiTotal')} value={formatNumber(data.total_records)} hint={`${data.range.from} – ${data.range.to}`} icon="megaphone" />
        {followUps && (
          <>
            <KpiTile label={t('option.marketing.kpiOpenFollowUps')} value={formatNumber(followUps.open)} hint={t('option.marketing.kpiOpenFollowUpsHint')} icon="calendar" />
            <KpiTile label={t('option.marketing.kpiDueToday')} value={formatNumber(followUps.due_today)} icon="bell" tone={followUps.due_today > 0 ? 'warning' : 'default'} />
            <KpiTile label={t('option.marketing.kpiOverdue')} value={formatNumber(followUps.overdue)} icon="alert-triangle" tone={followUps.overdue > 0 ? 'danger' : 'success'} />
          </>
        )}
      </div>

      <Section title={t('option.marketing.sectionRecords')}>
        <ChartCard title={t('option.marketing.byStatus')} empty={statusItems.length === 0} emptyText={empty}>
          <DonutChart items={statusItems} label={t('option.marketing.byStatus')} centerLabel={t('option.reports.totalLabel')} />
        </ChartCard>
        <ChartCard title={t('option.marketing.typeByStatus')} note={t('option.marketing.typeByStatusNote')} empty={typeItems.length === 0} emptyText={empty}>
          <div className="space-y-5">
            {Object.keys(typeStatus)
              .sort()
              .reverse()
              .map((type) => {
                const items = toShare(typeStatus[type] ?? {}, RECORD_STATUS_ORDER, statusLabel, RECORD_STATUS_TONE);
                const total = items.reduce((sum, item) => sum + drawable(item.value), 0);

                return (
                  <div key={type}>
                    <p className="mb-2 text-sm font-semibold text-ink">
                      {typeLabel(type)} <span className="font-normal text-ink-muted">· {formatNumber(total)}</span>
                    </p>
                    <ShareBar items={items} label={`${typeLabel(type)} — ${t('option.marketing.byStatus')}`} />
                  </div>
                );
              })}
          </div>
        </ChartCard>
        <ChartCard title={t('option.marketing.byType')} empty={typeItems.length === 0} emptyText={empty}>
          <ShareBar items={typeItems} label={t('option.marketing.byType')} />
        </ChartCard>
        <ChartCard title={t('option.marketing.recordsPerTeam')} empty={teamItems.length === 0} emptyText={empty}>
          <BarList items={teamItems} label={t('option.marketing.recordsPerTeam')} colorByPosition />
        </ChartCard>
        <ChartCard title={t('option.marketing.recordsPerPerson')} empty={employeeItems.length === 0} emptyText={t('warning.marketing.noEmployeeRecords')} className="lg:col-span-2">
          <BarList items={employeeItems} label={t('option.marketing.recordsPerPerson')} />
        </ChartCard>
      </Section>

      {followUps && (
        <Section title={t('nav.marketing.followUps')}>
          <ChartCard
            title={t('option.marketing.followUpStatusTitle')}
            note={t('option.marketing.followUpStatusNote')}
            empty={Object.values(followUps.by_status).every((count) => drawable(count) === 0)}
            emptyText={empty}
          >
            <DonutChart
              items={toShare(followUps.by_status, ['scheduled', 'rescheduled', 'completed'], (status) => tValue('status.marketing.followUp', status) ?? titleCase(status), FOLLOW_UP_TONE)}
              label={t('option.marketing.followUpStatusTitle')}
              centerLabel={t('option.reports.totalLabel')}
            />
          </ChartCard>
          <ChartCard title={t('option.marketing.workloadTitle')} empty={workloadItems.length === 0} emptyText={t('option.marketing.workloadEmpty')}>
            <BarList items={workloadItems} label={t('option.marketing.workloadTitle')} />
          </ChartCard>
        </Section>
      )}
    </div>
  );
}
