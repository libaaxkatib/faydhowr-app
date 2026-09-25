import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import { formatDate } from '@/utils/formatters';
import type { FinancialLedgerRow } from '@/types/employee';

const TYPE_OPTIONS: { value: 'payment' | 'penalty' | 'advance'; label: string }[] = [
  { value: 'payment', label: 'Payments' },
  { value: 'penalty', label: 'Penalties' },
  { value: 'advance', label: 'Advances' },
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
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  const [departmentId, setDepartmentId] = useState('');
  const [type, setType] = useState<'payment' | 'penalty' | 'advance' | ''>('');
  const [page, setPage] = useState(1);

  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

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

  const columns = useMemo<ColumnDef<FinancialLedgerRow, unknown>[]>(
    () => [
      {
        header: 'Type',
        accessorKey: 'type',
        cell: ({ row }) => (
          <StatusBadge status={row.original.type} label={TYPE_OPTIONS.find((o) => o.value === row.original.type)?.label ?? row.original.type} tone={TYPE_TONE[row.original.type]} />
        ),
      },
      {
        header: 'Employee',
        id: 'employee',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{row.original.employee_name ?? '—'}</p>
            <p className="truncate text-xs text-ink-muted">{row.original.department_name ?? '—'}</p>
          </div>
        ),
      },
      {
        header: 'Date',
        accessorKey: 'date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.date)}</span>,
      },
      {
        header: 'Amount',
        accessorKey: 'amount',
        cell: ({ row }) => (
          <span className="font-semibold text-ink">
            {row.original.amount} {row.original.currency}
          </span>
        ),
      },
      {
        header: 'Reason / Notes',
        id: 'reason',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.reason ?? row.original.notes ?? '—'}</span>,
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Financial Ledger"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Financial Ledger' }]}
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
            type="date"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPage(1);
            }}
            className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
          />
          <input
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
            placeholder="All departments"
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
            className="w-44"
          />
          <Select
            value={type}
            onChange={(e) => {
              setType(e.target.value as 'payment' | 'penalty' | 'advance' | '');
              setPage(1);
            }}
            placeholder="All types"
            options={TYPE_OPTIONS}
            className="w-40"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle="No financial records"
          emptyDescription="Payments, penalties, and advances appear here once recorded."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
