import { useMemo, useState } from 'react';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { returnState } from '@/utils/urlFilters';
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
import { ListResultBar, ResetFiltersAction, ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { XarunFormDialog } from '@/features/marketing/XarunFormDialog';
import { ProjectFormDialog } from '@/features/marketing/ProjectFormDialog';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { formatDate } from '@/utils/formatters';
import type { MarketingRecord, MarketingRecordStatus, MarketingRecordType } from '@/types/marketing';

const STATUS_OPTIONS: { value: MarketingRecordStatus; label: string; labelKey: MessageKey }[] = [
  { value: 'pending', label: 'Pending', labelKey: 'status.marketing.record.pending' },
  { value: 'quotation', label: 'Quotation', labelKey: 'status.marketing.record.quotation' },
  { value: 'done', label: 'Done', labelKey: 'status.marketing.record.done' },
  { value: 'cancelled', label: 'Cancelled', labelKey: 'status.marketing.record.cancelled' },
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
  const { t, tValue } = useI18n();
  const url = useUrlFilters();
  const search = url.get('q');
  const status = url.get('status') as MarketingRecordStatus | '';
  const setSearch = (value: string) => url.set('q', value);
  const setStatus = (value: MarketingRecordStatus | '') => url.set('status', value);
  const page = url.page;
  const setPage = url.setPage;
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);
  const filterKeys = ['q', 'status'] as const;
  const filtered = debouncedSearch.trim() !== '' || status !== '';
  const resetFilters = () => url.reset(filterKeys);

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
        header: type === 'xarun' ? t('option.marketing.facility') : t('option.marketing.responsible'),
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
        header: t('option.marketing.team'),
        accessorKey: 'assigned_team_name',
        cell: ({ row }) =>
          row.original.assigned_team_name ? (
            <span className="text-ink">{row.original.assigned_team_name}</span>
          ) : (
            <span className="italic text-ink-faint">{t('option.employees.unassigned')}</span>
          ),
      },
      {
        header: t('option.label.employee'),
        accessorKey: 'assigned_admin_name',
        cell: ({ row }) =>
          row.original.assigned_admin_name ? (
            <span className="text-ink">{row.original.assigned_admin_name}</span>
          ) : (
            <span className="italic text-ink-faint">{t('option.employees.unassigned')}</span>
          ),
      },
      {
        header: t('option.label.status'),
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} label={tValue('status.marketing.record', row.original.status)} tone={row.original.status === 'done' ? 'success' : row.original.status === 'cancelled' ? 'danger' : row.original.status === 'quotation' ? 'info' : 'warning'} />,
      },
      {
        header: t('option.marketing.created'),
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
              navigate(`/marketing/${type}/${row.original.id}`, { state: returnState(window.location.pathname, window.location.search, '' + (type === 'xarun' ? 'XARUN' : 'PROJECT') + '') });
            }}
            className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ),
      },
    ],
    [navigate, type, t, tValue],
  );

  return (
    <div>
      <PageHeader
        title={title}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: title }]}
        actions={
          <PermissionGate module="marketing">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.marketing.newRecord', { type: type === 'xarun' ? 'XARUN' : 'PROJECT' })}
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
            placeholder={t('option.marketing.searchPlaceholder')}
            className="w-full max-w-xs"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as MarketingRecordStatus | '');
              setPage(1);
            }}
            options={STATUS_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))}
            placeholder={t('option.marketing.allStatuses')}
            className="w-44"
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
          onRowClick={(record) => navigate(`/marketing/${type}/${record.id}`, { state: returnState(window.location.pathname, window.location.search, '' + (type === 'xarun' ? 'XARUN' : 'PROJECT') + '') })}
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('warning.marketing.noRecordsFound', { type: type === 'xarun' ? 'XARUN' : 'PROJECT' })}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('warning.marketing.adjustSearch')}
          emptyAction={filtered ? <ResetFiltersAction onReset={resetFilters} /> : undefined}
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
