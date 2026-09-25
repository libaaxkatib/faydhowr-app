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
import type { PayrollRollupRow } from '@/types/employee';

function currentPeriod(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: multi-employee payroll rollup. Every
 * row comes from GetPayrollRollupAction, which calls the SAME, unmodified
 * GetEmployeePayrollSummaryAction used by the per-employee Payroll Summary
 * card - the formula is never re-derived here. Overtime is deliberately never
 * shown as a number; office rows get a static "pending" note only.
 */
export function PayrollRollupPage() {
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

  const totalNetPayable = useMemo(
    () => (data ?? []).reduce((sum, row) => sum + Number(row.net_payable), 0),
    [data],
  );

  const columns = useMemo<ColumnDef<PayrollRollupRow, unknown>[]>(
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
        header: 'Monthly Salary',
        accessorKey: 'monthly_salary',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.monthly_salary} {row.original.currency}
          </span>
        ),
      },
      {
        header: 'Absence',
        id: 'absence',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {row.original.absent_days}d · {row.original.absence_deduction}
          </span>
        ),
      },
      {
        header: 'Penalties',
        accessorKey: 'penalty_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.penalty_deduction}</span>,
      },
      {
        header: 'Advances',
        accessorKey: 'advance_deduction',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.advance_deduction}</span>,
      },
      {
        header: 'Net Payable',
        accessorKey: 'net_payable',
        cell: ({ row }) => <span className="font-semibold text-ink">{row.original.net_payable}</span>,
      },
      {
        header: 'Overtime',
        id: 'overtime',
        cell: ({ row }) =>
          row.original.location_type === 'office' ? (
            <span className="text-xs italic text-ink-faint">Pending management decision</span>
          ) : (
            <span className="text-xs text-ink-faint">—</span>
          ),
      },
    ],
    [],
  );

  return (
    <div>
      <PageHeader
        title="Payroll Rollup"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Payroll Rollup' }]}
      />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Period</label>
            <input
              type="month"
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
              className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Client company</label>
            <Select
              value={clientCompanyId}
              onChange={(e) => setClientCompanyId(e.target.value)}
              placeholder="All companies"
              options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
              className="w-48"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Department</label>
            <Select
              value={departmentId}
              onChange={(e) => setDepartmentId(e.target.value)}
              placeholder="All departments"
              options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
              className="w-44"
            />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label="Loading payroll rollup…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <Card>
          {data.length === 0 ? (
            <EmptyState title="No matching assignments" description="No active work assignments matched the selected period and filters." />
          ) : (
            <>
              <div className="flex items-center justify-between border-b border-border px-5 py-3">
                <span className="text-sm text-ink-muted">{data.length} employee(s)</span>
                <span className="text-sm font-semibold text-ink">Total Net Payable: {totalNetPayable.toFixed(2)}</span>
              </div>
              <DataTable columns={columns} data={data} />
            </>
          )}
        </Card>
      )}
    </div>
  );
}
