import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';

export function MarketingReportsPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-reports-summary', { from, to }],
    queryFn: () => marketingApi.reportsSummary({ from: from || undefined, to: to || undefined }),
  });

  return (
    <div>
      <PageHeader
        title="Marketing Reports"
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Reports' }]}
        actions={
          <div className="flex items-center gap-2 text-sm">
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2" />
            <span className="text-ink-faint">to</span>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2" />
          </div>
        }
      />

      {isLoading && <LoadingState label="Loading report…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Total Records</h3>
            </CardHeader>
            <CardBody>
              <p className="font-display text-3xl font-bold text-ink">{formatNumber(data.total_records)}</p>
              <p className="mt-1 text-xs text-ink-muted">{data.range.from} – {data.range.to}</p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Type</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.type_breakdown).map(([type, total]) => (
                <div key={type} className="flex items-center justify-between text-sm">
                  <span className="capitalize text-ink-muted">{type}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Status</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.status_breakdown).map(([status, total]) => (
                <div key={status} className="flex items-center justify-between text-sm">
                  <span className="capitalize text-ink-muted">{status}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Team A / Team B / All Teams</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.team_breakdown.map((team) => (
                <div key={team.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{team.name}</span>
                  <span className="font-semibold text-ink">{formatNumber(team.total)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Individual Employee</h3>
            </CardHeader>
            <CardBody>
              {data.employee_breakdown.length === 0 ? (
                <p className="text-sm text-ink-muted">No records assigned to an employee in this range yet.</p>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {data.employee_breakdown.map((employee) => (
                    <div key={employee.admin_id} className="rounded-md border border-border p-3 text-center">
                      <p className="font-display text-lg font-bold text-ink">{formatNumber(employee.total)}</p>
                      <p className="text-xs text-ink-muted">{employee.admin_name}</p>
                    </div>
                  ))}
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}
