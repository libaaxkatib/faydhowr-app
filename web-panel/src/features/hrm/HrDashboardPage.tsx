import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';

export function HrDashboardPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['hr-dashboard'], queryFn: hrApi.dashboard });

  return (
    <div>
      <PageHeader title="HR Dashboard" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Dashboard' }]} />

      {isLoading && <LoadingState label="Loading HR dashboard…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <KpiCard label="Total Employees" value={data.total_employees} icon="users" color="bg-primary" />
            <KpiCard label="Active" value={data.active_employees} icon="check" color="bg-success" />
            <KpiCard label="Inactive" value={data.inactive_employees} icon="x" color="bg-danger" />
            <KpiCard label="New Applicants (30d)" value={data.new_applicants} icon="plus" color="bg-secondary" />
            <KpiCard label="Practical" value={data.practical} icon="file-text" color="bg-purple-500" />
            <KpiCard label="Waiting" value={data.waiting} icon="calendar" color="bg-warning" />
            <KpiCard label="Approved" value={data.approved} icon="shield" color="bg-teal-600" />
          </div>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Category Breakdown</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              {data.category_breakdown.map((category) => (
                <div key={category.id} className="rounded-md border border-border p-3 text-center">
                  <p className="font-display text-lg font-bold text-ink">{formatNumber(category.total)}</p>
                  <p className="text-xs text-ink-muted">{category.name}</p>
                </div>
              ))}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}

function KpiCard({ label, value, icon, color }: { label: string; value: number; icon: 'users' | 'check' | 'x' | 'plus' | 'file-text' | 'calendar' | 'shield'; color: string }) {
  return (
    <Card className="p-5">
      <div className="flex items-center gap-3">
        <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-white ${color}`}>
          <Icon name={icon} size={19} />
        </div>
        <div className="min-w-0">
          <p className="truncate text-xs font-medium text-ink-muted">{label}</p>
          <p className="font-display text-xl font-bold text-ink">{formatNumber(value)}</p>
        </div>
      </div>
    </Card>
  );
}
