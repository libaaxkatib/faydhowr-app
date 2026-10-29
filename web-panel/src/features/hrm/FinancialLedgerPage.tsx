import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { ListResultBar, ResetFiltersAction, ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { DATE_RANGE_KEYS } from '@/utils/dateRangePreset';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { formatDate } from '@/utils/formatters';
import type { FinancialLedgerRow } from '@/types/employee';

const TYPE_OPTIONS: { value: 'payment' | 'penalty' | 'advance'; labelKey: MessageKey }[] = [
  { value: 'payment', labelKey: 'option.payroll.ledgerPayments' },
  { value: 'penalty', labelKey: 'option.payroll.penalties' },
  { value: 'advance', labelKey: 'option.payroll.advances' },
];

const TYPE_TONE: Record<string, 'success' | 'danger' | 'warning'> = {
  payment: 'success',
  penalty: 'danger',
  advance: 'warning',
};

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a combined, read-only PRESENTATION of
 * Payments + Penalties + Advances. Each row still traces to exactly one of
 * the three real tables (tagged by `type`) - nothing is merged server-side.
 */
export function FinancialLedgerPage() {
  const { t } = useI18n();
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  // The filters and the page live in the address bar (changing one keeps the others and returns to page 1), so Back, a reload or a shared link bring the report back as it was.
  const url = useUrlFilters();
  const departmentId = url.get('department');
  const type = url.get('type') as 'payment' | 'penalty' | 'advance' | '';
  const setDepartmentId = (value: string) => url.set('department', value);
  const setType = (value: 'payment' | 'penalty' | 'advance' | '') => url.set('type', value);
  const page = url.page;
  const setPage = url.setPage;

  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const filterKeys = ['department', 'type', ...DATE_RANGE_KEYS] as const;
  const filtered = departmentId !== '' || type !== '' || from !== '' || to !== '';
  const resetFilters = () => url.reset(filterKeys);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['financial-ledger', { departmentId, type, from, to, page }],
    queryFn: () =>
      hrApi.reports.financialLedger({
        department_id: departmentId ? Number(departmentId) : undefined,
        type: type || undefined,
        from: from || undefined,
        to: to || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const typeOptions = useMemo(() => TYPE_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey) })), [t]);

  const columns = useMemo<ColumnDef<FinancialLedgerRow, unknown>[]>(
    () => [
      {
        header: t('option.label.type'),
        accessorKey: 'type',
        cell: ({ row }) => (
          <StatusBadge status={row.original.type} label={typeOptions.find((o) => o.value === row.original.type)?.label ?? row.original.type} tone={TYPE_TONE[row.original.type]} />
        ),
      },
      {
        header: t('option.label.employee'),
        id: 'employee',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{row.original.employee_name ?? '—'}</p>
            <p className="truncate text-xs text-ink-muted">{row.original.department_name ?? '—'}</p>
          </div>
        ),
      },
      {
        header: t('option.label.date'),
        accessorKey: 'date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.date)}</span>,
      },
      {
        header: t('option.label.amount'),
        accessorKey: 'amount',
        cell: ({ row }) => (
          <span className="font-semibold text-ink">
            {row.original.amount} {row.original.currency}
          </span>
        ),
      },
      {
        header: t('option.payroll.ledgerReasonNotes'),
        id: 'reason',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.reason ?? row.original.notes ?? '—'}</span>,
      },
    ],
    [t, typeOptions],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.financialLedger')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.financialLedger') }]}
      />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-center gap-2">
          {presetOptions.map((option) => (
            <Button
              key={option.value}
              type="button"
              size="sm"
              variant={preset === option.value ? 'primary' : 'outline'}
              className="h-8 px-3 text-xs"
              onClick={() => {
                setPreset(option.value);
                setPage(1);
              }}
            >
              {option.label}
            </Button>
          ))}
        </CardBody>
      </Card>

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <input
            aria-label={t('option.reports.dateFrom')}
            type="date"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPage(1);
            }}
            className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
          />
          <input
            aria-label={t('option.reports.dateTo')}
            type="date"
            value={to}
            onChange={(e) => {
              setTo(e.target.value);
              setPage(1);
            }}
            className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
          />
          <Select
            value={departmentId}
            onChange={(e) => {
              setDepartmentId(e.target.value);
              setPage(1);
            }}
            placeholder={t('option.employees.allDepartments')}
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
            className="w-44"
          />
          <Select
            value={type}
            onChange={(e) => {
              setType(e.target.value as 'payment' | 'penalty' | 'advance' | '');
              setPage(1);
            }}
            placeholder={t('option.facility.allTypes')}
            options={typeOptions}
            className="w-40"
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
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('option.payroll.ledgerEmptyTitle')}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('option.payroll.ledgerEmptyBody')}
          emptyAction={filtered ? <ResetFiltersAction onReset={resetFilters} /> : undefined}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
