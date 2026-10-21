import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { facilityApi } from '@/api/facility';
import { hrApi } from '@/api/hr';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorState } from '@/components/ui/ErrorState';
import { inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { PageHeader } from '@/components/ui/PageHeader';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { formatDate } from '@/utils/formatters';
import { dueStateTone, percent } from '@/utils/facility';
import type { FacilityComplaintType, FacilityDueListRow, FacilityReport } from '@/types/facility';

function Tile({ label, value, hint, to }: { label: string; value: string | number; hint?: string; to?: string }) {
  const body = (
    <div className="rounded-lg border border-border bg-surface px-4 py-3 shadow-card">
      <p className="text-xs font-medium text-ink-muted">{label}</p>
      <p className="font-display text-2xl font-bold text-ink">{value}</p>
      {hint && <p className="text-xs text-ink-faint">{hint}</p>}
    </div>
  );

  return to ? <Link to={to} className="block transition hover:opacity-90">{body}</Link> : body;
}

/** One labelled bar. The number is always printed, so the bar is never the only carrier of the value. */
function BarRow({ label, value, max, tone = 'bg-primary', suffix }: { label: string; value: number; max: number; tone?: string; suffix?: string }) {
  const width = max <= 0 ? 0 : Math.max(value > 0 ? 3 : 0, Math.round((value / max) * 100));

  return (
    <div className="flex items-center gap-3 py-1">
      <span className="w-40 shrink-0 truncate text-xs text-ink">{label}</span>
      <div className="h-3 flex-1 rounded-full bg-surface-alt" role="img" aria-label={`${label}: ${value}${suffix ?? ''}`}>
        <div className={`h-3 rounded-full ${tone}`} style={{ width: `${width}%` }} />
      </div>
      <span className="w-14 shrink-0 text-right text-xs font-semibold text-ink">{value}{suffix}</span>
    </div>
  );
}

function DueList({ title, rows, tone }: { title: string; rows: FacilityDueListRow[]; tone: 'danger' | 'warning' }) {
  return (
    <Card>
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">{title}</h3>
        <StatusBadge status={title} tone={rows.length > 0 ? tone : 'success'} label={String(rows.length)} />
      </CardHeader>
      <CardBody>
        {rows.length === 0 ? (
          <p className="text-sm text-ink-muted">None.</p>
        ) : (
          <ul className="divide-y divide-border">
            {rows.map((row) => (
              <li key={row.client_company_id} className="flex items-center justify-between gap-2 py-2 text-sm">
                <Link to={`/hr/facility-inspections?company=${row.client_company_id}`} className="font-medium text-primary hover:underline">{row.company}</Link>
                <span className="text-xs text-ink-muted">
                  {row.last_inspected_on ? `Last ${formatDate(row.last_inspected_on)}` : 'Never inspected'}
                  {row.next_due_on ? ` · due ${formatDate(row.next_due_on)}` : ''}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

function Trend({ report }: { report: FacilityReport }) {
  const max = Math.max(1, ...report.trends.flatMap((m) => [m.inspections, m.complaints_reported, m.complaints_resolved]));

  return (
    <div>
      <div className="mb-3 flex flex-wrap gap-4 text-xs text-ink-muted">
        <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-primary" /> Inspections</span>
        <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-danger" /> Complaints reported</span>
        <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-success" /> Complaints resolved</span>
      </div>
      <div className="flex items-end gap-3 overflow-x-auto pb-1">
        {report.trends.map((month) => (
          <div key={month.month} className="flex min-w-[3.5rem] flex-col items-center gap-1">
            <div className="flex h-32 items-end gap-1">
              {[
                { v: month.inspections, c: 'bg-primary', l: 'inspections' },
                { v: month.complaints_reported, c: 'bg-danger', l: 'complaints reported' },
                { v: month.complaints_resolved, c: 'bg-success', l: 'complaints resolved' },
              ].map((bar) => (
                <div key={bar.l} className="flex flex-col items-center justify-end gap-0.5">
                  <span className="text-[10px] font-semibold text-ink">{bar.v}</span>
                  <div className={`w-3.5 rounded-t-sm ${bar.c}`} style={{ height: `${Math.max(bar.v > 0 ? 3 : 0, Math.round((bar.v / max) * 100))}px` }} role="img" aria-label={`${month.month} ${bar.l}: ${bar.v}`} />
                </div>
              ))}
            </div>
            <span className="text-[11px] text-ink-muted">{month.month}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * Facility report: inspections, what was found, complaints and documented satisfaction, from the stored records only. Every figure respects the
 * filters and links down to the records behind it. Nothing is estimated - "not recorded" is its own number.
 */
export function FacilityReportPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const company = /^\d+$/.test(searchParams.get('company') ?? '') ? (searchParams.get('company') ?? '') : '';
  const type = (['staff', 'customer'].includes(searchParams.get('type') ?? '') ? searchParams.get('type') : '') as FacilityComplaintType | '';
  const from = searchParams.get('from') ?? '';
  const to = searchParams.get('to') ?? '';
  const [showInfrequent, setShowInfrequent] = useState(true);

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['facility-report', company, type, from, to],
    queryFn: () => facilityApi.report({ client_company_id: company ? Number(company) : undefined, type, from: from || undefined, to: to || undefined }),
  });

  function setParam(key: string, value: string) {
    setSearchParams(
      (previous) => {
        const next = new URLSearchParams(previous);
        if (value === '') next.delete(key);
        else next.set(key, value);

        return next;
      },
      { replace: true },
    );
  }

  const companyQuery = company ? `&company=${company}` : '';
  const maxItem = Math.max(1, ...(data?.items.by_item.map((i) => i.unsatisfactory) ?? [0]));
  const sat = data?.satisfaction;
  const satTotal = sat ? sat.satisfied + sat.not_satisfied + sat.not_recorded : 0;

  return (
    <div>
      <PageHeader title="Facility Report" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Reports', to: '/hr/reports' }, { label: 'Facility Report' }]} />

      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-2 px-4 py-3">
          <Select value={company} onChange={(e) => setParam('company', e.target.value)} placeholder="All companies" aria-label="Company" className="w-52" options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
          <Select value={type} onChange={(e) => setParam('type', e.target.value)} placeholder="All complaint types" aria-label="Complaint type" className="w-48" options={[{ value: 'staff', label: 'Staff complaints' }, { value: 'customer', label: 'Customer complaints' }]} />
          <label className="flex items-center gap-1.5 text-xs text-ink-muted">
            From <input type="date" value={from} onChange={(e) => setParam('from', e.target.value)} className={`${inputClasses} w-36`} />
          </label>
          <label className="flex items-center gap-1.5 text-xs text-ink-muted">
            To <input type="date" value={to} onChange={(e) => setParam('to', e.target.value)} className={`${inputClasses} w-36`} />
          </label>
          {(company || type || from || to) && (
            <button type="button" onClick={() => setSearchParams({}, { replace: true })} className="text-xs font-semibold text-primary hover:underline">Clear filters</button>
          )}
          {data && <span className="ml-auto text-xs text-ink-faint">{formatDate(data.period.from)} to {formatDate(data.period.to)} ({data.period.days} days; default is the last 6 months)</span>}
        </div>
      </Card>

      {isLoading && <LoadingState label="Loading report…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {data && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Tile label="Inspections done" value={data.inspections.count} hint={`${data.inspections.companies_inspected} of ${data.inspections.companies_in_scope} companies`} to={`/hr/facility-inspections${company ? `?company=${company}` : ''}`} />
            <Tile label="Overdue / due now" value={data.inspections.overdue + data.inspections.due} hint={`${data.inspections.due_soon} due soon`} to="/hr/facility-inspections?state=overdue" />
            <Tile label="Never inspected" value={data.inspections.never_inspected} to="/hr/facility-inspections?state=never_inspected" />
            <Tile label="Unsatisfactory findings" value={data.items.unsatisfactory_total} hint={`${data.items.uniform_issues} uniform issues`} />
            <Tile label="Complaints" value={data.complaints.total} hint={`${data.complaints.by_type.staff.total} staff · ${data.complaints.by_type.customer.total} customer`} to={`/hr/facility-complaints?${companyQuery.slice(1)}`} />
            <Tile label="Not resolved yet" value={data.complaints.unresolved} hint={`${data.complaints.answered} answered, awaiting resolution`} to={`/hr/facility-complaints?status=unresolved${companyQuery}`} />
            <Tile label="Avg. days to respond" value={data.complaints.average_days_to_response ?? '-'} hint={data.complaints.average_days_to_response === null ? 'No responses recorded' : undefined} />
            <Tile label="Avg. days to resolve" value={data.complaints.average_days_to_resolution ?? '-'} hint={data.complaints.average_days_to_resolution === null ? 'No resolved complaints' : undefined} />
          </div>

          <Card>
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">Inspections and complaints by month</h3></CardHeader>
            <CardBody>{data.trends.length === 0 ? <EmptyState title="No months in this period" /> : <Trend report={data} />}</CardBody>
          </Card>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader><h3 className="font-display text-sm font-bold text-ink">Unsatisfactory findings by item</h3></CardHeader>
              <CardBody>
                {data.items.unsatisfactory_total === 0 ? (
                  <p className="text-sm text-ink-muted">No unsatisfactory items in this period.</p>
                ) : (
                  data.items.by_item.map((item) => <BarRow key={item.item_key} label={item.label} value={item.unsatisfactory} max={maxItem} tone="bg-danger" suffix={item.checked > 0 ? ` / ${item.checked}` : ''} />)
                )}
                <p className="mt-2 text-xs text-ink-faint">Each bar shows unsatisfactory / times the item was checked.</p>
              </CardBody>
            </Card>

            <Card>
              <CardHeader><h3 className="font-display text-sm font-bold text-ink">Complaints by status</h3></CardHeader>
              <CardBody>
                {(['open', 'in_progress', 'answered', 'resolved'] as const).map((s) => (
                  <BarRow
                    key={s}
                    label={{ open: 'New / open', in_progress: 'In progress', answered: 'Answered', resolved: 'Resolved' }[s]}
                    value={data.complaints.by_status[s]}
                    max={Math.max(1, data.complaints.total)}
                    tone={{ open: 'bg-danger', in_progress: 'bg-warning', answered: 'bg-secondary', resolved: 'bg-success' }[s]}
                  />
                ))}
                <p className="mt-2 text-xs text-ink-faint">"Answered" means a reply was given; it is not counted as resolved.</p>
              </CardBody>
            </Card>

            <Card>
              <CardHeader><h3 className="font-display text-sm font-bold text-ink">Documented satisfaction</h3></CardHeader>
              <CardBody>
                <BarRow label="Satisfied (documented)" value={sat?.satisfied ?? 0} max={Math.max(1, satTotal)} tone="bg-success" />
                <BarRow label="Not satisfied (documented)" value={sat?.not_satisfied ?? 0} max={Math.max(1, satTotal)} tone="bg-danger" />
                <BarRow label="Not recorded" value={sat?.not_recorded ?? 0} max={Math.max(1, satTotal)} tone="bg-ink-faint" />
                <p className="mt-2 text-xs text-ink-faint">
                  Of {satTotal} complaint(s), {percent((sat?.satisfied ?? 0) + (sat?.not_satisfied ?? 0), satTotal)} have explicit feedback on record. "Not recorded" is neither good nor bad.
                </p>
              </CardBody>
            </Card>

            <Card>
              <CardHeader><h3 className="font-display text-sm font-bold text-ink">Complaints by type</h3></CardHeader>
              <CardBody>
                <BarRow label="Staff complaints" value={data.complaints.by_type.staff.total} max={Math.max(1, data.complaints.total)} tone="bg-primary" />
                <BarRow label="Customer complaints" value={data.complaints.by_type.customer.total} max={Math.max(1, data.complaints.total)} tone="bg-secondary" />
                <p className="mt-2 text-xs text-ink-faint">Not resolved: {data.complaints.by_type.staff.unresolved} staff, {data.complaints.by_type.customer.unresolved} customer.</p>
              </CardBody>
            </Card>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <DueList title="Overdue" rows={data.schedule_status.overdue} tone="danger" />
            <DueList title="Never inspected" rows={data.schedule_status.never_inspected} tone="warning" />
          </div>

          {data.infrequent.length > 0 && (
            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">Inspected less often than their own schedule</h3>
                <button type="button" className="text-xs font-semibold text-primary hover:underline" onClick={() => setShowInfrequent((v) => !v)}>{showInfrequent ? 'Hide' : 'Show'}</button>
              </CardHeader>
              {showInfrequent && (
                <CardBody>
                  <ul className="divide-y divide-border">
                    {data.infrequent.map((row) => (
                      <li key={row.client_company_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <Link to={`/hr/facility-inspections?company=${row.client_company_id}`} className="font-medium text-primary hover:underline">{row.company}</Link>
                        <span className="text-xs text-ink-muted">Every {row.frequency_days} days · expected {row.expected_inspections}, done {row.actual_inspections}{row.last_inspected_on ? ` · last ${formatDate(row.last_inspected_on)}` : ''}</span>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-ink-faint">Only companies with a frequency set can be judged; the others are not guessed.</p>
                </CardBody>
              )}
            </Card>
          )}

          <Card>
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">By company</h3></CardHeader>
            {data.by_company.length === 0 ? (
              <EmptyState icon="bar-chart" title="No inspections or complaints in this period" />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                      <th className="px-4 py-2.5 font-semibold">Company / Guri</th>
                      <th className="px-4 py-2.5 font-semibold">Inspections</th>
                      <th className="px-4 py-2.5 font-semibold">Complaints</th>
                      <th className="px-4 py-2.5 font-semibold">Not resolved</th>
                      <th className="px-4 py-2.5 font-semibold">Satisfied / not</th>
                      <th className="px-4 py-2.5 font-semibold">Schedule</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {data.by_company.map((row) => (
                      <tr key={row.client_company_id}>
                        <td className="px-4 py-2.5 font-medium text-ink">{row.company}</td>
                        <td className="px-4 py-2.5">
                          <Link className="text-primary hover:underline" to={`/hr/facility-inspections?company=${row.client_company_id}`}>{row.inspections}</Link>
                        </td>
                        <td className="px-4 py-2.5">
                          <Link className="text-primary hover:underline" to={`/hr/facility-complaints?company=${row.client_company_id}`}>{row.complaints}</Link>
                        </td>
                        <td className="px-4 py-2.5 text-ink">{row.unresolved_complaints}</td>
                        <td className="px-4 py-2.5 text-ink">{row.satisfied} / {row.not_satisfied}</td>
                        <td className="px-4 py-2.5">
                          <StatusBadge status={row.state} tone={dueStateTone(row.state)} label={{ overdue: 'Overdue', due: 'Due', due_soon: 'Due soon', never_inspected: 'Never inspected', ok: 'Up to date', no_schedule: 'No frequency set' }[row.state]} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}
