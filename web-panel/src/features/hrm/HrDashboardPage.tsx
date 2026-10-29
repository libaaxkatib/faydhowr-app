import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '@/components/ui/Card';
import { Icon, type IconName } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { formatCurrency, formatNumber } from '@/utils/formatters';
import { categoryTileLabel, categoryTileTo, dashboardCardTo } from '@/utils/hrDashboardLinks';
import { useI18n } from '@/i18n/useI18n';

export function HrDashboardPage() {
  const { t } = useI18n();
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['hr-dashboard'], queryFn: hrApi.dashboard });
  // One reference time per render so the "last 30 days" link and the card describe the same window.
  const now = new Date();

  return (
    <div>
      <PageHeader title={t('nav.hr.dashboardTitle')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: 'Dashboard' }]} />

      {isLoading && <LoadingState label={t('nav.hr.dashboardLoading')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <KpiCard label={t('option.hr.kpiTotalEmployees')} value={data.total_employees} icon="users" color="bg-primary" to={dashboardCardTo('total_employees', now)} />
            <KpiCard label={t('status.active')} value={data.active_employees} icon="check" color="bg-success" to={dashboardCardTo('active_employees', now)} />
            <KpiCard label={t('status.inactive')} value={data.inactive_employees} icon="x" color="bg-danger" to={dashboardCardTo('inactive_employees', now)} />
            <KpiCard label={t('option.hr.kpiNewApplicants')} value={data.new_applicants} icon="plus" color="bg-secondary" to={dashboardCardTo('new_applicants', now)} />
            <KpiCard label={t('status.practical')} value={data.practical} icon="file-text" color="bg-purple-500" to={dashboardCardTo('practical', now)} />
            <KpiCard label={t('nav.waiting')} value={data.waiting} icon="calendar" color="bg-warning" to={dashboardCardTo('waiting', now)} />
            <KpiCard label={t('option.hr.kpiOpenRequests')} value={data.open_workforce_requests} icon="briefcase" color="bg-teal-600" to={dashboardCardTo('open_workforce_requests', now)} />
            <KpiCard label={t('option.hr.kpiFulfilledRequests')} value={data.fulfilled_workforce_requests} icon="briefcase" color="bg-teal-600" to={dashboardCardTo('fulfilled_workforce_requests', now)} />
            <KpiCard label={t('status.approved')} value={data.approved} icon="shield" color="bg-teal-600" to={dashboardCardTo('approved', now)} />
            <KpiCard label={t('nav.supervisorPool')} value={data.supervisor_pool_count} icon="shield" color="bg-primary" to={dashboardCardTo('supervisor_pool', now)} />
            <KpiCard label={t('option.hr.kpiActiveReplacements')} value={data.active_temporary_replacements} icon="refresh" color="bg-warning" to={dashboardCardTo('active_replacements', now)} />
            <KpiCard label={t('option.hr.kpiExpiredReplacements')} value={data.expired_temporary_replacements} icon="refresh" color="bg-danger" to={dashboardCardTo('expired_replacements', now)} />
            <KpiCard label={t('nav.officeStaff')} value={data.office_staff_count} icon="users" color="bg-secondary" to={dashboardCardTo('office_staff', now)} />
            <KpiCard
              label={t('option.hr.kpiClientCompanyEmployees')}
              value={data.client_company_active_employees}
              icon="briefcase"
              color="bg-secondary"
              to={dashboardCardTo('client_company_employees', now)}
            />
          </div>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.hr.sectionAttendanceToday')}</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <KpiCard label={t('option.hr.kpiPresentToday')} value={data.present_today} icon="check" color="bg-success" to="/hr/attendance" />
              <KpiCard label={t('option.hr.kpiAbsentToday')} value={data.absent_today} icon="x" color="bg-danger" to="/hr/attendance" />
              <KpiCard label={t('option.hr.kpiLateToday')} value={data.late_today} icon="calendar" color="bg-warning" to="/hr/attendance" />
              <KpiCard label={t('option.hr.kpiMarkedToday')} value={data.attendance_marked_today} icon="calendar" color="bg-primary" to="/hr/attendance" />
            </CardBody>
          </Card>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.hr.sectionFinancials')}</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <KpiCard label={t('option.employeeDetail.payments')} value={data.month_payments_total} icon="credit-card" color="bg-success" to="/hr/reports/financial-ledger" isCurrency />
              <KpiCard label={t('option.payroll.penalties')} value={data.month_penalties_total} icon="x" color="bg-danger" to="/hr/reports/financial-ledger" isCurrency />
              <KpiCard label={t('option.payroll.advances')} value={data.month_advances_total} icon="credit-card" color="bg-warning" to="/hr/reports/financial-ledger" isCurrency />
            </CardBody>
          </Card>

          <Card className="mb-5">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.hr.sectionPipelineQueues')}</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              <KpiCard label={t('nav.damiinNeeded')} value={data.damiin_needed} icon="file-text" color="bg-secondary" to="/hr/pipeline/damiin" />
              <KpiCard label={t('nav.contractPending')} value={data.contract_pending} icon="file-text" color="bg-secondary" to="/hr/pipeline/contracts" />
              <KpiCard label={t('nav.uniformPending')} value={data.uniform_pending} icon="box" color="bg-warning" to="/hr/pipeline/uniform" />
              <KpiCard label={t('nav.needTraining')} value={data.need_training} icon="calendar" color="bg-primary" to="/hr/pipeline/training" />
              <KpiCard label={t('nav.needPractical')} value={data.need_practical} icon="eye" color="bg-primary" to="/hr/pipeline/practical" />
              <KpiCard label={t('nav.practicalRepeat')} value={data.practical_repeat} icon="refresh" color="bg-warning" to="/hr/pipeline/practical-repeat" />
              <KpiCard label={t('nav.rejected')} value={data.rejected} icon="x" color="bg-danger" to="/hr/pipeline/rejected" />
              <KpiCard
                label={t('nav.rejectedHistorical')}
                value={data.historical_rejected}
                icon="x"
                color="bg-ink-muted"
                to="/hr/pipeline/rejected-historical"
              />
              <KpiCard label={t('option.employeeDetail.historicalCompletion')} value={data.historical_completion_employees} icon="check" color="bg-secondary" to="/hr/employees" />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.hr.sectionCategoryBreakdown')}</h3>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              {data.category_breakdown.map((category) => (
                <Link
                  key={category.id}
                  to={categoryTileTo(category.id)}
                  aria-label={t('option.hr.categoryTileAria', { label: categoryTileLabel(category.name), n: formatNumber(category.total) })}
                  className={`block rounded-md border border-border p-3 text-center ${CLICKABLE_CLASSES}`}
                >
                  <p className="font-display text-lg font-bold text-ink">{formatNumber(category.total)}</p>
                  <p className="text-xs text-ink-muted">{categoryTileLabel(category.name)}</p>
                </Link>
              ))}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}

// Shared by every clickable dashboard tile: pointer cursor, hover feedback and a visible keyboard focus ring.
const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2';
const CLICKABLE_CLASSES = `cursor-pointer transition hover:border-primary/40 hover:shadow-card ${FOCUS_RING}`;

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
        <p className="text-xs font-medium leading-snug text-ink-muted" title={label}>{label}</p>
        <p className="font-display text-xl font-bold text-ink">{displayValue}</p>
      </div>
    </div>
  );

  if (to) {
    return (
      <Link to={to} aria-label={`${label}: ${displayValue}`} className={`block rounded-lg ${FOCUS_RING}`}>
        <Card className="cursor-pointer p-5 transition hover:border-primary/40 hover:shadow-card">{content}</Card>
      </Link>
    );
  }

  return <Card className="p-5">{content}</Card>;
}
