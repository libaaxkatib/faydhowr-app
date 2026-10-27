import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { inputClasses } from '@/components/ui/FormField';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { formatDate } from '@/utils/formatters';
import {
  ASSIGNMENT_TYPE_OPTIONS,
  assignmentShiftLabel,
  assignmentTypeLabel,
  currentPeriod,
  formatCents,
  formatMoney,
  isValidPeriod,
  periodLabel,
  shiftPeriod,
  totalsByCurrency,
} from '@/utils/companyEmployees';
import { AssignEmployeeDialog, EditAssignmentDialog, ReplaceEmployeeDialog, ReturnEmployeeDialog, StopEmployeeDialog } from '@/features/hrm/companyEmployees/AssignmentDialogs';
import { HistoryDialog } from '@/features/hrm/companyEmployees/HistoryDialog';
import type { AssignmentType, CompanyAssignmentStatus, CompanyEmployeeRow } from '@/types/companyEmployees';

/**
 * One company's employees: who is placed here, their position, type, dates, workdays and pay for the chosen month, and the actions
 * on each assignment. Reading needs hr.view; the action buttons need hr.manage; calculated pay appears only for people who may see
 * payroll. All of that is enforced by the server - the panel only mirrors it.
 */
export function CompanyEmployeesPage() {
  const { t, tCount } = useI18n();
  const params = useParams();
  const location = useLocation();
  const companyId = Number(params.id);
  // Back to the Companies & Workplaces list exactly as it was left (its category filter included), when we came from there.
  const fromState = (location.state as { fromPath?: string } | null)?.fromPath;
  const backToList = typeof fromState === 'string' && fromState.startsWith('/hr/companies') ? fromState : '/hr/companies';
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.manage');

  // Month, filters and search live in the address bar, so Back from an employee returns to the same view.
  const url = useUrlFilters();
  const periodParam = url.get('month');
  const period = isValidPeriod(periodParam) ? periodParam : currentPeriod();
  const status = (['active', 'ended'].includes(url.get('status')) ? url.get('status') : '') as CompanyAssignmentStatus | '';
  const positionParam = url.get('position');
  const positionId: number | '' = /^\d+$/.test(positionParam) ? Number(positionParam) : '';
  const type = (['permanent', 'replacement'].includes(url.get('type')) ? url.get('type') : '') as AssignmentType | '';
  const search = url.get('q');
  const setPeriod = (value: string) => url.set('month', value === currentPeriod() ? '' : value);
  const setStatus = (value: CompanyAssignmentStatus | '') => url.set('status', value);
  const setPositionId = (value: number | '') => url.set('position', value === '' ? '' : String(value));
  const setType = (value: AssignmentType | '') => url.set('type', value);
  const setSearch = (value: string) => url.set('q', value);
  const debouncedSearch = useDebouncedValue(search.trim(), 300);

  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<CompanyEmployeeRow | null>(null);
  const [replacing, setReplacing] = useState<CompanyEmployeeRow | null>(null);
  const [returning, setReturning] = useState<CompanyEmployeeRow | null>(null);
  const [stopping, setStopping] = useState<CompanyEmployeeRow | null>(null);
  const [history, setHistory] = useState<{ id: number; name: string } | 'all' | null>(null);

  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['company-employees', companyId, period, status, positionId, type, debouncedSearch],
    queryFn: () => companyEmployeesApi.list(companyId, { period, status, position_id: positionId, assignment_type: type, search: debouncedSearch }),
    enabled: Number.isInteger(companyId) && isValidPeriod(period),
    placeholderData: (previous) => previous,
  });

  const companyName = data?.company.name ?? t('option.label.company');
  const summary = data?.summary;
  const showPayroll = data?.employees.some((row) => row.payroll !== undefined) ?? false;
  const isEstimate = summary?.calculated_pay_is_estimate ?? false;
  const rows = data?.employees ?? [];

  return (
    <div>
      <PageHeader
        title={companyName}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.companiesWorkplaces'), to: backToList }, { label: companyName }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => setHistory('all')}>
              <Icon name="list" size={15} />
              {t('action.history')}
            </Button>
            {canManage && (
              <Button onClick={() => setAdding(true)}>
                <Icon name="plus" size={15} />
                {t('action.addEmployee')}
              </Button>
            )}
          </div>
        }
      />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="ce-month" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.month')}</label>
            <div className="flex items-center gap-1">
              <Button variant="outline" size="sm" aria-label={t('option.company.previousMonth')} onClick={() => setPeriod(shiftPeriod(period, -1))}>‹</Button>
              <input id="ce-month" type="month" className={`${inputClasses} w-44`} value={period} onChange={(event) => event.target.value && setPeriod(event.target.value)} />
              <Button variant="outline" size="sm" aria-label={t('option.company.nextMonth')} onClick={() => setPeriod(shiftPeriod(period, 1))}>›</Button>
            </div>
          </div>
          <div className="min-w-[200px] flex-1">
            <label htmlFor="ce-search" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.search')}</label>
            <input id="ce-search" className={inputClasses} placeholder={t('option.company.searchPlaceholder')} value={search} onChange={(event) => setSearch(event.target.value)} />
          </div>
          <div className="w-40">
            <label htmlFor="ce-status" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.status')}</label>
            <Select id="ce-status" value={status} onChange={(event) => setStatus(event.target.value as CompanyAssignmentStatus | '')} placeholder={t('option.label.all')} options={[{ value: 'active', label: t('status.active') }, { value: 'ended', label: t('status.company.ended') }]} />
          </div>
          <div className="w-48">
            <label htmlFor="ce-position" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.position')}</label>
            <Select id="ce-position" value={positionId === '' ? '' : String(positionId)} onChange={(event) => setPositionId(event.target.value === '' ? '' : Number(event.target.value))} placeholder={t('option.company.allPositions')} options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))} />
          </div>
          <div className="w-40">
            <label htmlFor="ce-type" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.type')}</label>
            <Select id="ce-type" value={type} onChange={(event) => setType(event.target.value as AssignmentType | '')} placeholder={t('option.company.allTypes')} options={ASSIGNMENT_TYPE_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))} />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label={t('option.company.loadingEmployees')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {summary && (
        <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label={t('option.company.summaryLabel', { period: periodLabel(period) })}>
          <SummaryCard label={t('option.label.employees')} value={String(summary.employee_count)} detail={t('option.company.activeEnded', { active: summary.active_count, ended: summary.ended_count })} />
          <SummaryCard label={t('option.label.type')} value={`${summary.permanent_count} ${assignmentTypeLabel('permanent')}`} detail={`${summary.replacement_count} ${assignmentTypeLabel('replacement')}`} />
          <SummaryCard
            label={t('option.company.payableDays')}
            value={String(summary.payable_days_total)}
            detail={t('option.company.daysAndAttendance', { days: summary.days_in_month, recorded: summary.attendance_recorded_total, absent: summary.absent_marks_total })}
          />
          <SummaryCard
            label={t('option.company.agreedSalaryAtMonthEnd', { period: periodLabel(period) })}
            value={totalsByCurrency(summary.contracted_monthly_salary_cents_by_currency).map((line) => line.text).join(' + ') || '—'}
            detail={summary.contracted_non_monthly_count > 0 ? t('option.company.nonMonthlyNotIncluded', { count: summary.contracted_non_monthly_count }) : t('option.company.peopleStillInPost')}
          />
        </div>
      )}

      {data && (
        <Card>
          {rows.length === 0 ? (
            <EmptyState icon="users" title={t('option.company.noEmployeesForFilters')} description={canManage ? t('option.company.useAddEmployee') : t('option.company.tryAnotherMonth')} />
          ) : (
            <div className="overflow-x-auto" aria-busy={isFetching}>
              <table className="w-full min-w-[1100px] text-sm">
                <caption className="sr-only">{t('option.company.tableCaption', { company: companyName, period: periodLabel(period) })}</caption>
                <thead>
                  <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-ink-faint">
                    <th className="px-4 py-3">{t('option.label.employee')}</th>
                    <th className="px-3 py-3">{t('option.label.position')}</th>
                    <th className="px-3 py-3">{t('option.label.type')}</th>
                    <th className="px-3 py-3">{t('confirm.company.fieldShift')}</th>
                    <th className="px-3 py-3">{t('option.company.columnStart')}</th>
                    <th className="px-3 py-3">{t('option.company.columnWorkdays')}</th>
                    <th className="px-3 py-3">{t('option.label.salary')}</th>
                    {showPayroll && <th className="px-3 py-3">{t('option.company.columnPayFor', { period: periodLabel(period) })}</th>}
                    <th className="px-3 py-3">{t('option.label.status')}</th>
                    <th className="px-3 py-3 text-right">{t('option.label.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {rows.map((row) => (
                    <EmployeeRow key={row.assignment_id} row={row} showPayroll={showPayroll} canManage={canManage} onEdit={setEditing} onReplace={setReplacing} onReturn={setReturning} onStop={setStopping} onHistory={(r) => setHistory({ id: r.employee_id, name: r.full_name ?? t('option.label.employee') })} />
                  ))}
                </tbody>
                {summary && (
                  <tfoot>
                    <tr className="border-t-2 border-border bg-surface-alt align-top font-semibold text-ink">
                      <td className="px-4 py-3" colSpan={4}>
                        {t('option.company.totalFor', { period: periodLabel(period) })}
                        <span className="block text-xs font-normal text-ink-muted">{tCount('option.company.totalsLine', summary.employee_count)}</span>
                      </td>
                      <td className="px-3 py-3">{t('option.company.daysCount', { days: summary.payable_days_total })}</td>
                      <td className="px-3 py-3">
                        <TotalLines title={t('option.company.agreedMonthlyTitle')} totals={summary.contracted_monthly_salary_cents_by_currency} />
                      </td>
                      {showPayroll && (
                        <td className="px-3 py-3">
                          <TotalLines title={isEstimate ? t('option.company.calculatedNetEstimate') : t('option.company.calculatedNetApproved')} totals={Object.fromEntries(Object.entries(summary.calculated_pay_by_currency ?? {}).map(([c, v]) => [c, v.net_cents]))} />
                          <TotalLines title={t('status.payroll.paid')} totals={summary.paid_net_cents_by_currency ?? {}} />
                          {(summary.calculated_pay_unavailable_count ?? 0) > 0 && <span className="block text-xs font-normal text-warning">{t('option.company.withoutCalculation', { count: summary.calculated_pay_unavailable_count ?? 0 })}</span>}
                        </td>
                      )}
                      <td colSpan={2} />
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          )}
        </Card>
      )}
      {summary && showPayroll && isEstimate && (
        <p className="mt-2 text-xs text-ink-muted">
          {t('warning.company.estimateNote')}
        </p>
      )}
      {summary?.payroll_warnings && summary.payroll_warnings.length > 0 && (
        <ul className="mt-2 list-disc pl-5 text-xs text-warning">
          {summary.payroll_warnings.slice(0, 5).map((warning) => (
            <li key={warning}>{warning}</li>
          ))}
        </ul>
      )}

      <AssignEmployeeDialog isOpen={adding} onClose={() => setAdding(false)} companyId={companyId} companyName={companyName} />
      <EditAssignmentDialog row={editing} onClose={() => setEditing(null)} companyId={companyId} />
      <ReplaceEmployeeDialog row={replacing} onClose={() => setReplacing(null)} companyId={companyId} companyName={companyName} />
      <ReturnEmployeeDialog row={returning} onClose={() => setReturning(null)} companyId={companyId} companyName={companyName} />
      <StopEmployeeDialog row={stopping} onClose={() => setStopping(null)} companyId={companyId} companyName={companyName} />
      <HistoryDialog isOpen={history !== null} onClose={() => setHistory(null)} companyId={companyId} companyName={companyName} employee={history === 'all' ? null : history} />
    </div>
  );
}

