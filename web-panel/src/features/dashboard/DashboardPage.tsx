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
import type { DashboardFilter } from '@/types/dashboard';

const FILTER_OPTIONS: { value: DashboardFilter | ''; label: string }[] = [
  { value: '', label: 'All time' },
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
];

export function DashboardPage() {
  const [filter, setFilter] = useState<DashboardFilter | ''>('');

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['dashboard', filter || 'all-time'],
    queryFn: () => dashboardApi.get(filter ? { filter } : undefined),
  });

  return (
    <div>
      <PageHeader
        title="Dashboard"
        breadcrumb={[{ label: 'Home', to: '/dashboard' }, { label: 'Dashboard' }]}
        actions={
          <>
            <Select
              value={filter}
              onChange={(event) => setFilter(event.target.value as DashboardFilter | '')}
              options={FILTER_OPTIONS.filter((option) => option.value !== '')}
              placeholder="All time"
              className="w-40"
            />
            <Button variant="outline" size="sm" onClick={() => refetch()} isLoading={isFetching}>
              <Icon name="refresh" size={14} />
              Refresh
            </Button>
          </>
        }
      />

      {isLoading && <LoadingState label="Loading dashboard…" />}
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
                <h3 className="font-display text-sm font-bold text-ink">Booking &amp; Order Overview</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="bar-chart"
                  title="Trend chart not available yet"
                  description="The dashboard API returns point-in-time totals only — no time-series endpoint exists yet to plot bookings vs. orders over the selected period."
                />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">Recent Activities</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="inbox"
                  title="No activity feed yet"
                  description="No admin-facing activity-feed endpoint exists on the dashboard yet."
                />
              </CardBody>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">Latest Orders</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="cart"
                  title="Use the Orders module"
                  description="A dedicated 'latest orders' widget isn't part of the dashboard API — see the Orders module in Mobile App Management for the full, real order list."
                />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">Sales Overview</h3>
              </CardHeader>
              <CardBody className="p-0">
                <EmptyState
                  icon="bar-chart"
                  title="No revenue breakdown yet"
                  description="The dashboard reports one total revenue figure — a services/products/bookings split isn't exposed by the API yet."
                />
              </CardBody>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
