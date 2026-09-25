import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { SearchInput } from '@/components/ui/SearchInput';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate, initialsOf } from '@/utils/formatters';
import type { Employee } from '@/types/employee';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: dedicated Former Employees page,
 * mirroring WaitingQueuePage.tsx - needs separation-specific columns nothing
 * else does. rehire_eligible is advisory only (a badge), never a hard block
 * on the Rehire action, mirroring Phase 2's "matching criteria are advisory"
 * precedent for candidate recommendations.
 */
export function FormerEmployeesPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const debouncedSearch = useDebouncedValue(search);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['employees', { search: debouncedSearch, status: 'inactive', page }],
    queryFn: () => hrApi.employees.list({ search: debouncedSearch || undefined, status: 'inactive', page, per_page: 15 }),
    placeholderData: (previous) => previous,
  });

  const rehireMutation = useMutation({
    mutationFn: (employeeId: number) => hrApi.employees.rehire(employeeId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show('Employee rehired.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not rehire employee.', 'error'),
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
        header: 'Separation Reason',
        id: 'reason',
        cell: ({ row }) => <span className="text-ink">{row.original.latest_separation?.reason_label ?? '—'}</span>,
      },
      {
        header: 'Separation Date',
        id: 'separation_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.latest_separation?.separation_date)}</span>,
      },
      {
        header: 'Rehire Eligible',
        id: 'rehire_eligible',
        cell: ({ row }) =>
          row.original.latest_separation ? (
            <StatusBadge
              status="rehire"
              label={row.original.latest_separation.rehire_eligible ? 'Eligible' : 'Not marked eligible'}
              tone={row.original.latest_separation.rehire_eligible ? 'success' : 'neutral'}
            />
          ) : (
            '—'
          ),
      },
      {
        header: '',
        id: 'rehire-action',
        cell: ({ row }) => (
          <PermissionGate module="hr">
            <Button
              size="sm"
              variant="outline"
              className="h-8 px-3 text-xs"
              isLoading={rehireMutation.isPending}
              onClick={(event) => {
                event.stopPropagation();
                rehireMutation.mutate(row.original.id);
              }}
            >
              Rehire
            </Button>
          </PermissionGate>
        ),
      },
    ],
    [rehireMutation],
  );

  return (
    <div>
      <PageHeader title="Former Employees" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Former Employees' }]} />

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
          onRowClick={(employee) => navigate(`/hr/employees/${employee.id}`)}
          emptyTitle="No former employees"
          emptyDescription="Employees appear here once marked Inactive."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
