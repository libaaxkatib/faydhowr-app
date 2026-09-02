import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { XarunFormDialog } from '@/features/marketing/XarunFormDialog';
import { ProjectFormDialog } from '@/features/marketing/ProjectFormDialog';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate } from '@/utils/formatters';
import type { MarketingRecord, MarketingRecordStatus, MarketingRecordType } from '@/types/marketing';

const STATUS_OPTIONS: { value: MarketingRecordStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'quotation', label: 'Quotation' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
];

interface MarketingRecordsListPageProps {
  type: MarketingRecordType;
  title: string;
}

/**
 * Shared browse/list table for both XARUN and PROJECT — only the underlying
 * data differs (filtered by `type`). Create/edit forms are NOT shared
 * (XarunFormDialog / ProjectFormDialog), per the SRS's explicit rule that
 * XARUN and PROJECT never use one generic form.
 */
export function MarketingRecordsListPage({ type, title }: MarketingRecordsListPageProps) {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<MarketingRecordStatus | ''>('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-records', { type, search: debouncedSearch, status, page }],
    queryFn: () =>
      marketingApi.records.list({
        type,
        search: debouncedSearch || undefined,
        status: status || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<MarketingRecord, unknown>[]>(
    () => [
      {
        header: type === 'xarun' ? 'Facility' : 'Responsible',
        id: 'primary',
        cell: ({ row }) => (
          <div>
            <p className="font-medium text-ink">
              {type === 'xarun' ? row.original.xarun?.facility_name : row.original.project?.responsible_person_name ?? row.original.project?.company_name}
            </p>
            <p className="text-xs text-ink-muted">{row.original.record_number}</p>
          </div>
        ),
      },
      {
        header: 'Team',
        accessorKey: 'assigned_team_name',
        cell: ({ row }) => <span className="text-ink">{row.original.assigned_team_name ?? '—'}</span>,
      },
      {
        header: 'Assigned',
        accessorKey: 'assigned_admin_name',
        cell: ({ row }) => <span className="text-ink">{row.original.assigned_admin_name ?? 'Unassigned'}</span>,
      },
      {
        header: 'Status',
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} tone={row.original.status === 'done' ? 'success' : row.original.status === 'cancelled' ? 'danger' : row.original.status === 'quotation' ? 'info' : 'warning'} />,
      },
      {
        header: 'Created',
        accessorKey: 'created_at',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.created_at)}</span>,
      },
      {
        header: '',
        id: 'actions',
        cell: ({ row }) => (
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              navigate(`/marketing/${type}/${row.original.id}`);
            }}
            className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ),
      },
    ],
    [navigate, type],
  );

  return (
    <div>
      <PageHeader
        title={title}
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: title }]}
        actions={
          <PermissionGate module="marketing">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              New {type === 'xarun' ? 'XARUN' : 'PROJECT'}
            </Button>
          </PermissionGate>
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search…"
            className="w-full max-w-xs"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as MarketingRecordStatus | '');
              setPage(1);
            }}
            options={STATUS_OPTIONS}
            placeholder="All statuses"
            className="w-44"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(record) => navigate(`/marketing/${type}/${record.id}`)}
          emptyTitle={`No ${type === 'xarun' ? 'XARUN' : 'PROJECT'} records found`}
          emptyDescription="Try adjusting your search or filters."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      {type === 'xarun' ? (
        <XarunFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} mode="create" />
      ) : (
        <ProjectFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} mode="create" />
      )}
    </div>
  );
}
