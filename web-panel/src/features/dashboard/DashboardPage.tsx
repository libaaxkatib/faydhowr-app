import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { dashboardApi } from '@/api/dashboard';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { KpiCard } from '@/features/dashboard/KpiCard';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import type { DashboardFilter } from '@/types/dashboard';

const FILTER_OPTIONS: { value: DashboardFilter | ''; labelKey: MessageKey }[] = [
  { value: '', labelKey: 'option.shell.allTime' },
  { value: 'today', labelKey: 'option.common.preset.today' },
  { value: 'yesterday', labelKey: 'option.shell.yesterday' },
  { value: 'last_7_days', labelKey: 'option.shell.last7Days' },
  { value: 'last_30_days', labelKey: 'option.shell.last30Days' },
  { value: 'this_month', labelKey: 'option.shell.thisMonthLower' },
  { value: 'last_month', labelKey: 'option.shell.lastMonthLower' },
];

export function DashboardPage() {
  const { t } = useI18n();
  const [filter, setFilter] = useState<DashboardFilter | ''>('');

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['dashboard', filter || 'all-time'],
    queryFn: () => dashboardApi.get(filter ? { filter } : undefined),
  });

  return (
    <div>
      <PageHeader
        title="Dashboard"
        breadcrumb={[{ label: t('nav.shell.home'), to: '/dashboard' }, { label: 'Dashboard' }]}
        actions={
          <>
            <Select
              value={filter}
              onChange={(event) => setFilter(event.target.value as DashboardFilter | '')}
              options={FILTER_OPTIONS.filter((option) => option.value !== '').map((option) => ({ value: option.value, label: t(option.labelKey) }))}
              placeholder={t('option.shell.allTime')}
              className="w-40"
            />
            <Button variant="outline" size="sm" onClick={() => refetch()} isLoading={isFetching}>
              <Icon name="refresh" size={14} />
              {t('action.shell.refresh')}
            </Button>
          </>
        }
      />

      {isLoading && <LoadingState label={t('option.shell.loadingDashboard')} />}
      {error && !isLoading && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <KpiCard widget={data.widgets.customers} icon="users" iconClassName="bg-primary" />
            <KpiCard widget={data.widgets.bookings} icon="calendar" iconClassName="bg-secondary" />
            <KpiCard widget={data.widgets.quotations} icon="file-text" iconClassName="bg-purple-500" />
            <KpiCard widget={data.widgets.orders} icon="cart" iconClassName="bg-success" />
            <KpiCard widget={data.widgets.payments} icon="credit-card" iconClassName="bg-warning" />
            <KpiCard widget={data.widgets.revenue} icon="bar-chart" iconClassName="bg-teal-600" />
            <KpiCard widget={data.widgets.inventory} icon="box" iconClassName="bg-orange-500" />
          </div>

          <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">{t('option.shell.bookingOrderOverview')}</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="bar-chart"
                  title={t('warning.shell.trendTitle')}
                  description={t('warning.shell.trendText')}
                />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">{t('option.shell.recentActivities')}</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="inbox"
                  title={t('warning.shell.activityTitle')}
                  description={t('warning.shell.activityText')}
                />
              </CardBody>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">{t('option.shell.latestOrders')}</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="cart"
                  title={t('warning.shell.ordersTitle')}
                  description={t('warning.shell.ordersText')}
                />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">{t('option.shell.salesOverview')}</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="bar-chart"
                  title={t('warning.shell.revenueTitle')}
                  description={t('warning.shell.revenueText')}
                />
              </CardBody>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
