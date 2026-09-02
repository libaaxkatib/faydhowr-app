import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';
import type { WorkAssignmentStatus } from '@/types/employee';

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

export function HrReportsPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
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
        </div>
      )}
    </div>
  );
}
