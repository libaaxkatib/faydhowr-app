import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { reconciliationApi } from '@/api/reconciliation';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { inputClasses } from '@/components/ui/FormField';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate } from '@/utils/formatters';
import { DataIssueFormDialog } from '@/features/system/DataIssueFormDialog';
import { MODULE_OPTIONS, SEVERITY_OPTIONS, STATUS_OPTIONS, TYPE_OPTIONS, translateOptions } from '@/features/system/dataIssueOptions';
import { useI18n } from '@/i18n/useI18n';
import type { DataIssue, DataIssueModule, DataIssueSeverity, DataIssueStatus, DataIssueType } from '@/types/reconciliation';

const STATUS_TONE: Record<DataIssueStatus, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  open: 'danger',
  investigating: 'warning',
  resolved: 'success',
  accepted_difference: 'info',
  cannot_resolve: 'neutral',
};

const SEVERITY_TONE: Record<DataIssueSeverity, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
};

export function ReconciliationListPage() {
  const { t, tValue } = useI18n();
  const navigate = useNavigate();
  const { hasPermission } = useEffectivePermissions();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<DataIssueStatus | ''>('');
  const [severity, setSeverity] = useState<DataIssueSeverity | ''>('');
  const [module, setModule] = useState<DataIssueModule | ''>('');
  const [issueType, setIssueType] = useState<DataIssueType | ''>('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);

  function resetPageAnd<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      setPage(1);
    };
  }

  const { data: summary } = useQuery({
    queryKey: ['reconciliation-summary'],
    queryFn: () => reconciliationApi.dataIssues.summary(),
  });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['data-issues', { search: debouncedSearch, status, severity, module, issueType, page }],
    queryFn: () =>
      reconciliationApi.dataIssues.list({
        search: debouncedSearch || undefined,
        status: status || undefined,
        severity: severity || undefined,
        module: module || undefined,
        issue_type: issueType || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<DataIssue, unknown>[]>(
    () => [
      {
        header: t('option.system.colIssueNumber'),
        accessorKey: 'issue_number',
        cell: ({ row }) => <span className="font-mono text-xs font-semibold text-ink">{row.original.issue_number}</span>,
      },
      {
        header: t('option.system.colTitle'),
        accessorKey: 'title',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{row.original.title}</p>
            <p className="truncate text-xs text-ink-muted">{tValue('option.system.issueType', row.original.issue_type) ?? row.original.issue_type_label}</p>
          </div>
        ),
      },
      {
        header: t('option.system.colModule'),
        accessorKey: 'module_label',
        cell: ({ row }) => <span className="text-ink">{tValue('option.system.issueModule', row.original.module) ?? row.original.module_label}</span>,
      },
      {
        header: t('option.system.colSeverity'),
        accessorKey: 'severity',
        cell: ({ row }) => <StatusBadge status={row.original.severity} tone={SEVERITY_TONE[row.original.severity]} label={tValue('option.system.issueSeverity', row.original.severity) ?? row.original.severity_label} />,
      },
      {
        header: t('option.label.status'),
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} tone={STATUS_TONE[row.original.status]} label={tValue('option.system.issueStatus', row.original.status) ?? row.original.status_label} />,
      },
      {
        header: t('option.system.colAffected'),
        accessorKey: 'affected_records_count',
        cell: ({ row }) => <span className="text-ink">{row.original.affected_records_count ?? 0}</span>,
      },
      { header: t('option.system.colCreated'), accessorKey: 'created_at', cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.created_at)}</span> },
      { header: t('option.system.colResolved'), accessorKey: 'resolved_at', cell: ({ row }) => <span className="text-ink-muted">{row.original.resolved_at ? formatDate(row.original.resolved_at) : '—'}</span> },
    ],
    [t, tValue],
  );

  const statusOptions = translateOptions(STATUS_OPTIONS, 'option.system.issueStatus', tValue);
  const severityOptions = translateOptions(SEVERITY_OPTIONS, 'option.system.issueSeverity', tValue);
  const moduleOptions = translateOptions(MODULE_OPTIONS, 'option.system.issueModule', tValue);
  const typeOptions = translateOptions(TYPE_OPTIONS, 'option.system.issueType', tValue);

  return (
    <div>
      <PageHeader
        title={t('option.system.pageDataIssues')}
        breadcrumb={[{ label: t('option.system.system'), to: '/system/settings' }, { label: t('option.system.pageDataIssues') }]}
        actions={
          hasPermission('reconciliation.create') ? (
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.system.recordIssue')}
            </Button>
          ) : undefined
        }
      />

      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        {(
          [
            [t('option.system.total'), summary?.total, 'neutral'],
            [t('option.system.issueStatus.open'), summary?.open, 'danger'],
            [t('option.system.issueStatus.investigating'), summary?.investigating, 'warning'],
            [t('option.system.issueStatus.resolved'), summary?.resolved, 'success'],
            [t('option.system.acceptedDiffShort'), summary?.accepted_difference, 'info'],
            [t('option.system.issueStatus.cannot_resolve'), summary?.cannot_resolve, 'neutral'],
            [t('option.system.issueSeverity.critical'), summary?.critical, 'danger'],
            [t('option.system.issueSeverity.high'), summary?.high, 'warning'],
          ] as const
        ).map(([label, value, tone]) => (
          <Card key={label} className="px-4 py-3">
            <p className="text-xs font-medium uppercase tracking-wide text-ink-muted">{label}</p>
            <p className={`mt-1 font-display text-2xl font-bold ${tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : tone === 'success' ? 'text-success' : tone === 'info' ? 'text-secondary' : 'text-ink'}`}>
              {value ?? '—'}
            </p>
          </Card>
        ))}
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput value={search} onChange={resetPageAnd(setSearch)} placeholder={t('option.system.searchIssues')} className="min-w-[240px] flex-1" />
          <Select
            className={inputClasses + ' w-auto'}
            options={statusOptions}
            placeholder={t('option.system.allStatuses')}
            value={status}
            onChange={(e) => resetPageAnd(setStatus)(e.target.value as DataIssueStatus | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={severityOptions}
            placeholder={t('option.system.allSeverities')}
            value={severity}
            onChange={(e) => resetPageAnd(setSeverity)(e.target.value as DataIssueSeverity | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={moduleOptions}
            placeholder={t('option.system.allModules')}
            value={module}
            onChange={(e) => resetPageAnd(setModule)(e.target.value as DataIssueModule | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={typeOptions}
            placeholder={t('option.system.allTypes')}
            value={issueType}
            onChange={(e) => resetPageAnd(setIssueType)(e.target.value as DataIssueType | '')}
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(issue) => navigate(`/system/data-issues/${issue.id}`)}
          emptyTitle={t('warning.system.noIssues')}
          emptyDescription={t('option.system.noMatchHint')}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <DataIssueFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} />
    </div>
  );
}
