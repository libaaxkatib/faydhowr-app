import { useMemo } from 'react';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { returnState } from '@/utils/urlFilters';
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
import { ListResultBar, ResetFiltersAction, ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate, initialsOf } from '@/utils/formatters';
import type { Employee } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

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
  const { t } = useI18n();
  // Search and page live in the address bar, so Back from an employee returns to the same results.
  const url = useUrlFilters();
  const search = url.get('q');
  const page = url.page;
  const setSearch = (value: string) => url.set('q', value);
  const setPage = url.setPage;
  const debouncedSearch = useDebouncedValue(search);
  const filterKeys = ['q'] as const;
  const filtered = debouncedSearch.trim() !== '';
  const resetFilters = () => url.reset(filterKeys);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['employees', { search: debouncedSearch, status: 'inactive', page }],
    queryFn: () => hrApi.employees.list({ search: debouncedSearch || undefined, status: 'inactive', page, per_page: 15 }),
    placeholderData: (previous) => previous,
  });

  const rehireMutation = useMutation({
    mutationFn: (employeeId: number) => hrApi.employees.rehire(employeeId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show(t('notify.pipeline.employeeRehired'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.pipeline.couldNotRehire'), 'error'),
  });

  const columns = useMemo<ColumnDef<Employee, unknown>[]>(
    () => [
      {
        header: t('option.label.employee'),
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
        header: t('option.label.category'),
        accessorKey: 'employee_category_name',
        cell: ({ row }) => <span className="text-ink">{row.original.employee_category_name ?? '—'}</span>,
      },
      {
        header: t('option.employees.separationReason'),
        id: 'reason',
        cell: ({ row }) => <span className="text-ink">{row.original.latest_separation?.reason_label ?? '—'}</span>,
      },
      {
        header: t('option.employees.separationDate'),
        id: 'separation_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.latest_separation?.separation_date)}</span>,
      },
      {
        header: t('option.employees.rehireEligible'),
        id: 'rehire_eligible',
        cell: ({ row }) =>
          row.original.latest_separation ? (
            <StatusBadge
              status="rehire"
              label={row.original.latest_separation.rehire_eligible ? t('status.pipeline.rehireEligible') : t('status.pipeline.rehireNotMarked')}
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
              {t('action.rehire')}
            </Button>
          </PermissionGate>
        ),
      },
    ],
    [rehireMutation, t],
  );

  return (
    <div>
      <PageHeader title={t('nav.formerEmployees')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.formerEmployees') }]} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder={t('option.employees.searchEmployees')}
            className="w-full max-w-xs"
          />
          <ResetFiltersButton count={url.activeCount(filterKeys)} onReset={resetFilters} />
        </div>
        {data && <ListResultBar matching={data.meta.total} filtered={filtered} />}

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(employee) => navigate(`/hr/employees/${employee.id}`, { state: returnState(window.location.pathname, window.location.search, 'Former Employees') })}
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('option.employees.noFormerEmployees')}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('option.employees.noFormerEmployeesHint')}
          emptyAction={filtered ? <ResetFiltersAction onReset={resetFilters} /> : undefined}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
