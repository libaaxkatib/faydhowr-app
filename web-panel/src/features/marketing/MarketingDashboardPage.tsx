import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';

export function MarketingDashboardPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['marketing-dashboard'], queryFn: marketingApi.dashboard });

  return (
    <div>
      <PageHeader title="Marketing Dashboard" breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Dashboard' }]} />

      {isLoading && <LoadingState label="Loading marketing dashboard…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Today</div>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <KpiCard label="Today's Follow-ups" value={data.todays_follow_ups} icon="calendar" color="bg-primary" />
            <KpiCard label="New Leads Today" value={data.new_leads_today} icon="plus" color="bg-secondary" />
            <KpiCard label="Overdue Follow-ups" value={data.overdue_follow_ups} icon="alert-triangle" color="bg-danger" />
            <KpiCard label="In Quotation" value={data.quotation} icon="file-text" color="bg-warning" />
          </div>

          <div className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Overview</div>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <KpiCard label="Total Records" value={data.total_records} icon="megaphone" color="bg-primary" />
            <KpiCard label="XARUN" value={data.total_xarun} icon="megaphone" color="bg-secondary" />
            <KpiCard label="PROJECT" value={data.total_project} icon="briefcase" color="bg-purple-500" />
            <KpiCard label="Pending" value={data.pending} icon="calendar" color="bg-warning" />
            <KpiCard label="Done" value={data.done} icon="check" color="bg-success" />
            <KpiCard label="Cancelled" value={data.cancelled} icon="x" color="bg-danger" />
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
}: {
  label: string;
  value: number;
  icon: 'calendar' | 'plus' | 'alert-triangle' | 'file-text' | 'megaphone' | 'briefcase' | 'check' | 'x';
  color: string;
}) {
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
