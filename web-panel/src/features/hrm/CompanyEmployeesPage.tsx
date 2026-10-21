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
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDate } from '@/utils/formatters';
import {
  ASSIGNMENT_TYPE_LABELS,
  ASSIGNMENT_TYPE_OPTIONS,
  assignmentShiftLabel,
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
  const params = useParams();
  const location = useLocation();
  const companyId = Number(params.id);
  // Back to the Companies & Workplaces list exactly as it was left (its category filter included), when we came from there.
  const fromState = (location.state as { fromPath?: string } | null)?.fromPath;
  const backToList = typeof fromState === 'string' && fromState.startsWith('/hr/companies') ? fromState : '/hr/companies';
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.manage');

  const [period, setPeriod] = useState(currentPeriod());
  const [status, setStatus] = useState<CompanyAssignmentStatus | ''>('');
  const [positionId, setPositionId] = useState<number | ''>('');
  const [type, setType] = useState<AssignmentType | ''>('');
  const [search, setSearch] = useState('');
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

  const companyName = data?.company.name ?? 'Company';
  const summary = data?.summary;
  const showPayroll = data?.employees.some((row) => row.payroll !== undefined) ?? false;
  const isEstimate = summary?.calculated_pay_is_estimate ?? false;
  const rows = data?.employees ?? [];

  return (
    <div>
      <PageHeader
        title={companyName}
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Companies & Workplaces', to: backToList }, { label: companyName }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => setHistory('all')}>
              <Icon name="list" size={15} />
              History
            </Button>
            {canManage && (
              <Button onClick={() => setAdding(true)}>
                <Icon name="plus" size={15} />
                Add employee
              </Button>
            )}
          </div>
        }
      />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="ce-month" className="mb-1 block text-xs font-medium text-ink-muted">Month</label>
            <div className="flex items-center gap-1">
              <Button variant="outline" size="sm" aria-label="Previous month" onClick={() => setPeriod(shiftPeriod(period, -1))}>‹</Button>
              <input id="ce-month" type="month" className={`${inputClasses} w-44`} value={period} onChange={(event) => event.target.value && setPeriod(event.target.value)} />
              <Button variant="outline" size="sm" aria-label="Next month" onClick={() => setPeriod(shiftPeriod(period, 1))}>›</Button>
            </div>
          </div>
          <div className="min-w-[200px] flex-1">
            <label htmlFor="ce-search" className="mb-1 block text-xs font-medium text-ink-muted">Search</label>
            <input id="ce-search" className={inputClasses} placeholder="Name or employee number…" value={search} onChange={(event) => setSearch(event.target.value)} />
          </div>
          <div className="w-40">
            <label htmlFor="ce-status" className="mb-1 block text-xs font-medium text-ink-muted">Status</label>
            <Select id="ce-status" value={status} onChange={(event) => setStatus(event.target.value as CompanyAssignmentStatus | '')} placeholder="All" options={[{ value: 'active', label: 'Active' }, { value: 'ended', label: 'Ended' }]} />
          </div>
          <div className="w-48">
            <label htmlFor="ce-position" className="mb-1 block text-xs font-medium text-ink-muted">Position</label>
            <Select id="ce-position" value={positionId === '' ? '' : String(positionId)} onChange={(event) => setPositionId(event.target.value === '' ? '' : Number(event.target.value))} placeholder="All positions" options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))} />
          </div>
          <div className="w-40">
            <label htmlFor="ce-type" className="mb-1 block text-xs font-medium text-ink-muted">Type</label>
            <Select id="ce-type" value={type} onChange={(event) => setType(event.target.value as AssignmentType | '')} placeholder="All types" options={ASSIGNMENT_TYPE_OPTIONS} />
          </div>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label="Loading company employees…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {summary && (
        <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label={`Summary for ${periodLabel(period)}`}>
          <SummaryCard label="Employees" value={String(summary.employee_count)} detail={`${summary.active_count} active · ${summary.ended_count} ended`} />
          <SummaryCard label="Type" value={`${summary.permanent_count} Permanent`} detail={`${summary.replacement_count} Replacement`} />
          <SummaryCard
            label="Payable days"
            value={String(summary.payable_days_total)}
            detail={`${summary.days_in_month}-day month · ${summary.attendance_recorded_total} attendance marks recorded, ${summary.absent_marks_total} absent`}
          />
          <SummaryCard
            label={`Agreed monthly salary at month end — ${periodLabel(period)}`}
            value={totalsByCurrency(summary.contracted_monthly_salary_cents_by_currency).map((t) => t.text).join(' + ') || '—'}
            detail={summary.contracted_non_monthly_count > 0 ? `${summary.contracted_non_monthly_count} non-monthly salary not included` : 'People still in post on the last day · each currency separately'}
          />
        </div>
      )}

      {data && (
        <Card>
          {rows.length === 0 ? (
            <EmptyState icon="users" title="No employees for these filters" description={canManage ? 'Use Add employee to place a registered employee at this company.' : 'Try another month or clear the filters.'} />
          ) : (
            <div className="overflow-x-auto" aria-busy={isFetching}>
              <table className="w-full min-w-[1100px] text-sm">
                <caption className="sr-only">Employees assigned to {companyName} in {periodLabel(period)}</caption>
                <thead>
                  <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-ink-faint">
                    <th className="px-4 py-3">Employee</th>
                    <th className="px-3 py-3">Position</th>
                    <th className="px-3 py-3">Type</th>
                    <th className="px-3 py-3">Shift</th>
                    <th className="px-3 py-3">Start</th>
                    <th className="px-3 py-3">Workdays</th>
                    <th className="px-3 py-3">Salary</th>
                    {showPayroll && <th className="px-3 py-3">Pay for {periodLabel(period)}</th>}
                    <th className="px-3 py-3">Status</th>
                    <th className="px-3 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {rows.map((row) => (
                    <EmployeeRow key={row.assignment_id} row={row} showPayroll={showPayroll} canManage={canManage} onEdit={setEditing} onReplace={setReplacing} onReturn={setReturning} onStop={setStopping} onHistory={(r) => setHistory({ id: r.employee_id, name: r.full_name ?? 'Employee' })} />
                  ))}
                </tbody>
                {summary && (
                  <tfoot>
                    <tr className="border-t-2 border-border bg-surface-alt align-top font-semibold text-ink">
                      <td className="px-4 py-3" colSpan={4}>
                        Total for {periodLabel(period)}
                        <span className="block text-xs font-normal text-ink-muted">{summary.employee_count} employee{summary.employee_count === 1 ? '' : 's'} · totals are per currency</span>
                      </td>
                      <td className="px-3 py-3">{summary.payable_days_total} days</td>
                      <td className="px-3 py-3">
                        <TotalLines title="Agreed monthly (in post at month end)" totals={summary.contracted_monthly_salary_cents_by_currency} />
                      </td>
                      {showPayroll && (
                        <td className="px-3 py-3">
                          <TotalLines title={isEstimate ? 'Calculated net (estimate)' : 'Calculated net (approved)'} totals={Object.fromEntries(Object.entries(summary.calculated_pay_by_currency ?? {}).map(([c, v]) => [c, v.net_cents]))} />
                          <TotalLines title="Paid" totals={summary.paid_net_cents_by_currency ?? {}} />
                          {(summary.calculated_pay_unavailable_count ?? 0) > 0 && <span className="block text-xs font-normal text-warning">{summary.calculated_pay_unavailable_count} without a calculation</span>}
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
          Calculated pay is a live estimate by the payroll rules (calendar days, absences and approved replacement costs) until the month's payroll is approved. Actual payment is shown only for approved payroll.
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
  const open = row.status === 'active';
  const canReturn = open && row.assignment_type === 'replacement' && row.replaces_assignment_id !== null;
  const calc = row.payroll?.calculated ?? null;

  return (
    <tr className="align-top">
      <td className="min-w-[190px] px-4 py-3">
        <Link to={`/hr/employees/${row.employee_id}`} className="font-medium text-ink hover:text-primary hover:underline">{row.full_name}</Link>
        <span className="block text-xs text-ink-muted">{row.employee_number} · HR id {row.employee_id}</span>
        {row.also_assigned_at.length > 0 && (
          <span className="block text-xs text-warning" title="Attendance marks belong to the employee, so they appear under each company. An absence is charged once.">
            Also at {row.also_assigned_at.map((o) => o.company_name).join(', ')}
          </span>
        )}
      </td>
      <td className="px-3 py-3 text-ink">{row.position_name ?? <span className="text-ink-faint">—</span>}</td>
      <td className="px-3 py-3">
        <StatusBadge status={row.assignment_type} label={ASSIGNMENT_TYPE_LABELS[row.assignment_type]} tone={row.assignment_type === 'replacement' ? 'warning' : 'neutral'} />
        {row.needs_correction && (
          <span role="note" data-testid="needs-correction" title={row.needs_correction_reason ?? undefined} className="mt-1 block text-xs text-danger">
            Needs correction: Temporary worker registered as a Replacement. No payroll until corrected.
          </span>
        )}
      </td>
      <td className="px-3 py-3 text-ink" data-testid="shift-cell">
        {row.shift === null ? <span className="text-ink-faint">Not recorded</span> : assignmentShiftLabel(row.shift)}
      </td>
      <td className="px-3 py-3 text-ink">
        {formatDate(row.start_date)}
        {row.end_date && <span className="block text-xs text-ink-muted">to {formatDate(row.end_date)}</span>}
      </td>
      <td className="px-3 py-3 text-ink">
        {row.payable_days} <span className="text-xs text-ink-muted">of {row.days_in_month}</span>
        <span className="block text-xs text-ink-muted" title="Marks HR recorded for the days of this assignment; days without a mark are not assumed worked or absent.">
          {row.attendance.recorded === 0 ? 'no attendance recorded' : `${row.attendance.present + row.attendance.late} present · ${row.attendance.absent} absent`}
        </span>
      </td>
      <td className="px-3 py-3 text-ink">
        {formatMoney(Number(row.salary.amount), row.salary.currency)}
        <span className="block text-xs text-ink-muted">{row.salary.frequency}{row.salary_changed_in_period ? ' · changed in this month' : ''}</span>
      </td>
      {showPayroll && (
        <td className="px-3 py-3 text-ink">
          {calc ? (
            <>
              {formatCents(calc.net_cents, calc.currency)}
              <span className="block text-xs text-ink-muted">
                {calc.is_estimate ? 'estimate' : `approved (${calc.run_status})`}
                {row.payroll?.payment ? ` · ${row.payroll.payment.status}` : ''}
              </span>
            </>
          ) : (
            <span className="text-xs text-ink-faint" title={row.payroll?.unavailable_reason ?? undefined}>not calculated</span>
          )}
        </td>
      )}
      <td className="px-3 py-3">
        <StatusBadge status={row.status} label={row.status === 'active' ? 'Active' : 'Ended'} tone={row.status === 'active' ? 'success' : 'neutral'} />
      </td>
      <td className="px-3 py-3">
        <div className="flex flex-wrap justify-end gap-1.5">
          <Link to={`/hr/employees/${row.employee_id}`} state={{ fromPath: `${window.location.pathname}${window.location.search}`, fromLabel: 'Company employees' }} className="inline-flex h-8 items-center rounded-sm border border-border px-2.5 text-xs font-semibold text-ink hover:bg-surface-alt" aria-label={`View ${row.full_name}`}>View</Link>
          {canManage && <Button variant="outline" size="sm" onClick={() => onEdit(row)} aria-label={`Edit ${row.full_name}`}>Edit</Button>}
          <RowMenu
            label={`More actions for ${row.full_name}`}
            items={[
              { label: 'History', ariaLabel: `History of ${row.full_name}`, onClick: () => onHistory(row) },
              ...(canManage && open ? [{ label: 'Replace', ariaLabel: `Replace ${row.full_name}`, onClick: () => onReplace(row) }] : []),
              ...(canManage && canReturn ? [{ label: 'Return original employee', ariaLabel: `Return original employee for ${row.full_name}`, onClick: () => onReturn(row) }] : []),
              ...(canManage && open ? [{ label: 'Stop', ariaLabel: `Stop ${row.full_name}`, onClick: () => onStop(row), danger: true }] : []),
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
        More
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
