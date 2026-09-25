import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { EmployeeFormDialog } from '@/features/hrm/EmployeeFormDialog';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { formatDate, initialsOf } from '@/utils/formatters';
import type { Employee, EmployeePipelineStage, EmployeeStatus } from '@/types/employee';

const STATUS_OPTIONS: { value: EmployeeStatus; label: string }[] = [
  { value: 'applicant', label: 'Applicant' },
  { value: 'recruitment', label: 'Recruitment' },
  { value: 'practical', label: 'Practical' },
  { value: 'waiting', label: 'Waiting' },
  { value: 'approved', label: 'Approved' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const PIPELINE_STAGE_LABELS: Record<string, string> = {
  damiin_needed: 'Damiin Needed',
  contract_pending: 'Contract Pending',
  uniform_pending: 'Uniform Pending',
  need_training: 'Need Training',
  need_practical: 'Need Practical',
  practical_repeat: 'Practical Repeat',
  rejected: 'Rejected',
};

interface EmployeesListPageProps {
  /** When set (Waiting nav entry), locks the list to one status and hides the status filter. */
  fixedStatus?: EmployeeStatus;
  /** When set (pipeline-queue nav entries), locks the list to one pipeline stage. */
  fixedPipelineStage?: EmployeePipelineStage;
  /** When set (Supervisor Pool nav entry), locks the list to is_supervisor=true. */
  filterIsSupervisor?: boolean;
  /** When set (Office Staff nav entry), locks the list to employees with an active office work assignment. */
  filterOfficeOnly?: boolean;
  title?: string;
  breadcrumbLabel?: string;
}

export function EmployeesListPage({
  fixedStatus,
  fixedPipelineStage,
  filterIsSupervisor,
  filterOfficeOnly,
  title = 'Employees',
  breadcrumbLabel = 'Employees',
}: EmployeesListPageProps) {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<EmployeeStatus | ''>('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);
  const effectiveStatus = fixedStatus ?? (status || undefined);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: [
      'employees',
      { search: debouncedSearch, status: effectiveStatus, pipeline_stage: fixedPipelineStage, is_supervisor: filterIsSupervisor, office_only: filterOfficeOnly, page },
    ],
    queryFn: () =>
      hrApi.employees.list({
        search: debouncedSearch || undefined,
        status: effectiveStatus,
        pipeline_stage: fixedPipelineStage,
        is_supervisor: filterIsSupervisor || undefined,
        office_only: filterOfficeOnly || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const columns = useMemo<ColumnDef<Employee, unknown>[]>(
    () => [
      {
        header: 'Employee',
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
        header: 'Category',
        accessorKey: 'employee_category_name',
        cell: ({ row }) => <span className="text-ink">{row.original.employee_category_name ?? '—'}</span>,
      },
      {
        header: 'Phone',
        accessorKey: 'phone',
      },
      {
        header: 'Workplace',
        id: 'workplace',
        cell: ({ row }) => {
          const assignments = row.original.active_work_assignments ?? [];
          if (assignments.length === 0) {
            return <span className="italic text-ink-faint">Unassigned</span>;
          }
          const labels = assignments.map((a) =>
            a.location_type === 'office' ? 'Fayadhowr Office' : (a.client_company_name ?? a.work_location_name),
          );
          return <span className="text-ink">{labels.join(', ')}</span>;
        },
      },
      {
        header: 'Status',
        accessorKey: 'status',
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <StatusBadge status={row.original.status} />
            {row.original.pipeline_stage && (
              <StatusBadge
                status={row.original.pipeline_stage}
                label={PIPELINE_STAGE_LABELS[row.original.pipeline_stage]}
                tone={row.original.pipeline_stage === 'rejected' ? 'danger' : 'warning'}
              />
            )}
          </div>
        ),
      },
      {
        header: 'Applied',
        accessorKey: 'application_date',
        cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.application_date)}</span>,
      },
      {
        header: '',
        id: 'actions',
        cell: ({ row }) => (
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              navigate(`/hr/employees/${row.original.id}`);
            }}
            className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ),
      },
    ],
    [navigate],
  );

  return (
    <div>
      <PageHeader
        title={title}
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: breadcrumbLabel }]}
        actions={
          !fixedStatus && !fixedPipelineStage && !filterIsSupervisor && !filterOfficeOnly ? (
            <PermissionGate module="hr">
              <Button onClick={() => setIsCreateOpen(true)}>
                <Icon name="plus" size={15} />
                Register Employee
              </Button>
            </PermissionGate>
          ) : undefined
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search by name, phone, or employee number…"
            className="w-full max-w-xs"
          />
          {!fixedStatus && !fixedPipelineStage && !filterIsSupervisor && !filterOfficeOnly && (
            <Select
              value={status}
              onChange={(event) => {
                setStatus(event.target.value as EmployeeStatus | '');
                setPage(1);
              }}
              options={STATUS_OPTIONS}
              placeholder="All statuses"
              className="w-44"
            />
          )}
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(employee) => navigate(`/hr/employees/${employee.id}`)}
          emptyTitle="No employees found"
          emptyDescription="Try adjusting your search or filters."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <EmployeeFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} mode="create" />
    </div>
  );
}
