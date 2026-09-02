import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatNumber } from '@/utils/formatters';
import type { MarketingRecordStatus, MarketingRecordType } from '@/types/marketing';

const STATUS_OPTIONS: { value: MarketingRecordStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'quotation', label: 'Quotation' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
];

const TYPE_OPTIONS: { value: MarketingRecordType; label: string }[] = [
  { value: 'xarun', label: 'XARUN' },
  { value: 'project', label: 'PROJECT' },
];

export function MarketingReportsPage() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [teamId, setTeamId] = useState('');
  const [adminId, setAdminId] = useState('');
  const [status, setStatus] = useState<MarketingRecordStatus | ''>('');
  const [type, setType] = useState<MarketingRecordType | ''>('');

  const { data: teams } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });

  // Marketing Employee options are Admins who belong to at least one team — there's no
  // separate "list admins" endpoint exposed to this panel yet, so this reuses real,
  // already-available data rather than adding a new backend endpoint for one filter.
  const employeeOptions = useMemo(() => {
    const seen = new Map<number, string>();
    for (const team of teams ?? []) {
      for (const member of team.members ?? []) {
        seen.set(member.id, member.full_name);
      }
    }
    return Array.from(seen.entries()).map(([value, label]) => ({ value: String(value), label }));
  }, [teams]);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-reports-summary', { from, to, teamId, adminId, status, type }],
    queryFn: () =>
      marketingApi.reportsSummary({
        from: from || undefined,
        to: to || undefined,
        assigned_team_id: teamId ? Number(teamId) : undefined,
        assigned_admin_id: adminId ? Number(adminId) : undefined,
        status: status || undefined,
        type: type || undefined,
      }),
  });

  return (
    <div>
      <PageHeader title="Marketing Reports" breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Reports' }]} />

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
            <label className="mb-1 block text-xs font-medium text-ink-muted">Team</label>
            <Select
              value={teamId}
              onChange={(e) => setTeamId(e.target.value)}
              placeholder="All teams"
              options={(teams ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
              className="w-40"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Marketing employee</label>
            <Select value={adminId} onChange={(e) => setAdminId(e.target.value)} placeholder="All employees" options={employeeOptions} className="w-44" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Record type</label>
            <Select
              value={type}
              onChange={(e) => setType(e.target.value as MarketingRecordType | '')}
              placeholder="XARUN & PROJECT"
              options={TYPE_OPTIONS}
              className="w-36"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">Status</label>
            <Select
              value={status}
              onChange={(e) => setStatus(e.target.value as MarketingRecordStatus | '')}
              placeholder="All statuses"
              options={STATUS_OPTIONS}
              className="w-36"
            />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label="Loading report…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Total Records</h3>
            </CardHeader>
            <CardBody>
              <p className="font-display text-3xl font-bold text-ink">{formatNumber(data.total_records)}</p>
              <p className="mt-1 text-xs text-ink-muted">{data.range.from} – {data.range.to}</p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Type (XARUN / PROJECT)</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.type_breakdown).map(([recordType, total]) => (
                <div key={recordType} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{recordType === 'xarun' ? 'XARUN' : 'PROJECT'}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">By Status</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.status_breakdown).map(([recordStatus, total]) => (
                <div key={recordStatus} className="flex items-center justify-between text-sm">
                  <span className="capitalize text-ink-muted">{recordStatus}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Team A / Team B / All Teams</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.team_breakdown.map((team) => (
                <div key={team.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-muted">{team.name}</span>
                  <span className="font-semibold text-ink">{formatNumber(team.total)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Individual Employee</h3>
            </CardHeader>
            <CardBody>
              {data.employee_breakdown.length === 0 ? (
                <p className="text-sm text-ink-muted">No records assigned to an employee in this range yet.</p>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {data.employee_breakdown.map((employee) => (
                    <div key={employee.admin_id} className="rounded-md border border-border p-3 text-center">
                      <p className="font-display text-lg font-bold text-ink">{formatNumber(employee.total)}</p>
                      <p className="text-xs text-ink-muted">{employee.admin_name}</p>
                    </div>
                  ))}
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}
