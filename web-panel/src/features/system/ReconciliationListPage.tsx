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
import type { DataIssue, DataIssueModule, DataIssueSeverity, DataIssueStatus, DataIssueType } from '@/types/reconciliation';

const STATUS_OPTIONS: { value: DataIssueStatus; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'investigating', label: 'Investigating' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'accepted_difference', label: 'Accepted Difference' },
  { value: 'cannot_resolve', label: 'Cannot Resolve' },
];

const SEVERITY_OPTIONS: { value: DataIssueSeverity; label: string }[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
];

const MODULE_OPTIONS: { value: DataIssueModule; label: string }[] = [
  { value: 'hr', label: 'HR' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'finance', label: 'Finance' },
  { value: 'bookings', label: 'Bookings' },
  { value: 'customers', label: 'Customers' },
  { value: 'quotations', label: 'Quotations' },
  { value: 'store', label: 'Store' },
  { value: 'system', label: 'System' },
  { value: 'other', label: 'Other' },
];

const TYPE_OPTIONS: { value: DataIssueType; label: string }[] = [
  { value: 'missing_data', label: 'Missing Data' },
  { value: 'missing_record', label: 'Missing Record' },
  { value: 'duplicate', label: 'Duplicate' },
  { value: 'conflict', label: 'Conflict' },
  { value: 'source_mismatch', label: 'Source Mismatch' },
  { value: 'count_discrepancy', label: 'Count Discrepancy' },
  { value: 'invalid_record', label: 'Invalid Record' },
  { value: 'system_error', label: 'System Error' },
  { value: 'migration_issue', label: 'Migration Issue' },
  { value: 'other', label: 'Other' },
];

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
        header: 'Issue #',
        accessorKey: 'issue_number',
        cell: ({ row }) => <span className="font-mono text-xs font-semibold text-ink">{row.original.issue_number}</span>,
      },
      {
        header: 'Title',
        accessorKey: 'title',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{row.original.title}</p>
            <p className="truncate text-xs text-ink-muted">{row.original.issue_type_label}</p>
          </div>
        ),
      },
      { header: 'Module', accessorKey: 'module_label', cell: ({ row }) => <span className="text-ink">{row.original.module_label}</span> },
      {
        header: 'Severity',
        accessorKey: 'severity',
        cell: ({ row }) => <StatusBadge status={row.original.severity} tone={SEVERITY_TONE[row.original.severity]} label={row.original.severity_label} />,
      },
      {
        header: 'Status',
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} tone={STATUS_TONE[row.original.status]} label={row.original.status_label} />,
      },
      {
        header: 'Affected',
        accessorKey: 'affected_records_count',
        cell: ({ row }) => <span className="text-ink">{row.original.affected_records_count ?? 0}</span>,
      },
      { header: 'Created', accessorKey: 'created_at', cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.created_at)}</span> },
      { header: 'Resolved', accessorKey: 'resolved_at', cell: ({ row }) => <span className="text-ink-muted">{row.original.resolved_at ? formatDate(row.original.resolved_at) : '—'}</span> },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Data Issues & Reconciliation"
        breadcrumb={[{ label: 'System', to: '/system/settings' }, { label: 'Data Issues & Reconciliation' }]}
        actions={
          hasPermission('reconciliation.create') ? (
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              Record Issue
            </Button>
          ) : undefined
        }
      />

      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        {(
          [
            ['Total', summary?.total, 'neutral'],
            ['Open', summary?.open, 'danger'],
            ['Investigating', summary?.investigating, 'warning'],
            ['Resolved', summary?.resolved, 'success'],
            ['Accepted Diff.', summary?.accepted_difference, 'info'],
            ['Cannot Resolve', summary?.cannot_resolve, 'neutral'],
            ['Critical', summary?.critical, 'danger'],
            ['High', summary?.high, 'warning'],
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
          <SearchInput value={search} onChange={resetPageAnd(setSearch)} placeholder="Search issue #, title, employee, source…" className="min-w-[240px] flex-1" />
          <Select
            className={inputClasses + ' w-auto'}
            options={STATUS_OPTIONS}
            placeholder="All statuses"
            value={status}
            onChange={(e) => resetPageAnd(setStatus)(e.target.value as DataIssueStatus | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={SEVERITY_OPTIONS}
            placeholder="All severities"
            value={severity}
            onChange={(e) => resetPageAnd(setSeverity)(e.target.value as DataIssueSeverity | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={MODULE_OPTIONS}
            placeholder="All modules"
            value={module}
            onChange={(e) => resetPageAnd(setModule)(e.target.value as DataIssueModule | '')}
          />
          <Select
            className={inputClasses + ' w-auto'}
            options={TYPE_OPTIONS}
            placeholder="All types"
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
          emptyTitle="No data issues found"
          emptyDescription="Try adjusting your search or filters."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <DataIssueFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} />
    </div>
  );
}
