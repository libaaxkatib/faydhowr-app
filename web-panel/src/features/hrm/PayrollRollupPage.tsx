import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { DataTable } from '@/components/ui/DataTable';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { Msg } from '@/i18n/Msg';
import { useI18n } from '@/i18n/useI18n';
import { localizeServerMessage } from '@/i18n/serverMessages';
import type { PayrollRollupRow } from '@/types/employee';

function currentPeriod(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: multi-employee payroll rollup. Every
 * row is the same line the payroll run holds (the formula is never re-derived
 * here), so it always agrees with the Payroll Summary card and the payroll run.
 * A month without an approved/finalized run is shown as an ESTIMATE. Overtime
 * is deliberately never shown as a number; office rows get a static "pending"
 * note only.
 */
export function PayrollRollupPage() {
  const { t, tValue, language } = useI18n();
  const [period, setPeriod] = useState(currentPeriod());
  const [clientCompanyId, setClientCompanyId] = useState('');
  const [departmentId, setDepartmentId] = useState('');

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['payroll-rollup', { period, clientCompanyId, departmentId }],
    queryFn: () =>
      hrApi.reports.payrollRollup({
        period,
        client_company_id: clientCompanyId ? Number(clientCompanyId) : undefined,
        department_id: departmentId ? Number(departmentId) : undefined,
      }),
    enabled: /^\d{4}-(0[1-9]|1[0-2])$/.test(period),
    placeholderData: (previous) => previous,
  });

  const rows = data?.rows ?? [];
  const meta = data?.meta;
  const runStatus = meta?.run_status ?? '';
  const runStatusLabel = tValue('option.payrollStatus', runStatus) ?? runStatus;

  const columns = useMemo<ColumnDef<PayrollRollupRow, unknown>[]>(
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
        header: t('option.payroll.rollupMonthlySalary'),
        accessorKey: 'monthly_salary',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.monthly_salary} {row.original.currency}
          </span>
        ),
      },
      {
        header: t('option.payroll.rollupDaysPaid'),
        id: 'days_paid',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {row.original.eligible_days}/{row.original.days_in_month}
            {row.original.final_pay && <span className="ml-1 text-xs text-warning">{t('option.payroll.rollupFinalPay')}</span>}
          </span>
        ),
      },
      {
        header: t('option.payroll.gross'),
        accessorKey: 'gross_pay',
        cell: ({ row }) => <span className="text-ink">{row.original.gross_pay}</span>,
      },
      {
        header: t('status.payroll.deduction.absence'),
        id: 'absence',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {t('option.payroll.rollupAbsence', { days: row.original.absent_days, amount: row.original.absence_deduction })}
          </span>
        ),
      },
      {
        header: t('option.payroll.penalties'),
        accessorKey: 'penalty_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.penalty_deduction}</span>,
      },
      {
        header: t('option.payroll.advances'),
        accessorKey: 'advance_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.advance_deduction}</span>,
      },
      {
        header: t('option.label.replacement'),
        accessorKey: 'replacement_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.replacement_deduction}</span>,
      },
      {
        header: t('status.payroll.deduction.unpaid_leave'),
        accessorKey: 'unpaid_leave_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.unpaid_leave_deduction}</span>,
      },
      {
        header: t('option.payroll.rollupNetPayable'),
        accessorKey: 'net_payable',
        cell: ({ row }) => <span className="font-semibold text-ink">{row.original.net_payable}</span>,
      },
      {
        header: t('option.payroll.rollupOvertime'),
        id: 'overtime',
        cell: ({ row }) =>
          row.original.location_type === 'office' ? (
            <span className="text-xs italic text-ink-faint">{t('option.payroll.rollupOvertimePending')}</span>
          ) : (
            <span className="text-xs text-ink-faint">—</span>
          ),
      },
    ],
    [t],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.payrollRollup')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.payrollRollup') }]}
      />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.period')}</label>
            <input
              type="month"
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
              className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.payroll.rollupClientCompany')}</label>
            <Select
              value={clientCompanyId}
              onChange={(e) => setClientCompanyId(e.target.value)}
              placeholder={t('option.label.allCompanies')}
              options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
              className="w-48"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.department')}</label>
            <Select
              value={departmentId}
              onChange={(e) => setDepartmentId(e.target.value)}
              placeholder={t('option.employees.allDepartments')}
              options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
              className="w-44"
            />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label={t('option.payroll.rollupLoading')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {meta && (
        <div
          data-testid="rollup-provenance"
          className={`mb-4 rounded-sm border px-4 py-3 text-sm ${meta.is_estimate ? 'border-warning/40 bg-warning/10 text-ink' : 'border-border bg-surface-muted text-ink-muted'}`}
        >
          {meta.is_estimate ? (
            <>
              <p className="font-semibold text-ink">{t('option.payroll.rollupEstimate')}</p>
              <p>{localizeServerMessage(language, meta.estimate_note ?? '')}</p>
              {meta.run_id && meta.differs_from_run && (
                <p className="mt-1 font-medium text-danger">
                  {t('warning.payroll.rollupStale', { status: runStatusLabel })}
                </p>
              )}
            </>
          ) : (
            <p>
              <Msg
                id="option.payroll.rollupFinalFigures"
                values={{ status: <span className="font-semibold text-ink">{runStatusLabel}</span>, period: meta.period }}
              />
            </p>
          )}
        </div>
      )}

      {meta && meta.warnings.length > 0 && (
        <ul data-testid="rollup-warnings" className="mb-4 list-disc space-y-1 rounded-sm border border-warning/40 bg-warning/10 py-3 pl-8 pr-4 text-xs text-ink">
          {meta.warnings.map((w) => (
            <li key={w}>{w}</li>
          ))}
        </ul>
      )}

      {data && (
        <Card>
          {rows.length === 0 ? (
            <EmptyState title={t('option.payroll.noLines')} description={t('option.payroll.rollupEmpty')} />
          ) : (
            <>
              <div className="flex items-center justify-between border-b border-border px-5 py-3">
                <span className="text-sm text-ink-muted">{t('option.payroll.rollupEmployeeCount', { n: rows.length })}</span>
                <span className="text-sm font-semibold text-ink">
                  {t(meta?.is_estimate ? 'option.payroll.rollupTotalEstimate' : 'option.payroll.rollupTotal', { amount: meta?.totals.net_payable ?? '' })}
                </span>
              </div>
              <DataTable columns={columns} data={rows} />
            </>
          )}
        </Card>
      )}
    </div>
  );
}
