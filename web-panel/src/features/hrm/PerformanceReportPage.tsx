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
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import { formatDate } from '@/utils/formatters';
import type { EmployeePerformanceReview, PerformanceRating } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

const RATING_OPTIONS: { value: PerformanceRating; label: string }[] = [
  { value: 'excellent', label: 'Excellent' },
  { value: 'good', label: 'Good' },
  { value: 'needs_improvement', label: 'Needs Improvement' },
  { value: 'poor', label: 'Poor' },
];

/** docs/HRM_MARKETING_SRS.md HR Phase 6: company-wide Performance Review list - categorical ratings only, no numeric scoring. */
export function PerformanceReportPage() {
  const { t, tValue } = useI18n();
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  // The filters and the page live in the address bar (changing one keeps the others and returns to page 1), so Back, a reload or a shared link bring the report back as it was.
  const url = useUrlFilters();
  const departmentId = url.get('department');
  const rating = url.get('rating') as PerformanceRating | '';
  const setDepartmentId = (value: string) => url.set('department', value);
  const setRating = (value: PerformanceRating | '') => url.set('rating', value);
  const page = url.page;
  const setPage = url.setPage;

  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const filterKeys = ['department', 'rating', ...DATE_RANGE_KEYS] as const;
  const filtered = departmentId !== '' || rating !== '' || from !== '' || to !== '';
  const resetFilters = () => url.reset(filterKeys);

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
        header: t('option.employeeDetail.fieldRating'),
        accessorKey: 'rating_label',
        cell: ({ row }) => <span className="text-ink">{tValue('status.pipeline.rating', row.original.rating) ?? row.original.rating_label}</span>,
      },
      {
        header: t('option.reports.colReviewDate'),
        accessorKey: 'review_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.review_date)}</span>,
      },
      {
        header: t('option.reports.colReviewedBy'),
        accessorKey: 'reviewed_by',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.reviewed_by ?? '—'}</span>,
      },
    ],
    [t, tValue],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.performanceReport')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.performanceReport') }]}
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
            placeholder={t('option.pipeline.allDepartments')}
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
            className="w-44"
          />
          <Select
            value={rating}
            onChange={(e) => {
              setRating(e.target.value as PerformanceRating | '');
              setPage(1);
            }}
            placeholder={t('option.pipeline.allRatings')}
            options={RATING_OPTIONS.map((o) => ({ value: o.value, label: tValue('status.pipeline.rating', o.value) ?? o.label }))}
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
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('option.reports.reviewsListEmptyTitle')}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('option.reports.reviewsListEmptyBody')}
          emptyAction={filtered ? <ResetFiltersAction onReset={resetFilters} /> : undefined}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
