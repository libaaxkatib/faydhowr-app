import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Icon, type IconName } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatCurrency, formatNumber } from '@/utils/formatters';

export function HrDashboardPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['hr-dashboard'], queryFn: hrApi.dashboard });

  return (
    <div>
      <PageHeader title="HR Dashboard" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Dashboard' }]} />

      {isLoading && <LoadingState label="Loading HR dashboard…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <KpiCard label="Total Employees" value={data.total_employees} icon="users" color="bg-primary" to="/hr/employees" />
            <KpiCard label="Active" value={data.active_employees} icon="check" color="bg-success" to="/hr/employees" />
            <KpiCard label="Inactive" value={data.inactive_employees} icon="x" color="bg-danger" to="/hr/former-employees" />
            <KpiCard label="New Applicants (30d)" value={data.new_applicants} icon="plus" color="bg-secondary" />
            <KpiCard label="Practical" value={data.practical} icon="file-text" color="bg-purple-500" />
            <KpiCard label="Waiting" value={data.waiting} icon="calendar" color="bg-warning" to="/hr/waiting" />
            <KpiCard label="Open Workforce Requests" value={data.open_workforce_requests} icon="briefcase" color="bg-teal-600" to="/hr/workforce-requests" />
            <KpiCard label="Fulfilled Workforce Requests" value={data.fulfilled_workforce_requests} icon="briefcase" color="bg-teal-600" to="/hr/reports/workforce-requests" />
            <KpiCard label="Approved" value={data.approved} icon="shield" color="bg-teal-600" />
            <KpiCard label="Supervisor Pool" value={data.supervisor_pool_count} icon="shield" color="bg-primary" to="/hr/supervisor-pool" />
            <KpiCard label="Active Replacements" value={data.active_temporary_replacements} icon="refresh" color="bg-warning" to="/hr/temporary-replacements" />
            <KpiCard label="Office Staff" value={data.office_staff_count} icon="users" color="bg-secondary" to="/hr/office-staff" />
            <KpiCard label="Client Company Active Employees" value={data.client_company_active_employees} icon="briefcase" color="bg-secondary" />
          </div>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Today's Attendance</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <KpiCard label="Present Today" value={data.present_today} icon="check" color="bg-success" to="/hr/attendance" />
              <KpiCard label="Absent Today" value={data.absent_today} icon="x" color="bg-danger" to="/hr/attendance" />
              <KpiCard label="Late Today" value={data.late_today} icon="calendar" color="bg-warning" to="/hr/attendance" />
              <KpiCard label="Attendance Marked Today" value={data.attendance_marked_today} icon="calendar" color="bg-primary" to="/hr/attendance" />
            </CardBody>
          </Card>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">This Month's Financials</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <KpiCard label="Payments" value={data.month_payments_total} icon="credit-card" color="bg-success" to="/hr/reports/financial-ledger" isCurrency />
              <KpiCard label="Penalties" value={data.month_penalties_total} icon="x" color="bg-danger" to="/hr/reports/financial-ledger" isCurrency />
              <KpiCard label="Advances" value={data.month_advances_total} icon="credit-card" color="bg-warning" to="/hr/reports/financial-ledger" isCurrency />
            </CardBody>
          </Card>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Pipeline Queues</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              <KpiCard label="Damiin Needed" value={data.damiin_needed} icon="file-text" color="bg-secondary" to="/hr/pipeline/damiin" />
              <KpiCard label="Contract Pending" value={data.contract_pending} icon="file-text" color="bg-secondary" to="/hr/pipeline/contracts" />
              <KpiCard label="Uniform Pending" value={data.uniform_pending} icon="box" color="bg-warning" to="/hr/pipeline/uniform" />
              <KpiCard label="Need Training" value={data.need_training} icon="calendar" color="bg-primary" to="/hr/pipeline/training" />
              <KpiCard label="Need Practical" value={data.need_practical} icon="eye" color="bg-primary" to="/hr/pipeline/practical" />
              <KpiCard label="Practical Repeat" value={data.practical_repeat} icon="refresh" color="bg-warning" to="/hr/pipeline/practical-repeat" />
              <KpiCard label="Rejected" value={data.rejected} icon="x" color="bg-danger" to="/hr/pipeline/rejected" />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Category Breakdown</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              {data.category_breakdown.map((category) => (
                <div key={category.id} className="rounded-md border border-border p-3 text-center">
                  <p className="font-display text-lg font-bold text-ink">{formatNumber(category.total)}</p>
                  <p className="text-xs text-ink-muted">{category.name}</p>
                </div>
              ))}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}

function KpiCard({
  label,
  value,
  icon,
  color,
  to,
  isCurrency = false,
}: {
  label: string;
  value: number | string;
  icon: IconName;
  color: string;
  to?: string;
  isCurrency?: boolean;
}) {
  const displayValue = isCurrency ? formatCurrency(Number(value)) : formatNumber(Number(value));
  const content = (
    <div className="flex items-center gap-3">
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-white ${color}`}>
        <Icon name={icon} size={19} />
      </div>
      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-ink-muted">{label}</p>
        <p className="font-display text-xl font-bold text-ink">{displayValue}</p>
      </div>
    </div>
  );

  if (to) {
    return (
      <Link to={to} className="block">
        <Card className="p-5 transition hover:border-primary/40 hover:shadow-card">{content}</Card>
      </Link>
    );
  }

  return <Card className="p-5">{content}</Card>;
}
