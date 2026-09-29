import { useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';

import { reconciliationApi } from '@/api/reconciliation';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { inputClasses } from '@/components/ui/FormField';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDateTime } from '@/utils/formatters';
import { DataIssueEditDialog } from '@/features/system/DataIssueEditDialog';
import { ResolveDataIssueDialog } from '@/features/system/ResolveDataIssueDialog';
import type { DataIssueSeverity, DataIssueStatus } from '@/types/reconciliation';

const STATUS_TONE: Record<DataIssueStatus, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  open: 'danger',
  investigating: 'warning',
  resolved: 'success',
  accepted_difference: 'info',
  cannot_resolve: 'neutral',
};

const SEVERITY_TONE: Record<DataIssueSeverity, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
};

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</dt>
      <dd className="mt-0.5 whitespace-pre-wrap text-sm text-ink">{value ?? <span className="text-ink-faint">—</span>}</dd>
    </div>
  );
}

export function ReconciliationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const issueId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const origin = location.state as { fromPath?: string; fromLabel?: string } | null;
  const fromPath = origin?.fromPath ?? '/system/data-issues';
  const fromLabel = origin?.fromLabel ?? 'Data Issues & Reconciliation';
  const { hasPermission } = useEffectivePermissions();

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [isResolveOpen, setIsResolveOpen] = useState(false);
  const [recordSearch, setRecordSearch] = useState('');
  const [recordSort, setRecordSort] = useState<'name_asc' | 'name_desc' | 'newest' | 'oldest'>('newest');
  const [recordPage, setRecordPage] = useState(1);
  const RECORDS_PER_PAGE = 10;

  const { data: issue, isLoading, error, refetch } = useQuery({
    queryKey: ['data-issue', issueId],
    queryFn: () => reconciliationApi.dataIssues.get(issueId),
  });

  const filteredRecords = useMemo(() => {
    const records = issue?.affected_records ?? [];
    const q = recordSearch.trim().toLowerCase();
    let result = q
      ? records.filter(
          (r) =>
            r.employee?.full_name.toLowerCase().includes(q) ||
            r.employee?.employee_number.toLowerCase().includes(q) ||
            r.context_note?.toLowerCase().includes(q),
        )
      : records;

    result = [...result].sort((a, b) => {
      if (recordSort === 'name_asc') return (a.employee?.full_name ?? '').localeCompare(b.employee?.full_name ?? '');
      if (recordSort === 'name_desc') return (b.employee?.full_name ?? '').localeCompare(a.employee?.full_name ?? '');
      const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
      const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;
      return recordSort === 'newest' ? bTime - aTime : aTime - bTime;
    });

    return result;
  }, [issue?.affected_records, recordSearch, recordSort]);

  const pagedRecords = filteredRecords.slice((recordPage - 1) * RECORDS_PER_PAGE, recordPage * RECORDS_PER_PAGE);
  const totalRecordPages = Math.max(1, Math.ceil(filteredRecords.length / RECORDS_PER_PAGE));

  if (isLoading) return <LoadingState label="Loading data issue…" />;
  if (error || !issue) return <ErrorState error={error} onRetry={refetch} />;

  const canEdit = hasPermission('reconciliation.update');
  const canResolve = hasPermission('reconciliation.resolve');
  const isClosing = issue.status === 'resolved' || issue.status === 'accepted_difference' || issue.status === 'cannot_resolve';

  return (
    <div>
      <PageHeader
        title={`${issue.issue_number} — ${issue.title}`}
        breadcrumb={[{ label: 'System', to: '/system/settings' }, { label: fromLabel, to: fromPath }, { label: issue.issue_number }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => navigate(fromPath)}>
              <Icon name="chevron-left" size={14} />
              Back to {fromLabel}
            </Button>
            {canEdit && (
              <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                <Icon name="pencil" size={14} />
                Edit
              </Button>
            )}
            {canResolve && !isClosing && (
              <Button size="sm" onClick={() => setIsResolveOpen(true)}>
                <Icon name="check" size={14} />
                Resolve
              </Button>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Overview</h3>
            <div className="flex items-center gap-2">
              <StatusBadge status={issue.severity} tone={SEVERITY_TONE[issue.severity]} label={issue.severity_label} />
              <StatusBadge status={issue.status} tone={STATUS_TONE[issue.status]} label={issue.status_label} />
            </div>
          </CardHeader>
          <CardBody>
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <Field label="Module" value={issue.module_label} />
              <Field label="Type" value={issue.issue_type_label} />
              <Field label="Description" value={issue.description} />
              <div />
              <Field label="Expected" value={issue.expected_value} />
              <Field label="Actual" value={issue.actual_value} />
              <Field label="Difference" value={issue.difference_value} />
              <div />
              <Field label="Root Cause" value={issue.root_cause} />
              <Field label="Resolution" value={issue.resolution} />
              <Field label="Source / Reference" value={issue.source_reference} />
              <Field label="Notes" value={issue.notes} />
            </dl>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Timeline</h3>
          </CardHeader>
          <CardBody>
            <dl className="space-y-4 text-sm">
              <Field label="Created" value={`${formatDateTime(issue.created_at)}${issue.created_by_name ? ` by ${issue.created_by_name}` : ''}`} />
              <Field label="Last Updated" value={formatDateTime(issue.updated_at)} />
              <Field
                label="Resolved"
                value={issue.resolved_at ? `${formatDateTime(issue.resolved_at)}${issue.resolved_by_name ? ` by ${issue.resolved_by_name}` : ''}` : null}
              />
            </dl>
          </CardBody>
        </Card>
      </div>

      <div className="mt-4">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Affected Records ({filteredRecords.length})</h3>
          </CardHeader>
          <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
            <SearchInput
              value={recordSearch}
              onChange={(v) => {
                setRecordSearch(v);
                setRecordPage(1);
              }}
              placeholder="Search affected records…"
              className="min-w-[220px] flex-1"
            />
            <Select
              className={inputClasses + ' w-auto'}
              options={[
                { value: 'newest', label: 'Newest first' },
                { value: 'oldest', label: 'Oldest first' },
                { value: 'name_asc', label: 'Name A–Z' },
                { value: 'name_desc', label: 'Name Z–A' },
              ]}
              value={recordSort}
              onChange={(e) => setRecordSort(e.target.value as typeof recordSort)}
            />
          </div>
          <CardBody>
            {pagedRecords.length === 0 ? (
              <EmptyState icon="users" title="No affected records" description="No employees or records are linked to this issue yet." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt">
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Employee #</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Name</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Category</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Status</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Note</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">Linked</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pagedRecords.map((record) => (
                      <tr
                        key={record.id}
                        className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-alt"
                        onClick={() =>
                          record.employee &&
                          navigate(`/hr/employees/${record.employee.id}`, {
                            state: { fromPath: `/system/data-issues/${issue.id}`, fromLabel: issue.issue_number },
                          })
                        }
                      >
                        <td className="px-4 py-3 font-mono text-xs text-ink">{record.employee?.employee_number ?? '—'}</td>
                        <td className="px-4 py-3 text-ink">
                          {record.employee?.full_name ?? '—'}
                          {record.employee?.deleted_at && <span className="ml-2 text-xs text-danger">(removed)</span>}
                        </td>
                        <td className="px-4 py-3 text-ink">{record.employee?.category_name ?? '—'}</td>
                        <td className="px-4 py-3 text-ink">{record.employee?.status ?? '—'}</td>
                        <td className="px-4 py-3 text-ink-muted">{record.context_note ?? '—'}</td>
                        <td className="px-4 py-3 text-ink-muted">{record.created_at ? formatDateTime(record.created_at) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {totalRecordPages > 1 && (
              <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
                <span>
                  Page {recordPage} of {totalRecordPages}
                </span>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" disabled={recordPage <= 1} onClick={() => setRecordPage((p) => p - 1)}>
                    Previous
                  </Button>
                  <Button variant="outline" size="sm" disabled={recordPage >= totalRecordPages} onClick={() => setRecordPage((p) => p + 1)}>
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      <div className="mt-4">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Audit Trail</h3>
          </CardHeader>
          <CardBody>
            {!issue.audit_logs || issue.audit_logs.length === 0 ? (
              <EmptyState icon="list" title="No changes recorded yet" />
            ) : (
              <div className="space-y-3">
                {issue.audit_logs.map((entry) => (
                  <div key={entry.id} className="flex items-start justify-between border-b border-border pb-3 text-sm last:border-0">
                    <div>
                      <p className="text-ink">
                        <span className="font-medium">{entry.admin_name ?? 'System'}</span> changed{' '}
                        <span className="font-mono text-xs">{entry.field_changed}</span>
                      </p>
                      <p className="mt-0.5 text-xs text-ink-muted">
                        {entry.old_value ?? '—'} <Icon name="chevron-right" size={11} /> {entry.new_value ?? '—'}
                      </p>
                    </div>
                    <span className="whitespace-nowrap text-xs text-ink-faint">{entry.created_at ? formatDateTime(entry.created_at) : '—'}</span>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      <DataIssueEditDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} issue={issue} />
      <ResolveDataIssueDialog isOpen={isResolveOpen} onClose={() => setIsResolveOpen(false)} issue={issue} />
    </div>
  );
}
