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
import type { TemporaryReplacement } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

export function TemporaryReplacementHistoryPage() {
  const { t, tValue } = useI18n();
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  const [clientCompanyId, setClientCompanyId] = useState('');
  const [page, setPage] = useState(1);

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['temporary-replacement-history', { clientCompanyId, from, to, page }],
    queryFn: () =>
      hrApi.reports.temporaryReplacementHistory({
        client_company_id: clientCompanyId ? Number(clientCompanyId) : undefined,
        from: from || undefined,
        to: to || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<TemporaryReplacement, unknown>[]>(
    () => [
      {
        header: t('option.label.location'),
        id: 'location',
        cell: ({ row }) => (
          <span className="text-ink">
            {[row.original.client_company_name, row.original.work_location_name].filter(Boolean).join(' · ') || '—'}
          </span>
        ),
      },
      {
        header: t('option.pipeline.replaced'),
        accessorKey: 'replaced_employee_name',
        cell: ({ row }) => <span className="text-ink">{row.original.replaced_employee_name ?? '—'}</span>,
      },
      {
        header: t('option.label.replacement'),
        accessorKey: 'replacement_employee_name',
        cell: ({ row }) => <span className="text-ink">{row.original.replacement_employee_name ?? '—'}</span>,
      },
      {
        header: t('option.label.period'),
        id: 'period',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {formatDate(row.original.start_date)} – {row.original.end_date ? formatDate(row.original.end_date) : t('option.pipeline.ongoing')}
          </span>
        ),
      },
      {
        header: t('option.pipeline.totalPaid'),
        accessorKey: 'total_paid',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.total_paid} {row.original.currency}
          </span>
        ),
      },
      {
        header: t('option.label.status'),
        accessorKey: 'status',
        cell: ({ row }) => (
          <StatusBadge
            status={row.original.status}
            label={tValue('status.pipeline.replacement', row.original.status) ?? (row.original.status === 'active' ? 'Active' : 'Ended')}
            tone={row.original.status === 'active' ? 'warning' : 'neutral'}
          />
        ),
      },
    ],
    [t, tValue],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.temporaryReplacementHistory')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.temporaryReplacementHistory') }]}
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
            value={clientCompanyId}
            onChange={(e) => {
              setClientCompanyId(e.target.value);
              setPage(1);
            }}
            placeholder={t('option.pipeline.allCompanies')}
            options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
            className="w-48"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle={t('option.pipeline.noTemporaryReplacements')}
          emptyDescription={t('option.pipeline.replacementsEmptyDescription')}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
