import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';

const STATUS_LABELS: Record<string, string> = {
  applicant: 'Applicant',
  recruitment: 'Recruitment',
  practical: 'Practical',
  waiting: 'Waiting',
  approved: 'Approved',
  active: 'Active',
  inactive: 'Inactive',
};

export function HrReportsPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['hr-reports-summary', { from, to }],
    queryFn: () => hrApi.reportsSummary({ from: from || undefined, to: to || undefined }),
  });

  return (
    <div>
      <PageHeader
        title="HR Reports"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports' }]}
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
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Registrations</h3>
            </CardHeader>
            <CardBody>
              <p className="font-display text-3xl font-bold text-ink">{formatNumber(data.registrations_in_range)}</p>
              <p className="mt-1 text-xs text-ink-muted">
                {data.range.from} – {data.range.to}
              </p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Status</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.status_breakdown).map(([status, total]) => (
                <div key={status} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{STATUS_LABELS[status] ?? status}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Category</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.category_breakdown.map((category) => (
                <div key={category.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{category.name}</span>
                  <span className="font-semibold text-ink">{formatNumber(category.total)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card className="lg:col-span-3">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Department</h3>
            </CardHeader>
            <CardBody>
              {data.department_breakdown.length === 0 ? (
                <p className="text-sm text-ink-muted">No departments configured yet.</p>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {data.department_breakdown.map((department) => (
                    <div key={department.id} className="rounded-md border border-border p-3 text-center">
                      <p className="font-display text-lg font-bold text-ink">{formatNumber(department.total)}</p>
                      <p className="text-xs text-ink-muted">{department.name}</p>
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
