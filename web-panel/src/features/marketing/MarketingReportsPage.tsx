import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { formatNumber } from '@/utils/formatters';
import type { MarketingRecordStatus, MarketingRecordType } from '@/types/marketing';

const STATUS_OPTIONS: { value: MarketingRecordStatus; label: string; labelKey: MessageKey }[] = [
  { value: 'pending', label: 'Pending', labelKey: 'status.marketing.record.pending' },
  { value: 'quotation', label: 'Quotation', labelKey: 'status.marketing.record.quotation' },
  { value: 'done', label: 'Done', labelKey: 'status.marketing.record.done' },
  { value: 'cancelled', label: 'Cancelled', labelKey: 'status.marketing.record.cancelled' },
];

const TYPE_OPTIONS: { value: MarketingRecordType; label: string }[] = [
  { value: 'xarun', label: 'XARUN' },
  { value: 'project', label: 'PROJECT' },
];

export function MarketingReportsPage() {
  const { t, tValue } = useI18n();
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
      <PageHeader title={t('nav.marketing.reportsTitle')} breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.reports') }]} />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.dateFrom')}</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.dateTo')}</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.team')}</label>
            <Select
              value={teamId}
              onChange={(e) => setTeamId(e.target.value)}
              placeholder={t('option.marketing.allTeams')}
              options={(teams ?? []).map((team) => ({ value: String(team.id), label: team.name }))}
              className="w-40"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.marketingEmployee')}</label>
            <Select value={adminId} onChange={(e) => setAdminId(e.target.value)} placeholder={t('option.marketing.allEmployees')} options={employeeOptions} className="w-44" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.recordType')}</label>
            <Select
              value={type}
              onChange={(e) => setType(e.target.value as MarketingRecordType | '')}
              placeholder="XARUN & PROJECT"
              options={TYPE_OPTIONS}
              className="w-36"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.status')}</label>
            <Select
              value={status}
              onChange={(e) => setStatus(e.target.value as MarketingRecordStatus | '')}
              placeholder={t('option.marketing.allStatuses')}
              options={STATUS_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))}
              className="w-36"
            />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label={t('option.reports.loading')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.totalRecords')}</h3>
            </CardHeader>
            <CardBody>
              <p className="font-display text-3xl font-bold text-ink">{formatNumber(data.total_records)}</p>
              <p className="mt-1 text-xs text-ink-muted">{data.range.from} – {data.range.to}</p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.byType')}</h3>
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
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.byStatus')}</h3>
            </CardHeader>
            <CardBody className="space-y-2">
              {Object.entries(data.status_breakdown).map(([recordStatus, total]) => (
                <div key={recordStatus} className="flex items-center justify-between text-sm">
                  <span className="capitalize text-ink-muted">{tValue('status.marketing.record', recordStatus) ?? recordStatus}</span>
                  <span className="font-semibold text-ink">{formatNumber(total ?? 0)}</span>
                </div>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.teamBreakdown')}</h3>
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
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.individualEmployee')}</h3>
            </CardHeader>
            <CardBody>
              {data.employee_breakdown.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('warning.marketing.noEmployeeRecords')}</p>
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
