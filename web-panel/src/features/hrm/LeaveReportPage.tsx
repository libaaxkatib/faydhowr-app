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
import type { EmployeeLeave, LeaveType } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';

const LEAVE_TYPE_OPTIONS: { value: LeaveType; label: string }[] = [
  { value: 'annual', label: 'Annual' },
  { value: 'sick', label: 'Sick' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'other', label: 'Other' },
];

/** docs/HRM_MARKETING_SRS.md HR Phase 6: company-wide Leave list - previously only visible one employee at a time. */
export function LeaveReportPage() {
  const { t, tValue } = useI18n();
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  const [departmentId, setDepartmentId] = useState('');
  const [leaveType, setLeaveType] = useState<LeaveType | ''>('');
  const [page, setPage] = useState(1);

  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['leave-report', { departmentId, leaveType, from, to, page }],
    queryFn: () =>
      hrApi.reports.leaves({
        department_id: departmentId ? Number(departmentId) : undefined,
        leave_type: leaveType || undefined,
        from: from || undefined,
        to: to || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<EmployeeLeave, unknown>[]>(
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
        header: t('option.label.type'),
        accessorKey: 'leave_type_label',
        cell: ({ row }) => <span className="text-ink">{tValue('option.leaveType', row.original.leave_type) ?? row.original.leave_type_label}</span>,
      },
      {
        header: t('option.label.period'),
        id: 'period',
        cell: ({ row }) => (
          <span className="text-ink-muted">
            {formatDate(row.original.start_date)} – {formatDate(row.original.end_date)}
          </span>
        ),
      },
      {
        header: t('option.reports.colRecordedBy'),
        accessorKey: 'recorded_by',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.recorded_by ?? '—'}</span>,
      },
    ],
    [t, tValue],
  );

  return (
    <div>
      <PageHeader
        title={t('nav.leaveReport')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports'), to: '/hr/reports' }, { label: t('nav.leaveReport') }]}
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
            placeholder={t('option.pipeline.allDepartments')}
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
            className="w-44"
          />
          <Select
            value={leaveType}
            onChange={(e) => {
              setLeaveType(e.target.value as LeaveType | '');
              setPage(1);
            }}
            placeholder={t('option.pipeline.allLeaveTypes')}
            options={LEAVE_TYPE_OPTIONS.map((o) => ({ value: o.value, label: tValue('option.leaveType', o.value) ?? o.label }))}
            className="w-40"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle={t('option.reports.leavesEmptyTitle')}
          emptyDescription={t('option.reports.leavesEmptyBody')}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>
    </div>
  );
}
