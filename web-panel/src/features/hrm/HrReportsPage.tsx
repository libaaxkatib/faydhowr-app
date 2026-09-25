import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import { formatNumber } from '@/utils/formatters';
import type { WorkAssignmentStatus } from '@/types/employee';

const REPORT_LINKS: { label: string; to: string }[] = [
  { label: 'Waiting Analytics', to: '/hr/reports/waiting-analytics' },
  { label: 'Workforce Request History', to: '/hr/reports/workforce-requests' },
  { label: 'Temporary Replacement History', to: '/hr/reports/temporary-replacements' },
  { label: 'Leave Report', to: '/hr/reports/leaves' },
  { label: 'Performance Report', to: '/hr/reports/performance-reviews' },
  { label: 'Financial Ledger', to: '/hr/reports/financial-ledger' },
  { label: 'Payroll Rollup', to: '/hr/reports/payroll-rollup' },
];

const ASSIGNMENT_STATUS_OPTIONS: { value: WorkAssignmentStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'cancelled', label: 'Cancelled' },
];

const STATUS_LABELS: Record<string, string> = {
  applicant: 'Applicant',
  recruitment: 'Recruitment',
  practical: 'Practical',
  waiting: 'Waiting',
  approved: 'Approved',
  active: 'Active',
  inactive: 'Inactive',
};

const PIPELINE_STAGE_LABELS: Record<string, string> = {
  damiin_needed: 'Damiin Needed',
  contract_pending: 'Contract Pending',
  uniform_pending: 'Uniform Pending',
  need_training: 'Need Training',
  need_practical: 'Need Practical',
  practical_repeat: 'Practical Repeat',
  rejected: 'Rejected',
};

