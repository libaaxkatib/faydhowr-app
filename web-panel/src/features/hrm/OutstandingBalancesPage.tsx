import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { Card } from '@/components/ui/Card';
import { DataTable } from '@/components/ui/DataTable';
import { ListResultBar, ResetFiltersAction, ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { PageHeader } from '@/components/ui/PageHeader';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useI18n } from '@/i18n/useI18n';
import { BALANCE_FILTER_KEYS, balanceRowView, balanceTotalsView, parseBalanceFilters, type BalanceRowView } from '@/utils/deductions';
import type { DeductionBalanceRow } from '@/types/payroll';

interface TableRow {
  view: BalanceRowView;
  employeeId: number;
}

/**
 * Outstanding balances: the penalties and one-month advances payroll could not deduct yet (the net is never below $0), so they stay owed and are taken from
 * the next months' pay. Read-only: nothing here is a debt, a payment or a forgiveness. People who no longer work at the company are marked "Left the company".
 * The filters (company, kind, left the company only) live in the address bar. Everything shown comes from the server.
 */
export function OutstandingBalancesPage() {
  const { t } = useI18n();
  const url = useUrlFilters();
  const filters = parseBalanceFilters((key) => url.get(key));
  const filtered = url.activeCount(BALANCE_FILTER_KEYS) > 0;
  const reset = () => url.reset(BALANCE_FILTER_KEYS);

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['deduction-balances', filters],
    queryFn: () => hrApi.payrollRuns.deductionBalances(filters),
    placeholderData: (previous) => previous,
    // The figures change whenever a payroll is finalized or a record is voided / waived: always show the current picture.
    staleTime: 0,
  });

  const rows = useMemo<TableRow[]>(() => (data?.rows ?? []).map((row: DeductionBalanceRow) => ({ view: balanceRowView(row), employeeId: row.employee_id })), [data]);
  const totals = data ? balanceTotalsView(data) : null;

  const columns = useMemo<ColumnDef<TableRow, unknown>[]>(
    () => [
      {
        header: t('option.label.employee'),
        id: 'employee',
        cell: ({ row }) => (
          <div className="min-w-0">
            <Link to={`/hr/employees/${row.original.employeeId}`} className="truncate font-medium text-primary hover:underline">
              {row.original.view.employeeName}
            </Link>
            {row.original.view.employeeNumber && <p className="truncate text-xs text-ink-muted">{row.original.view.employeeNumber}</p>}
            {row.original.view.leaver && (
              <div className="mt-1">
                <StatusBadge status="leaver" tone="warning" label={t('status.deduction.leaver')} />
              </div>
            )}
          </div>
        ),
      },
      { header: t('option.label.company'), id: 'company', cell: ({ row }) => <span className="text-ink-muted">{row.original.view.companyName}</span> },
      {
        header: t('option.label.type'),
        id: 'kind',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="text-ink">{t(row.original.view.kindKey)}</p>
            {row.original.view.reason && <p className="max-w-[16rem] truncate text-xs text-ink-muted" title={row.original.view.reason}>{row.original.view.reason}</p>}
          </div>
        ),
      },
      { header: t('option.deduction.colRegisteredMonth'), id: 'period', cell: ({ row }) => <span className="text-ink-muted">{row.original.view.period}</span> },
      { header: t('option.deduction.colRegistered'), id: 'registered', cell: ({ row }) => <span className="whitespace-nowrap text-ink">{row.original.view.registered}</span> },
      { header: t('option.deduction.colDeducted'), id: 'deducted', cell: ({ row }) => <span className="whitespace-nowrap text-ink">{row.original.view.deducted}</span> },
      { header: t('option.deduction.colWaived'), id: 'waived', cell: ({ row }) => <span className="whitespace-nowrap text-ink">{row.original.view.waived}</span> },
      { header: t('option.deduction.colOwed'), id: 'owed', cell: ({ row }) => <span className="whitespace-nowrap font-semibold text-ink">{row.original.view.owed}</span> },
      {
        header: t('option.deduction.colState'),
        id: 'state',
        cell: ({ row }) => <StatusBadge status={row.original.view.stateKey} tone={row.original.view.stateKey === 'status.deduction.statePartly' ? 'info' : 'neutral'} label={t(row.original.view.stateKey)} />,
      },
    ],
    [t],
  );

  return (
    <div>
      <PageHeader title={t('nav.outstandingBalances')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.payroll'), to: '/hr/payroll' }, { label: t('nav.outstandingBalances') }]} />

      <p className="mb-4 max-w-3xl text-sm text-ink-muted" data-testid="balances-help">{t('option.deduction.balancesHelp')}</p>

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <Select
            value={url.get('company')}
            onChange={(e) => url.set('company', e.target.value)}
            placeholder={t('option.label.allCompanies')}
            options={(companies ?? []).map((company) => ({ value: String(company.id), label: company.name }))}
            className="w-56"
          />
          <Select
            value={url.get('kind')}
            onChange={(e) => url.set('kind', e.target.value)}
            placeholder={t('option.deduction.allKinds')}
            options={[
              { value: 'penalty', label: t('option.deduction.kindPenalty') },
              { value: 'advance', label: t('option.deduction.kindAdvance') },
            ]}
            className="w-44"
          />
          <label className="flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" className="h-4 w-4" checked={filters.leavers_only === true} onChange={(e) => url.set('leavers', e.target.checked ? '1' : '')} />
            {t('option.deduction.leaversOnly')}
          </label>
          <ResetFiltersButton count={url.activeCount(BALANCE_FILTER_KEYS)} onReset={reset} />
        </div>
        {totals && <ListResultBar matching={totals.count} filtered={filtered} />}

        <DataTable
          columns={columns}
          data={rows}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          label={t('nav.outstandingBalances')}
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('option.deduction.emptyTitle')}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('option.deduction.emptyBody')}
          emptyAction={filtered ? <ResetFiltersAction onReset={reset} /> : undefined}
        />

        {totals && totals.count > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-surface-alt px-5 py-3 text-sm" data-testid="balances-totals">
            <p className="font-semibold text-ink">{t('option.deduction.totalsRow', { count: totals.count, amount: totals.owed })}</p>
            {totals.leaverCount > 0 && <p className="text-ink-muted">{t('option.deduction.leaverTotals', { count: totals.leaverCount, amount: totals.leaverOwed })}</p>}
          </div>
        )}
      </Card>
    </div>
  );
}
