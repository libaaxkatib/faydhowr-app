import { useCallback, useMemo, useState } from 'react';
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
import { inputClasses } from '@/components/ui/FormField';
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

const BOOLEAN_FILTER_OPTIONS = [
  { value: 'true', label: 'Yes' },
  { value: 'false', label: 'No' },
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
  /**
   * When set (Damiin Needed nav entry), locks the list to the active-work-queue
   * definition: guarantor_needed=true AND damiin_completed=false. Deliberately
   * NOT the same as the generic "Damiin: Yes" filter (guarantor_needed alone,
   * see the interactive `guarantorNeeded` state below) — that one intentionally
   * keeps showing the full historical population for audit/reporting even after
   * someone completes Damiin. Also deliberately NOT pipeline_stage='damiin_needed'
   * — that's a separate, pre-Excel-migration workflow value, independent of
   * guarantor_needed, with 0 migrated employees on it.
   */
  filterDamiinActive?: boolean;
  /**
   * When set (Rejected (Historical) nav entry), locks the list to employees
   * migrated from the CANCELED sheet / RED REGISTRATION rows — see Issue #7.
   * Deliberately NOT pipeline_stage='rejected' (the live workflow's own
   * outcome from RecordPracticalDecisionAction) — the two never overlap and
   * must never be shown as the same thing.
   */
  filterHistoricalRejected?: boolean;
  title?: string;
  breadcrumbLabel?: string;
}

