import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { formatDate } from '@/utils/formatters';
import type { WorkforceRequest, WorkforceRequestStatus } from '@/types/employee';

const STATUS_OPTIONS: { value: WorkforceRequestStatus; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'partially_filled', label: 'Partially Filled' },
  { value: 'fulfilled', label: 'Fulfilled' },
  { value: 'cancelled', label: 'Cancelled' },
];

const STATUS_TONE: Record<WorkforceRequestStatus, 'neutral' | 'warning' | 'success' | 'danger'> = {
  open: 'neutral',
  partially_filled: 'warning',
  fulfilled: 'success',
  cancelled: 'danger',
};

/** docs/HRM_MARKETING_SRS.md HR Phase 6: full request history (not just open cards, unlike WorkforceRequestsPage.tsx). */
export function WorkforceRequestHistoryPage() {
  const [clientCompanyId, setClientCompanyId] = useState('');
  const [status, setStatus] = useState<WorkforceRequestStatus | ''>('');
  const [page, setPage] = useState(1);

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['workforce-request-history', { clientCompanyId, status, page }],
    queryFn: () =>
      hrApi.reports.workforceRequestHistory({
        client_company_id: clientCompanyId ? Number(clientCompanyId) : undefined,
        status: status || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<WorkforceRequest, unknown>[]>(
    () => [
      {
        header: 'Location',
        id: 'location',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.client_company_name ? `${row.original.client_company_name} · ${row.original.work_location_name}` : row.original.work_location_name}
          </span>
        ),
      },
      {
        header: 'Requested',
        accessorKey: 'requested_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.requested_date)}</span>,
      },
      {
        header: 'Needed / Matched',
        id: 'progress',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.matched_count} / {row.original.quantity_needed}
            {row.original.quantity_needed - row.original.matched_count > 0 && (
              <span className="ml-1 text-xs text-ink-muted">({row.original.quantity_needed - row.original.matched_count} unmatched)</span>
            )}
          </span>
        ),
      },
      {
        header: 'Status',
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} label={STATUS_OPTIONS.find((o) => o.value === row.original.status)?.label ?? row.original.status} tone={STATUS_TONE[row.original.status]} />,
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Workforce Request History"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Workforce Request History' }]}
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <Select
            value={clientCompanyId}
            onChange={(e) => {
              setClientCompanyId(e.target.value);
              setPage(1);
            }}
            placeholder="All companies"
            options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
            className="w-48"
          />
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as WorkforceRequestStatus | '');
              setPage(1);
            }}
            placeholder="All statuses"
            options={STATUS_OPTIONS}
            className="w-44"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle="No workforce requests"
          emptyDescription="Requests appear here once created."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