function SummaryCard({ label, value, detail }: { label: string; value: string; detail: string }) {
  return (
    <Card>
      <CardBody>
        <p className="text-xs font-medium text-ink-muted">{label}</p>
        <p className="mt-1 font-display text-xl font-bold text-ink">{value}</p>
        <p className="mt-0.5 text-xs text-ink-faint">{detail}</p>
      </CardBody>
    </Card>
  );
}

function TotalLines({ title, totals }: { title: string; totals: Record<string, number> }) {
  const lines = totalsByCurrency(totals);
  if (lines.length === 0) return <span className="block text-xs font-normal text-ink-faint">{title}: —</span>;
  return (
    <span className="block">
      <span className="block text-[11px] font-normal text-ink-muted">{title}</span>
      {lines.map((line) => (
        <span key={line.currency} className="block">{line.text}</span>
      ))}
    </span>
  );
}

interface EmployeeRowProps {
  row: CompanyEmployeeRow;
  showPayroll: boolean;
  canManage: boolean;
  onEdit: (row: CompanyEmployeeRow) => void;
  onReplace: (row: CompanyEmployeeRow) => void;
  onReturn: (row: CompanyEmployeeRow) => void;
  onStop: (row: CompanyEmployeeRow) => void;
  onHistory: (row: CompanyEmployeeRow) => void;
}

