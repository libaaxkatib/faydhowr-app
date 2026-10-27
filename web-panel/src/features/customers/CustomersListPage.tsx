import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { customersApi } from '@/api/customers';
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
import { CustomerFormDialog } from '@/features/customers/CustomerFormDialog';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { formatDate, initialsOf } from '@/utils/formatters';
import type { Customer, CustomerStatus } from '@/types/customer';

const STATUS_OPTIONS: { value: CustomerStatus; labelKey: MessageKey }[] = [
  { value: 'ACTIVE', labelKey: 'option.customers.status.ACTIVE' },
  { value: 'INACTIVE', labelKey: 'option.customers.status.INACTIVE' },
  { value: 'BLOCKED', labelKey: 'option.customers.status.BLOCKED' },
  { value: 'DELETED', labelKey: 'option.customers.status.DELETED' },
];

export function CustomersListPage() {
  const navigate = useNavigate();
  const { t, tValue } = useI18n();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<CustomerStatus | ''>('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['customers', { search: debouncedSearch, status, page }],
    queryFn: () =>
      customersApi.list({
        search: debouncedSearch || undefined,
        status: status || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<Customer, unknown>[]>(
    () => [
      {
        header: t('option.customers.customer'),
        accessorKey: 'full_name',
        cell: ({ row }) => (
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
              {initialsOf(row.original.full_name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium text-ink">{row.original.full_name}</p>
              <p className="truncate text-xs text-ink-muted">{row.original.customer_number}</p>
            </div>
          </div>
        ),
      },
      {
        header: t('option.customers.contact'),
        accessorKey: 'phone',
        cell: ({ row }) => (
          <div>
            <p className="text-ink">{row.original.phone ?? '—'}</p>
            <p className="text-xs text-ink-muted">{row.original.email ?? t('option.customers.noEmail')}</p>
          </div>
        ),
      },
      {
        header: t('option.label.status'),
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} label={tValue('option.customers.status', row.original.status)} />,
      },
      {
        header: t('option.customers.registered'),
        accessorKey: 'registered_at',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.registered_at)}</span>,
      },
      {
        header: '',
        id: 'actions',
        cell: ({ row }) => (
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              navigate(`/mobile-app/customers/${row.original.id}`);
            }}
            className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ),
      },
    ],
    [navigate, t, tValue],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.customers.customers')}
        breadcrumb={[{ label: t('nav.customers.mobileApp'), to: '/dashboard' }, { label: t('nav.customers.customers') }]}
        actions={
          <PermissionGate module="customers">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.customers.add')}
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
            placeholder={t('option.customers.searchPlaceholder')}
            className="w-full max-w-xs"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as CustomerStatus | '');
              setPage(1);
            }}
            options={STATUS_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))}
            placeholder={t('option.customers.allStatuses')}
            className="w-44"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(customer) => navigate(`/mobile-app/customers/${customer.id}`)}
          emptyTitle={t('warning.customers.noneFound')}
          emptyDescription={t('warning.customers.adjustFilters')}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <CustomerFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} mode="create" />
    </div>
  );
}
