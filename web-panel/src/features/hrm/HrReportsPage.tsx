import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { HrReportCharts, type HrReportLabels } from '@/features/hrm/HrReportCharts';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { useDateRangePreset } from '@/hooks/useDateRangePreset';
import type { WorkAssignmentStatus } from '@/types/employee';
import type { MessageKey } from '@/i18n/messages';
import { useI18n } from '@/i18n/useI18n';
import { titleCase } from '@/utils/chartMath';

const REPORT_LINKS: { labelKey: MessageKey; to: string }[] = [
  { labelKey: 'nav.waitingAnalytics', to: '/hr/reports/waiting-analytics' },
  { labelKey: 'nav.workforceRequestHistory', to: '/hr/reports/workforce-requests' },
  { labelKey: 'nav.temporaryReplacementHistory', to: '/hr/reports/temporary-replacements' },
  { labelKey: 'nav.leaveReport', to: '/hr/reports/leaves' },
  { labelKey: 'nav.performanceReport', to: '/hr/reports/performance-reviews' },
  { labelKey: 'nav.financialLedger', to: '/hr/reports/financial-ledger' },
  { labelKey: 'nav.payrollRollup', to: '/hr/reports/payroll-rollup' },
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

const ASSIGNMENT_STATUS_OPTIONS: { value: WorkAssignmentStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'cancelled', label: 'Cancelled' },
];


export function HrReportsPage() {
  const { t, tValue } = useI18n();
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

  // The approved wording of every stored value the charts show (Somali or English as chosen); the charts only place these words.
  const labels: HrReportLabels = {
    status: (value) => tValue('status', value) ?? STATUS_LABELS[value] ?? value,
    stage: (value) => tValue('status.stage', value) ?? PIPELINE_STAGE_LABELS[value] ?? value,
    gender: (value) => tValue('option.gender', value) ?? titleCase(value),
    attendance: (value) => tValue('option.attendance', value) ?? titleCase(value),
    rating: (value) => tValue('status.pipeline.rating', value) ?? titleCase(value),
    separation: (value) => tValue('option.separation', value) ?? titleCase(value),
  };

  return (
    <div>
      <PageHeader title={t('option.reports.title')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.reports') }]} />

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
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.reports.dateFrom')}</label>
            <input aria-label={t('option.reports.dateFrom')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.reports.dateTo')}</label>
            <input aria-label={t('option.reports.dateTo')} type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.reports.clientCompany')}</label>
            <Select
              value={clientCompanyId}
              onChange={(e) => setClientCompanyId(e.target.value)}
              placeholder={t('option.label.allCompanies')}
              options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
              className="w-44"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.pipeline.workLocation')}</label>
            <Select
              value={workLocationId}
              onChange={(e) => setWorkLocationId(e.target.value)}
              placeholder={t('option.reports.allLocations')}
              options={(locations ?? []).map((l) => ({ value: String(l.id), label: l.name }))}
              className="w-44"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-muted">{t('option.reports.assignmentStatus')}</label>
            <Select
              value={assignmentStatus}
              onChange={(e) => setAssignmentStatus(e.target.value as WorkAssignmentStatus | '')}
              placeholder={t('option.reports.activeDefault')}
              options={ASSIGNMENT_STATUS_OPTIONS.map((o) => ({ value: o.value, label: tValue('status.pipeline.assignment', o.value) ?? o.label }))}
              className="w-40"
            />
          </div>
        </CardBody>
      </Card>

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">{t('option.reports.detailedReports')}</h3>
        </CardHeader>
        <CardBody className="flex flex-wrap gap-2">
          {REPORT_LINKS.map((link) => (
            <Link key={link.to} to={link.to}>
              <Button type="button" size="sm" variant="outline" className="h-8 px-3 text-xs">
                {t(link.labelKey)}
              </Button>
            </Link>
          ))}
        </CardBody>
      </Card>

      {isLoading && <LoadingState label={t('option.reports.loading')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && <HrReportCharts data={data} labels={labels} />}
    </div>
  );
}