export function EmployeesListPage({
  fixedStatus,
  fixedPipelineStage,
  filterIsSupervisor,
  filterOfficeOnly,
  filterDamiinActive,
  filterHistoricalRejected,
  title = 'Employees',
  breadcrumbLabel = 'Employees',
}: EmployeesListPageProps) {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<EmployeeStatus | ''>('');
  const [employeeCategoryId, setEmployeeCategoryId] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [location, setLocation] = useState('');
  const [profileComplete, setProfileComplete] = useState<'' | 'true' | 'false'>('');
  const [guarantorNeeded, setGuarantorNeeded] = useState<'' | 'true' | 'false'>('');
  const [isSupervisorFilter, setIsSupervisorFilter] = useState<'' | 'true' | 'false'>('');
  const [applicationDateFrom, setApplicationDateFrom] = useState('');
  const [applicationDateTo, setApplicationDateTo] = useState('');
  const [joiningDateFrom, setJoiningDateFrom] = useState('');
  const [joiningDateTo, setJoiningDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const debouncedSearch = useDebouncedValue(search);
  const debouncedLocation = useDebouncedValue(location);
  const effectiveStatus = fixedStatus ?? (status || undefined);
  const isLockedNavView = Boolean(
    fixedStatus || fixedPipelineStage || filterIsSupervisor || filterOfficeOnly || filterDamiinActive || filterHistoricalRejected,
  );
  const effectiveIsSupervisor = filterIsSupervisor ?? (isSupervisorFilter === '' ? undefined : isSupervisorFilter === 'true');
  // Deliberately independent of filterDamiinActive — this is the generic "Damiin:
  // Yes/No" dropdown's own raw guarantor_needed filter, unaffected by the queue.
  const effectiveGuarantorNeeded = guarantorNeeded === '' ? undefined : guarantorNeeded === 'true';

  const { data: categories } = useQuery({ queryKey: ['employee-categories'], queryFn: hrApi.employeeCategories.list });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: [
      'employees',
      {
        search: debouncedSearch,
        status: effectiveStatus,
        pipeline_stage: fixedPipelineStage,
        employee_category_id: employeeCategoryId,
        department_id: departmentId,
        location: debouncedLocation,
        is_supervisor: effectiveIsSupervisor,
        office_only: filterOfficeOnly,
        profile_complete: profileComplete,
        guarantor_needed: effectiveGuarantorNeeded,
        damiin_active: filterDamiinActive,
        historical_rejected: filterHistoricalRejected,
        application_date_from: applicationDateFrom,
        application_date_to: applicationDateTo,
        joining_date_from: joiningDateFrom,
        joining_date_to: joiningDateTo,
        page,
      },
    ],
    queryFn: () =>
      hrApi.employees.list({
        search: debouncedSearch || undefined,
        status: effectiveStatus,
        pipeline_stage: fixedPipelineStage,
        employee_category_id: employeeCategoryId ? Number(employeeCategoryId) : undefined,
        department_id: departmentId ? Number(departmentId) : undefined,
        location: debouncedLocation || undefined,
        is_supervisor: effectiveIsSupervisor,
        office_only: filterOfficeOnly || undefined,
        profile_complete: profileComplete === '' ? undefined : profileComplete === 'true',
        guarantor_needed: effectiveGuarantorNeeded,
        damiin_active: filterDamiinActive || undefined,
        historical_rejected: filterHistoricalRejected || undefined,
        application_date_from: applicationDateFrom || undefined,
        application_date_to: applicationDateTo || undefined,
        joining_date_from: joiningDateFrom || undefined,
        joining_date_to: joiningDateTo || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  function resetPageAnd<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      setPage(1);
    };
  }

  // Issue #10: Employee Detail should be able to return to wherever the HR user
  // actually came from (Need Training, Damiin, Waiting, etc.), not always the
  // generic Employees list. Carried via router state rather than the URL, since
  // this page's own filters live in component state, not query params.
  const goToEmployee = useCallback(
    (employeeId: number) => {
      navigate(`/hr/employees/${employeeId}`, {
        state: { fromPath: `${window.location.pathname}${window.location.search}`, fromLabel: breadcrumbLabel },
      });
    },
    [navigate, breadcrumbLabel],
  );

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
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.employee_category_name ?? '—'}
            {row.original.category_specialization && <span className="text-ink-muted"> · {row.original.category_specialization}</span>}
          </span>
        ),
      },
      {
        header: 'Department',
        accessorKey: 'department_name',
        cell: ({ row }) => <span className="text-ink">{row.original.department_name ?? '—'}</span>,
      },
      {
        header: 'Gender',
        accessorKey: 'gender',
        cell: ({ row }) => (
          <span className="text-ink">
            {row.original.gender ? row.original.gender[0].toUpperCase() + row.original.gender.slice(1) : 'Not recorded'}
          </span>
        ),
      },
      {
        header: 'Phone',
        accessorKey: 'phone',
        cell: ({ row }) => <span className="text-ink">{row.original.phone ?? '—'}</span>,
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
        header: 'Profile',
        id: 'profile_complete',
        cell: ({ row }) =>
          row.original.profile_complete ? (
            <StatusBadge status="complete" tone="success" label="Complete" />
          ) : (
            <StatusBadge status="incomplete" tone="warning" label="Incomplete" />
          ),
      },
      {
        header: 'Damiin',
        id: 'guarantor_needed',
        cell: ({ row }) =>
          row.original.guarantor_needed ? (
            <StatusBadge status="needed" tone="danger" label="Needed" />
          ) : (
            <StatusBadge status="not-needed" tone="neutral" label="Not needed" />
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
              goToEmployee(row.original.id);
            }}
            className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ),
      },
    ],
    [goToEmployee],
  );

  return (
    <div>
      <PageHeader
        title={title}
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: breadcrumbLabel }]}
        actions={
          !isLockedNavView ? (
            <PermissionGate module="hr">
              <Button onClick={() => setIsCreateOpen(true)}>
                <Icon name="plus" size={15} />
                Register Employee
              </Button>
            </PermissionGate>
          ) : undefined
        }
      />

      {filterHistoricalRejected && (
        <div className="mb-4 rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm text-ink-muted">
          These employees were migrated as already cancelled/rejected from historical Excel records (CANCELED sheet / RED
          REGISTRATION rows). This list is separate from the live recruitment workflow's own <strong>Rejected</strong> queue and
          never affects it.
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={resetPageAnd(setSearch)}
            placeholder="Search by name, phone, or employee number…"
            className="w-full max-w-xs"
          />
          {!isLockedNavView && (
            <>
              <Select
                value={status}
                onChange={(event) => resetPageAnd(setStatus)(event.target.value as EmployeeStatus | '')}
                options={STATUS_OPTIONS}
                placeholder="All statuses"
                className="w-40"
              />
              <Select
                value={employeeCategoryId}
                onChange={(event) => resetPageAnd(setEmployeeCategoryId)(event.target.value)}
                options={(categories ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
                placeholder="All categories"
                className="w-44"
              />
              <Select
                value={departmentId}
                onChange={(event) => resetPageAnd(setDepartmentId)(event.target.value)}
                options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
                placeholder="All departments"
                className="w-44"
              />
              <input
                value={location}
                onChange={(event) => resetPageAnd(setLocation)(event.target.value)}
                placeholder="Location…"
                className={`${inputClasses} w-40`}
              />
              <Select
                value={profileComplete}
                onChange={(event) => resetPageAnd(setProfileComplete)(event.target.value as '' | 'true' | 'false')}
                options={BOOLEAN_FILTER_OPTIONS}
                placeholder="Profile: any"
                className="w-36"
              />
              <Select
                value={guarantorNeeded}
                onChange={(event) => resetPageAnd(setGuarantorNeeded)(event.target.value as '' | 'true' | 'false')}
                options={BOOLEAN_FILTER_OPTIONS}
                placeholder="Damiin: any"
                className="w-36"
              />
              <Select
                value={isSupervisorFilter}
                onChange={(event) => resetPageAnd(setIsSupervisorFilter)(event.target.value as '' | 'true' | 'false')}
                options={BOOLEAN_FILTER_OPTIONS}
                placeholder="Supervisor: any"
                className="w-40"
              />
            </>
          )}
        </div>

        {!isLockedNavView && (
          <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <label className="flex items-center gap-2 text-xs text-ink-muted">
              Applied
              <input
                type="date"
                value={applicationDateFrom}
                onChange={(event) => resetPageAnd(setApplicationDateFrom)(event.target.value)}
                className={`${inputClasses} w-36`}
              />
              to
              <input
                type="date"
                value={applicationDateTo}
                onChange={(event) => resetPageAnd(setApplicationDateTo)(event.target.value)}
                className={`${inputClasses} w-36`}
              />
            </label>
            <label className="flex items-center gap-2 text-xs text-ink-muted">
              Joined
              <input
                type="date"
                value={joiningDateFrom}
                onChange={(event) => resetPageAnd(setJoiningDateFrom)(event.target.value)}
                className={`${inputClasses} w-36`}
              />
              to
              <input
                type="date"
                value={joiningDateTo}
                onChange={(event) => resetPageAnd(setJoiningDateTo)(event.target.value)}
                className={`${inputClasses} w-36`}
              />
            </label>
          </div>
        )}

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          onRowClick={(employee) => goToEmployee(employee.id)}
          emptyTitle="No employees found"
          emptyDescription="Try adjusting your search or filters."
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <EmployeeFormDialog isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} mode="create" />
    </div>
  );
}
