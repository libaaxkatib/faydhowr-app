import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { MarketingReportCharts } from '@/features/marketing/MarketingReportCharts';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { useI18n } from '@/i18n/useI18n';
import { reportPresetRange, type ReportPreset } from '@/utils/marketingReportRange';
import type { MessageKey } from '@/i18n/messages';
import type { MarketingRecordStatus, MarketingRecordType } from '@/types/marketing';

const STATUS_OPTIONS: { value: MarketingRecordStatus; label: string; labelKey: MessageKey }[] = [
  { value: 'pending', label: 'Pending', labelKey: 'status.marketing.record.pending' },
  { value: 'quotation', label: 'Quotation', labelKey: 'status.marketing.record.quotation' },
  { value: 'done', label: 'Done', labelKey: 'status.marketing.record.done' },
  { value: 'cancelled', label: 'Cancelled', labelKey: 'status.marketing.record.cancelled' },
];

const PRESET_KEYS: Record<ReportPreset, MessageKey> = {
  today: 'option.marketing.presetToday',
  last7: 'option.marketing.presetLast7',
  last30: 'option.marketing.presetLast30',
  thisMonth: 'option.marketing.presetThisMonth',
};

const TYPE_OPTIONS: { value: MarketingRecordType; label: string }[] = [
  { value: 'xarun', label: 'XARUN' },
  { value: 'project', label: 'PROJECT' },
];

export function MarketingReportsPage() {
  const { t } = useI18n();
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [teamId, setTeamId] = useState('');
  const [adminId, setAdminId] = useState('');
  const [status, setStatus] = useState<MarketingRecordStatus | ''>('');
  const [type, setType] = useState<MarketingRecordType | ''>('');

  const { data: teams } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });

  // The same list the assignment pickers use (managers and employees, active), so a manager who belongs to no team can be filtered too.
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });
  const employeeOptions = (employees ?? []).map((employee) => ({ value: String(employee.id), label: employee.full_name }));

  const applyPreset = (preset: ReportPreset) => {
    const range = reportPresetRange(preset, new Date());
    setFrom(range.from);
    setTo(range.to);
  };

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
          <div className="flex w-full flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-ink-muted">{t('option.marketing.reportQuickRange')}</span>
            {(['today', 'last7', 'last30', 'thisMonth'] as const).map((preset) => (
              <button
                key={preset}
                type="button"
                onClick={() => applyPreset(preset)}
                className="rounded-full border border-border px-3 py-1 text-xs font-medium text-ink-muted hover:bg-surface-alt hover:text-primary"
              >
                {t(PRESET_KEYS[preset])}
              </button>
            ))}
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.dateFrom')}</label>
            <input aria-label={t('option.reports.dateFrom')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.dateTo')}</label>
            <input aria-label={t('option.reports.dateTo')} type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
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

      {data && (
        <p className="mb-3 text-xs text-ink-muted">
          {t('option.marketing.reportPeriod', { from: data.range.from, to: data.range.to })}
          {from === '' && to === '' ? ` ${t('warning.marketing.reportDefaultPeriod')}` : ''}
        </p>
      )}

      {isLoading && <LoadingState label={t('option.reports.loading')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && <MarketingReportCharts data={data} />}
    </div>
  );
}
