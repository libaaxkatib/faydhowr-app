import { useMemo } from 'react';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { returnState } from '@/utils/urlFilters';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatDate, formatDurationSince, formatNumber, initialsOf } from '@/utils/formatters';
import type { Employee } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: aggregate Waiting analytics (duration
 * buckets, category/gender/location split from GetHrReportsSummaryAction)
 * plus a sortable-by-duration roster (ListWaitingRosterAction). Complements
 * WaitingQueuePage.tsx, which stays the per-employee search+browse view.
 */
export function WaitingAnalyticsPage() {
  const navigate = useNavigate();
  const { t, tValue } = useI18n();
  const url = useUrlFilters();
  const orderBy: 'oldest' | 'newest' = url.get('order') === 'newest' ? 'newest' : 'oldest';
  const setOrderBy = (value: 'oldest' | 'newest') => url.set('order', value === 'oldest' ? '' : value);
  const page = url.page;
  const setPage = url.setPage;

  const { data: summary, isLoading: summaryLoading, error: summaryError, refetch: refetchSummary } = useQuery({
    queryKey: ['hr-reports-summary', {}],
    queryFn: () => hrApi.reportsSummary(),
  });

  const { data: roster, isLoading: rosterLoading, error: rosterError, refetch: refetchRoster } = useQuery({
    queryKey: ['waiting-roster', { orderBy, page }],
    queryFn: () => hrApi.reports.waitingRoster({ order_by: orderBy, page, per_page: 15 }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<Employee, unknown>[]>(
    () => [
      {
        header: t('option.label.employee'),
        accessorKey: 'full_name',
        cell: ({ row }) => (
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
              {initialsOf(row.original.full_name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium text-ink">{row.original.full_name}</p>
              <p className="truncate text-xs text-ink-muted">{row.original.employee_number}</p>
            </div>
          </div>
        ),
      },
      {
        header: t('option.label.category'),
        accessorKey: 'employee_category_name',
        cell: ({ row }) => <span className="text-ink">{row.original.employee_category_name ?? '—'}</span>,
      },
      {
        header: t('option.pipeline.waitingDuration'),
        accessorKey: 'waiting_since',
        cell: ({ row }) =>
          row.original.waiting_since ? (
            <span className="text-ink">{formatDurationSince(row.original.waiting_since)}</span>
          ) : (
            <span className="text-xs italic text-ink-faint">{t('option.pipeline.unavailable')}</span>
          ),
      },
      {
        header: t('option.pipeline.applied'),
        accessorKey: 'application_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.application_date)}</span>,
      },
    ],
    [t],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.waitingAnalytics')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.waitingAnalytics') }]}
      />

      {summaryLoading && <LoadingState label={t('option.pipeline.loadingAnalytics')} />}
      {summaryError && <ErrorState error={summaryError} onRetry={refetchSummary} />}

      {summary && (
        <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.pipeline.waitingDuration')}</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              <Row label={t('option.pipeline.under7Days')} value={summary.waiting_duration_buckets.under_7_days} />
              <Row label={t('option.pipeline.days7to30')} value={summary.waiting_duration_buckets.seven_to_30_days} />
              <Row label={t('option.pipeline.days30to90')} value={summary.waiting_duration_buckets.thirty_to_90_days} />
              <Row label={t('option.pipeline.over90Days')} value={summary.waiting_duration_buckets.over_90_days} />
              <Row label={t('option.pipeline.unavailableNoWaitingSince')} value={summary.waiting_duration_buckets.unavailable} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.pipeline.byCategory')}</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {summary.waiting_by_category.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('option.pipeline.noOneCurrentlyWaiting')}</p>
              ) : (
                summary.waiting_by_category.map((category) => <Row key={category.id} label={category.name} value={category.total} />)
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.pipeline.byGenderLocation')}</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(summary.waiting_by_gender).map(([gender, total]) => (
                <Row key={gender} label={tValue('option.gender', gender) ?? gender} value={total ?? 0} capitalize />
              ))}
              {summary.waiting_by_location.slice(0, 5).map((entry) => (
                <Row key={entry.location} label={entry.location} value={entry.total} />
              ))}
            </CardBody>
          </Card>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <label className="text-xs font-medium text-ink-muted">{t('option.pipeline.sortBy')}</label>
          <Select
            value={orderBy}
            onChange={(e) => {
              setOrderBy(e.target.value as 'oldest' | 'newest');
              setPage(1);
            }}
            options={[
              { value: 'oldest', label: t('option.pipeline.oldestFirst') },
              { value: 'newest', label: t('option.pipeline.newestFirst') },
            ]}
            className="w-40"
          />
        </div>

        <DataTable
          columns={columns}
          data={roster?.data ?? []}
          isLoading={rosterLoading}
          error={rosterError}
          onRetry={refetchRoster}
          onRowClick={(employee) => navigate(`/hr/employees/${employee.id}`, { state: returnState(window.location.pathname, window.location.search, 'Waiting Analytics') })}
          emptyTitle={t('option.pipeline.noOneWaiting')}
          emptyDescription={t('option.pipeline.waitingEmptyDescription')}
        />

        {roster && roster.meta.total > 0 && <Pagination meta={roster.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}

function Row({ label, value, capitalize = false }: { label: string; value: number; capitalize?: boolean }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className={`text-ink-muted ${capitalize ? 'capitalize' : ''}`}>{label}</span>
      <span className="font-semibold text-ink">{formatNumber(value)}</span>
    </div>
  );
}
