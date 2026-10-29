import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { useI18n } from '@/i18n/useI18n';
import { formatNumber } from '@/utils/formatters';

export function MarketingDashboardPage() {
  const { t } = useI18n();
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-dashboard'], queryFn: marketingApi.dashboard });

  return (
    <div>
      <PageHeader title={t('nav.marketing.dashboardTitle')} breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: 'Dashboard' }]} />

      {isLoading && <LoadingState label={t('option.marketing.loadingDashboard')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{t('option.marketing.today')}</div>
          <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard label={t('option.marketing.todaysFollowUps')} value={data.todays_follow_ups} icon="calendar" color="bg-primary" to="/marketing/follow-ups" />
            <KpiCard label={t('option.marketing.newLeadsToday')} value={data.new_leads_today} icon="plus" color="bg-secondary" />
            <KpiCard label={t('option.marketing.overdueFollowUps')} value={data.overdue_follow_ups} icon="alert-triangle" color="bg-danger" to="/marketing/follow-ups?tab=overdue" />
            <KpiCard label={t('option.marketing.inQuotation')} value={data.quotation} icon="file-text" color="bg-warning" />
          </div>

          <div className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{t('option.marketing.sectionOverview')}</div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiCard label={t('option.marketing.totalRecords')} value={data.total_records} icon="megaphone" color="bg-primary" />
            <KpiCard label="XARUN" value={data.total_xarun} icon="megaphone" color="bg-secondary" />
            <KpiCard label="PROJECT" value={data.total_project} icon="briefcase" color="bg-purple-500" />
            <KpiCard label={t('status.marketing.record.pending')} value={data.pending} icon="calendar" color="bg-warning" />
            <KpiCard label={t('status.marketing.record.done')} value={data.done} icon="check" color="bg-success" />
            <KpiCard label={t('status.marketing.record.cancelled')} value={data.cancelled} icon="x" color="bg-danger" />
          </div>
        </>
      )}
    </div>
  );
}

function KpiCard({
  label,
  value,
  icon,
  color,
  to,
}: {
  label: string;
  value: number;
  icon: 'calendar' | 'plus' | 'alert-triangle' | 'file-text' | 'megaphone' | 'briefcase' | 'check' | 'x';
  color: string;
  /** Makes the whole card a link to the list behind the number. */
  to?: string;
}) {
  const card = (
    <Card className={`p-5 ${to ? 'transition-colors hover:border-primary' : ''}`}>
      <div className="flex items-center gap-3">
        <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-white ${color}`}>
          <Icon name={icon} size={19} />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium leading-snug text-ink-muted">{label}</p>
          <p className="font-display text-xl font-bold text-ink">{formatNumber(value)}</p>
        </div>
        {to && <Icon name="chevron-right" size={16} />}
      </div>
    </Card>
  );

  return to ? (
    <Link to={to} className="block rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
      {card}
    </Link>
  ) : (
    card
  );
}
