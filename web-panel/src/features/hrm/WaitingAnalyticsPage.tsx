import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatDate, formatDurationSince, formatNumber, initialsOf } from '@/utils/formatters';
import type { Employee } from '@/types/employee';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: aggregate Waiting analytics (duration
 * buckets, category/gender/location split from GetHrReportsSummaryAction)
 * plus a sortable-by-duration roster (ListWaitingRosterAction). Complements
 * WaitingQueuePage.tsx, which stays the per-employee search+browse view.
 */
export function WaitingAnalyticsPage() {
  const navigate = useNavigate();
  const [orderBy, setOrderBy] = useState<'oldest' | 'newest'>('oldest');
  const [page, setPage] = useState(1);

  const { data: summary, isLoading: summaryLoading, error: summaryError, refetch: refetchSummary } = useQuery({
    queryKey: ['hr-reports-summary', {}],
    queryFn: () => hrApi.reportsSummary(),
  });

  const { data: roster, isLoading: rosterLoading, error: rosterError, refetch: refetchRoster } = useQuery({
    queryKey: ['waiting-roster', { orderBy, page }],
    queryFn: () => hrApi.reports.waitingRoster({ order_by: orderBy, page, per_page: 15 }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<Employee, unknown>[]>(
    () => [
      {
        header: 'Employee',
        accessorKey: 'full_name',
        cell: ({ row }) => (
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
              {initialsOf(row.original.full_name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium text-ink">{row.original.full_name}</p>
              <p className="truncate text-xs text-ink-muted">{row.original.employee_number}</p>
            </div>
          </div>
        ),
      },
      {
        header: 'Category',
        accessorKey: 'employee_category_name',
        cell: ({ row }) => <span className="text-ink">{row.original.employee_category_name ?? '—'}</span>,
      },
      {
        header: 'Waiting Duration',
        accessorKey: 'waiting_since',
        cell: ({ row }) =>
          row.original.waiting_since ? (
            <span className="text-ink">{formatDurationSince(row.original.waiting_since)}</span>
          ) : (
            <span className="text-xs italic text-ink-faint">Unavailable</span>
          ),
      },
      {
        header: 'Applied',
        accessorKey: 'application_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.application_date)}</span>,
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Waiting Analytics"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Waiting Analytics' }]}
      />

      {summaryLoading && <LoadingState label="Loading analytics…" />}
      {summaryError && <ErrorState error={summaryError} onRetry={refetchSummary} />}

      {summary && (
        <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Waiting Duration</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              <Row label="Under 7 days" value={summary.waiting_duration_buckets.under_7_days} />
              <Row label="7–30 days" value={summary.waiting_duration_buckets.seven_to_30_days} />
              <Row label="30–90 days" value={summary.waiting_duration_buckets.thirty_to_90_days} />
              <Row label="Over 90 days" value={summary.waiting_duration_buckets.over_90_days} />
              <Row label="Unavailable (no waiting_since)" value={summary.waiting_duration_buckets.unavailable} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Category</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {summary.waiting_by_category.length === 0 ? (
                <p className="text-sm text-ink-muted">No one currently waiting.</p>
              ) : (
                summary.waiting_by_category.map((category) => <Row key={category.id} label={category.name} value={category.total} />)
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Gender / Location</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(summary.waiting_by_gender).map(([gender, total]) => (
                <Row key={gender} label={gender} value={total ?? 0} capitalize />
              ))}
              {summary.waiting_by_location.slice(0, 5).map((entry) => (
                <Row key={entry.location} label={entry.location} value={entry.total} />
              ))}
            </CardBody>
          </Card>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <label className="text-xs font-medium text-ink-muted">Sort by</label>
          <Select
            value={orderBy}
            onChange={(e) => {
              setOrderBy(e.target.value as 'oldest' | 'newest');
              setPage(1);
            }}
            options={[
              { value: 'oldest', label: 'Oldest first' },
              { value: 'newest', label: 'Newest first' },
            ]}
            className="w-40"
          />
        </div>

        <DataTable
          columns={columns}
          data={roster?.data ?? []}
          isLoading={rosterLoading}
          error={rosterError}
          onRetry={refetchRoster}
          onRowClick={(employee) => navigate(`/hr/employees/${employee.id}`)}
          emptyTitle="No one is waiting"
          emptyDescription="Employees appear here once Practical is Approved."
        />

        {roster && roster.meta.total > 0 && <Pagination meta={roster.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}

function Row({ label, value, capitalize = false }: { label: string; value: number; capitalize?: boolean }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className={`text-ink-muted ${capitalize ? 'capitalize' : ''}`}>{label}</span>
      <span className="font-semibold text-ink">{formatNumber(value)}</span>
    </div>
  );
}