export function HrReportsPage() {
  const { preset, setPreset, from, to, setFrom, setTo, options: presetOptions } = useDateRangePreset();
  const [clientCompanyId, setClientCompanyId] = useState('');
  const [workLocationId, setWorkLocationId] = useState('');
  const [assignmentStatus, setAssignmentStatus] = useState<WorkAssignmentStatus | ''>('');

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['hr-reports-summary', { from, to, clientCompanyId, workLocationId, assignmentStatus }],
    queryFn: () =>
      hrApi.reportsSummary({
        from: from || undefined,
        to: to || undefined,
        client_company_id: clientCompanyId ? Number(clientCompanyId) : undefined,
        work_location_id: workLocationId ? Number(workLocationId) : undefined,
        assignment_status: assignmentStatus || undefined,
      }),
  });

  return (
    <div>
      <PageHeader title="HR Reports" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports' }]} />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-center gap-2 border-b border-border pb-3">
          {presetOptions.map((option) => (
            <Button
              key={option.value}
              type="button"
              size="sm"
              variant={preset === option.value ? 'primary' : 'outline'}
              className="h-8 px-3 text-xs"
              onClick={() => setPreset(option.value)}
            >
              {option.label}
            </Button>
          ))}
        </CardBody>
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Date from</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Date to</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Client company</label>
            <Select
              value={clientCompanyId}
              onChange={(e) => setClientCompanyId(e.target.value)}
              placeholder="All companies"
              options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
              className="w-44"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Work location</label>
            <Select
              value={workLocationId}
              onChange={(e) => setWorkLocationId(e.target.value)}
              placeholder="All locations"
              options={(locations ?? []).map((l) => ({ value: String(l.id), label: l.name }))}
              className="w-44"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Assignment status</label>
            <Select
              value={assignmentStatus}
              onChange={(e) => setAssignmentStatus(e.target.value as WorkAssignmentStatus | '')}
              placeholder="Active (default)"
              options={ASSIGNMENT_STATUS_OPTIONS}
              className="w-40"
            />
          </div>
        </CardBody>
      </Card>

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">Detailed Reports</h3>
        </CardHeader>
        <CardBody className="flex flex-wrap gap-2">
          {REPORT_LINKS.map((link) => (
            <Link key={link.to} to={link.to}>
              <Button type="button" size="sm" variant="outline" className="h-8 px-3 text-xs">
                {link.label}
              </Button>
            </Link>
          ))}
        </CardBody>
      </Card>

      {isLoading && <LoadingState label="Loading report…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Registrations</h3>
            </CardHeader>
            <CardBody>
              <p className="font-display text-3xl font-bold text-ink">{formatNumber(data.registrations_in_range)}</p>
              <p className="mt-1 text-xs text-ink-muted">
                {data.range.from} – {data.range.to}
              </p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Status</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.status_breakdown).map(([status, total]) => (
                <div key={status} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{STATUS_LABELS[status] ?? status}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Pipeline Breakdown</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.keys(data.pipeline_breakdown).length === 0 ? (
                <p className="text-sm text-ink-muted">No one currently in the pre-Waiting pipeline.</p>
              ) : (
                Object.entries(data.pipeline_breakdown).map(([stage, total]) => (
                  <div key={stage} className="flex items-center justify-between text-sm">
                    <span className="text-ink-muted">{PIPELINE_STAGE_LABELS[stage] ?? stage}</span>
                    <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                  </div>
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Category</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.category_breakdown.map((category) => (
                <div key={category.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{category.name}</span>
                  <span className="font-semibold text-ink">{formatNumber(category.total)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card className="lg:col-span-3">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Department</h3>
            </CardHeader>
            <CardBody>
              {data.department_breakdown.length === 0 ? (
                <p className="text-sm text-ink-muted">No departments configured yet.</p>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {data.department_breakdown.map((department) => (
                    <div key={department.id} className="rounded-md border border-border p-3 text-center">
                      <p className="font-display text-lg font-bold text-ink">{formatNumber(department.total)}</p>
                      <p className="text-xs text-ink-muted">{department.name}</p>
                    </div>
                  ))}
                </div>
              )}
            </CardBody>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Workplace</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.workplace_breakdown.map((location) => (
                <div key={location.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">
                    {location.location_type === 'office' ? location.name : `${location.client_company_name} · ${location.name}`}
                  </span>
                  <span className="font-semibold text-ink">
                    {formatNumber(location.total)}
                    {location.capacity !== null && ` / ${location.capacity}`}
                  </span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Salary by Company</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.company_salary_totals.length === 0 ? (
                <p className="text-sm text-ink-muted">No company-assigned salaries yet.</p>
              ) : (
                data.company_salary_totals.map((company) => (
                  <div key={company.id} className="flex items-center justify-between text-sm">
                    <span className="text-ink-muted">{company.name}</span>
                    <span className="font-semibold text-ink">{company.total_salary} ({company.total_assignments})</span>
                  </div>
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Penalties by Department</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.penalty_totals_by_department.length === 0 ? (
                <p className="text-sm text-ink-muted">No penalties recorded in this range.</p>
              ) : (
                data.penalty_totals_by_department.map((department) => (
                  <div key={department.id} className="flex items-center justify-between text-sm">
                    <span className="text-ink-muted">{department.name}</span>
                    <span className="font-semibold text-ink">{department.total_deducted} ({department.total_penalties})</span>
                  </div>
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Advances by Department</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.advance_totals_by_department.length === 0 ? (
                <p className="text-sm text-ink-muted">No salary advances recorded in this range.</p>
              ) : (
                data.advance_totals_by_department.map((department) => (
                  <div key={department.id} className="flex items-center justify-between text-sm">
                    <span className="text-ink-muted">{department.name}</span>
                    <span className="font-semibold text-ink">{department.total_advanced} ({department.total_advances})</span>
                  </div>
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Waiting Duration</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              <BreakdownRow label="Under 7 days" value={data.waiting_duration_buckets.under_7_days} />
              <BreakdownRow label="7–30 days" value={data.waiting_duration_buckets.seven_to_30_days} />
              <BreakdownRow label="30–90 days" value={data.waiting_duration_buckets.thirty_to_90_days} />
              <BreakdownRow label="Over 90 days" value={data.waiting_duration_buckets.over_90_days} />
              <BreakdownRow label="Unavailable (no waiting_since)" value={data.waiting_duration_buckets.unavailable} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Waiting by Category</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.waiting_by_category.length === 0 ? (
                <p className="text-sm text-ink-muted">No one currently waiting.</p>
              ) : (
                data.waiting_by_category.map((category) => <BreakdownRow key={category.id} label={category.name} value={category.total} />)
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Workforce Requests by Company</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.workforce_requests_by_company.length === 0 ? (
                <p className="text-sm text-ink-muted">No workforce requests recorded.</p>
              ) : (
                data.workforce_requests_by_company.map((company) => <BreakdownRow key={company.name} label={company.name} value={company.total} />)
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Active Workforce by Gender</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.keys(data.active_by_gender).length === 0 ? (
                <p className="text-sm text-ink-muted">No active employees with a recorded gender.</p>
              ) : (
                Object.entries(data.active_by_gender).map(([gender, total]) => (
                  <BreakdownRow key={gender} label={gender} value={total ?? 0} capitalize />
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Temporary Replacements by Company</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.replacement_totals_by_company.length === 0 ? (
                <p className="text-sm text-ink-muted">No temporary replacements recorded.</p>
              ) : (
                data.replacement_totals_by_company.map((company) => (
                  <div key={company.name} className="flex items-center justify-between text-sm">
                    <span className="text-ink-muted">{company.name}</span>
                    <span className="font-semibold text-ink">{company.total_paid} ({company.total_replacements})</span>
                  </div>
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Separations & Rehires</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.keys(data.separation_reason_breakdown).length === 0 ? (
                <p className="text-sm text-ink-muted">No separations recorded in this range.</p>
              ) : (
                Object.entries(data.separation_reason_breakdown).map(([reason, total]) => (
                  <BreakdownRow key={reason} label={reason.replace(/_/g, ' ')} value={total ?? 0} capitalize />
                ))
              )}
              <BreakdownRow label="Rehired in range" value={data.rehire_count_in_range} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Supervisor Pool & Office Staff</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              <BreakdownRow label="Nominated supervisors in range" value={data.supervisors_since_range} />
              {data.office_staff_by_department.map((department) => (
                <BreakdownRow key={department.id} label={`Office · ${department.name}`} value={department.total} />
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Attendance Breakdown</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.keys(data.attendance_breakdown).length === 0 ? (
                <p className="text-sm text-ink-muted">No attendance recorded in this range.</p>
              ) : (
                Object.entries(data.attendance_breakdown).map(([status, total]) => (
                  <BreakdownRow key={status} label={status} value={total ?? 0} capitalize />
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Performance Reviews by Rating</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.keys(data.reviews_by_rating).length === 0 ? (
                <p className="text-sm text-ink-muted">No performance reviews recorded in this range.</p>
              ) : (
                Object.entries(data.reviews_by_rating).map(([rating, total]) => (
                  <BreakdownRow key={rating} label={rating.replace(/_/g, ' ')} value={total ?? 0} capitalize />
                ))
              )}
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}

function BreakdownRow({ label, value, capitalize = false }: { label: string; value: number; capitalize?: boolean }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className={`text-ink-muted ${capitalize ? 'capitalize' : ''}`}>{label}</span>
      <span className="font-semibold text-ink">{formatNumber(value)}</span>
    </div>
  );
}
