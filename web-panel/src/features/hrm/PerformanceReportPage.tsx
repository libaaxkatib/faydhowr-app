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
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import { formatDate } from '@/utils/formatters';
import type { EmployeePerformanceReview, PerformanceRating } from '@/types/employee';

const RATING_OPTIONS: { value: PerformanceRating; label: string }[] = [
  { value: 'excellent', label: 'Excellent' },
  { value: 'good', label: 'Good' },
  { value: 'needs_improvement', label: 'Needs Improvement' },
  { value: 'poor', label: 'Poor' },
];

/** docs/HRM_MARKETING_SRS.md HR Phase 6: company-wide Performance Review list - categorical ratings only, no numeric scoring. */
export function PerformanceReportPage() {
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  const [departmentId, setDepartmentId] = useState('');
  const [rating, setRating] = useState<PerformanceRating | ''>('');
  const [page, setPage] = useState(1);

  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['performance-report', { departmentId, rating, from, to, page }],
    queryFn: () =>
      hrApi.reports.performanceReviews({
        department_id: departmentId ? Number(departmentId) : undefined,
        rating: rating || undefined,
        from: from || undefined,
        to: to || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<EmployeePerformanceReview, unknown>[]>(
    () => [
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
        header: 'Rating',
        accessorKey: 'rating_label',
        cell: ({ row }) => <span className="text-ink">{row.original.rating_label}</span>,
      },
      {
        header: 'Review Date',
        accessorKey: 'review_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.review_date)}</span>,
      },
      {
        header: 'Reviewed By',
        accessorKey: 'reviewed_by',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.reviewed_by ?? '—'}</span>,
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Performance Report"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Performance Report' }]}
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
            value={rating}
            onChange={(e) => {
              setRating(e.target.value as PerformanceRating | '');
              setPage(1);
            }}
            placeholder="All ratings"
            options={RATING_OPTIONS}
            className="w-44"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle="No performance reviews recorded"
          emptyDescription="Reviews appear here once recorded for an employee."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
