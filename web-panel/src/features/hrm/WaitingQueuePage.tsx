import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { SearchInput } from '@/components/ui/SearchInput';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate, formatDurationSince, initialsOf } from '@/utils/formatters';
import type { Employee } from '@/types/employee';

/**
 * Dedicated Waiting page (docs/HRM_MARKETING_SRS.md HR Phase 2) - unlike every
 * other queue, Waiting needs columns nothing else does: residential location,
 * waiting duration, and whether a workforce-request match exists. The generic
 * EmployeesListPage stays exactly as-is for every other queue.
 */
export function WaitingQueuePage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const debouncedSearch = useDebouncedValue(search);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['employees', { search: debouncedSearch, status: 'waiting', page }],
    queryFn: () => hrApi.employees.list({ search: debouncedSearch || undefined, status: 'waiting', page, per_page: 15 }),
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
        header: 'Gender',
        accessorKey: 'gender',
        cell: ({ row }) => <span className="capitalize text-ink">{row.original.gender ?? '—'}</span>,
      },
      {
        header: 'Residential Location',
        accessorKey: 'location',
        cell: ({ row }) => <span className="text-ink">{row.original.location ?? '—'}</span>,
      },
      {
        header: 'Waiting Duration',
        accessorKey: 'waiting_since',
        cell: ({ row }) => <span className="text-ink">{formatDurationSince(row.original.waiting_since)}</span>,
      },
      {
        header: 'Matched',
        id: 'matched',
        cell: ({ row }) =>
          (row.original.workforce_request_matches_count ?? 0) > 0 ? (
            <StatusBadge status="matched" label={`Matched (${row.original.workforce_request_matches_count})`} tone="success" />
          ) : (
            <span className="text-xs italic text-ink-faint">Not yet matched</span>
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
      <PageHeader title="Waiting" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Waiting' }]} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search by name, phone, or employee number…"
            className="w-full max-w-xs"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(employee) =>
            navigate(`/hr/employees/${employee.id}`, {
              state: { fromPath: `${window.location.pathname}${window.location.search}`, fromLabel: 'Waiting' },
            })
          }
          emptyTitle="No one is waiting"
          emptyDescription="Employees appear here once Practical is Approved."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