function EmployeeRow({ row, showPayroll, canManage, onEdit, onReplace, onReturn, onStop, onHistory }: EmployeeRowProps) {
  const { t, tValue } = useI18n();
  const open = row.status === 'active';
  const canReturn = open && row.assignment_type === 'replacement' && row.replaces_assignment_id !== null;
  const calc = row.payroll?.calculated ?? null;

  return (
    <tr className="align-top">
      <td className="min-w-[190px] px-4 py-3">
        <Link to={`/hr/employees/${row.employee_id}`} className="font-medium text-ink hover:text-primary hover:underline">{row.full_name}</Link>
        <span className="block text-xs text-ink-muted">{row.employee_number} · {t('option.label.employeeId')} {row.employee_id}</span>
        {row.also_assigned_at.length > 0 && (
          <span className="block text-xs text-warning" title={t('warning.company.alsoAtTitle')}>
            {t('option.company.alsoAt', { companies: row.also_assigned_at.map((o) => o.company_name).join(', ') })}
          </span>
        )}
      </td>
      <td className="px-3 py-3 text-ink">{row.position_name ?? <span className="text-ink-faint">—</span>}</td>
      <td className="px-3 py-3">
        <StatusBadge status={row.assignment_type} label={assignmentTypeLabel(row.assignment_type)} tone={row.assignment_type === 'replacement' ? 'warning' : 'neutral'} />
        {row.needs_correction && (
          <span role="note" data-testid="needs-correction" title={row.needs_correction_reason ?? undefined} className="mt-1 block text-xs text-danger">
            {t('warning.company.needsCorrection')}
          </span>
        )}
      </td>
      <td className="px-3 py-3 text-ink" data-testid="shift-cell">
        {row.shift === null ? <span className="text-ink-faint">{t('option.company.shiftNotRecorded')}</span> : assignmentShiftLabel(row.shift)}
      </td>
      <td className="px-3 py-3 text-ink">
        {formatDate(row.start_date)}
        {row.end_date && <span className="block text-xs text-ink-muted">{t('option.company.toDate', { date: formatDate(row.end_date) })}</span>}
      </td>
      <td className="px-3 py-3 text-ink">
        {row.payable_days} <span className="text-xs text-ink-muted">{t('option.company.ofDays', { days: row.days_in_month })}</span>
        <span className="block text-xs text-ink-muted" title={t('warning.company.attendanceMarksTitle')}>
          {row.attendance.recorded === 0 ? t('option.company.noAttendanceRecorded') : t('option.company.presentAbsent', { present: row.attendance.present + row.attendance.late, absent: row.attendance.absent })}
        </span>
      </td>
      <td className="px-3 py-3 text-ink">
        {formatMoney(Number(row.salary.amount), row.salary.currency)}
        <span className="block text-xs text-ink-muted">{tValue('option.frequency', row.salary.frequency) ?? row.salary.frequency}{row.salary_changed_in_period ? ` · ${t('option.company.changedInThisMonth')}` : ''}</span>
      </td>
      {showPayroll && (
        <td className="px-3 py-3 text-ink">
          {calc ? (
            <>
              {formatCents(calc.net_cents, calc.currency)}
              <span className="block text-xs text-ink-muted">
                {calc.is_estimate ? t('option.company.payEstimate') : t('option.company.payApproved', { status: tValue('option.payrollStatus', String(calc.run_status)) ?? String(calc.run_status) })}
                {row.payroll?.payment ? ` · ${tValue('status.payroll', row.payroll.payment.status) ?? row.payroll.payment.status}` : ''}
              </span>
            </>
          ) : (
            <span className="text-xs text-ink-faint" title={row.payroll?.unavailable_reason ?? undefined}>{t('option.company.notCalculated')}</span>
          )}
        </td>
      )}
      <td className="px-3 py-3">
        <StatusBadge status={row.status} label={row.status === 'active' ? t('status.active') : t('status.company.ended')} tone={row.status === 'active' ? 'success' : 'neutral'} />
      </td>
      <td className="px-3 py-3">
        <div className="flex flex-wrap justify-end gap-1.5">
          <Link to={`/hr/employees/${row.employee_id}`} state={{ fromPath: `${window.location.pathname}${window.location.search}`, fromLabel: t('option.company.companyEmployeesBack') }} className="inline-flex h-8 items-center rounded-sm border border-border px-2.5 text-xs font-semibold text-ink hover:bg-surface-alt" aria-label={t('action.company.viewNamed', { name: row.full_name ?? '' })}>{t('action.company.viewEmployee')}</Link>
          {canManage && <Button variant="outline" size="sm" onClick={() => onEdit(row)} aria-label={t('action.company.editNamed', { name: row.full_name ?? '' })}>{t('action.edit')}</Button>}
          <RowMenu
            label={t('action.company.moreActionsFor', { name: row.full_name ?? '' })}
            items={[
              { label: t('action.history'), ariaLabel: t('action.company.historyOf', { name: row.full_name ?? '' }), onClick: () => onHistory(row) },
              ...(canManage && open ? [{ label: t('action.replace'), ariaLabel: t('confirm.company.replaceTitle', { name: row.full_name ?? '' }), onClick: () => onReplace(row) }] : []),
              ...(canManage && canReturn ? [{ label: t('action.returnOriginal'), ariaLabel: t('action.company.returnOriginalFor', { name: row.full_name ?? '' }), onClick: () => onReturn(row) }] : []),
              ...(canManage && open ? [{ label: t('action.stop'), ariaLabel: t('confirm.company.stopTitle', { name: row.full_name ?? '' }), onClick: () => onStop(row), danger: true }] : []),
            ]}
          />
        </div>
      </td>
    </tr>
  );
}

/** The less common row actions behind one "More" button, so the Actions column stays narrow. Closes on selection, outside click and Escape. */
function RowMenu({ label, items }: { label: string; items: { label: string; ariaLabel: string; onClick: () => void; danger?: boolean }[] }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { t } = useI18n();

  useEffect(() => {
    if (!open) return;
    const onPointer = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <Button variant="outline" size="sm" aria-haspopup="menu" aria-expanded={open} aria-label={label} onClick={() => setOpen((value) => !value)}>
        {t('action.more')}
        <Icon name="chevron-down" size={12} />
      </Button>
      {open && (
        <div role="menu" className="absolute right-0 z-20 mt-1 min-w-[10rem] rounded-md border border-border bg-surface py-1 shadow-card">
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              aria-label={item.ariaLabel}
              onClick={() => {
                setOpen(false);
                item.onClick();
              }}
              className={`block w-full px-3 py-1.5 text-left text-xs font-medium hover:bg-surface-alt ${item.danger ? 'text-danger' : 'text-ink'}`}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
