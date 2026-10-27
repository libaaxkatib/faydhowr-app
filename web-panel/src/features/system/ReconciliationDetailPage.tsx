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
import { useI18n } from '@/i18n/useI18n';
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
  const { t, tValue } = useI18n();
  const { id } = useParams<{ id: string }>();
  const issueId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const origin = location.state as { fromPath?: string; fromLabel?: string } | null;
  const fromPath = origin?.fromPath ?? '/system/data-issues';
  const fromLabel = origin?.fromLabel ?? t('option.system.pageDataIssues');
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

  if (isLoading) return <LoadingState label={t('option.system.loadingIssue')} />;
  if (error || !issue) return <ErrorState error={error} onRetry={refetch} />;

  const canEdit = hasPermission('reconciliation.update');
  const canResolve = hasPermission('reconciliation.resolve');
  const isClosing = issue.status === 'resolved' || issue.status === 'accepted_difference' || issue.status === 'cannot_resolve';

  return (
    <div>
      <PageHeader
        title={`${issue.issue_number} — ${issue.title}`}
        breadcrumb={[{ label: t('option.system.system'), to: '/system/settings' }, { label: fromLabel, to: fromPath }, { label: issue.issue_number }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => navigate(fromPath)}>
              <Icon name="chevron-left" size={14} />
              {t('action.system.backTo', { label: fromLabel })}
            </Button>
            {canEdit && (
              <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                <Icon name="pencil" size={14} />
                {t('action.edit')}
              </Button>
            )}
            {canResolve && !isClosing && (
              <Button size="sm" onClick={() => setIsResolveOpen(true)}>
                <Icon name="check" size={14} />
                {t('action.system.resolve')}
              </Button>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.system.overview')}</h3>
            <div className="flex items-center gap-2">
              <StatusBadge status={issue.severity} tone={SEVERITY_TONE[issue.severity]} label={tValue('option.system.issueSeverity', issue.severity) ?? issue.severity_label} />
              <StatusBadge status={issue.status} tone={STATUS_TONE[issue.status]} label={tValue('option.system.issueStatus', issue.status) ?? issue.status_label} />
            </div>
          </CardHeader>
          <CardBody>
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <Field label={t('option.system.colModule')} value={tValue('option.system.issueModule', issue.module) ?? issue.module_label} />
              <Field label={t('option.label.type')} value={tValue('option.system.issueType', issue.issue_type) ?? issue.issue_type_label} />
              <Field label={t('option.label.description')} value={issue.description} />
              <div />
              <Field label={t('option.system.expected')} value={issue.expected_value} />
              <Field label={t('option.system.actual')} value={issue.actual_value} />
              <Field label={t('option.system.difference')} value={issue.difference_value} />
              <div />
              <Field label={t('option.system.rootCause')} value={issue.root_cause} />
              <Field label={t('option.system.resolution')} value={issue.resolution} />
              <Field label={t('option.system.sourceReference')} value={issue.source_reference} />
              <Field label={t('option.label.notes')} value={issue.notes} />
            </dl>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.system.timeline')}</h3>
          </CardHeader>
          <CardBody>
            <dl className="space-y-4 text-sm">
              <Field
                label={t('option.system.colCreated')}
                value={`${formatDateTime(issue.created_at)}${issue.created_by_name ? ` ${t('option.system.byName', { name: issue.created_by_name })}` : ''}`}
              />
              <Field label={t('option.system.lastUpdated')} value={formatDateTime(issue.updated_at)} />
              <Field
                label={t('option.system.colResolved')}
                value={
                  issue.resolved_at
                    ? `${formatDateTime(issue.resolved_at)}${issue.resolved_by_name ? ` ${t('option.system.byName', { name: issue.resolved_by_name })}` : ''}`
                    : null
                }
              />
            </dl>
          </CardBody>
        </Card>
      </div>

      <div className="mt-4">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.system.affectedRecordsTitle', { n: filteredRecords.length })}</h3>
          </CardHeader>
          <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
            <SearchInput
              value={recordSearch}
              onChange={(v) => {
                setRecordSearch(v);
                setRecordPage(1);
              }}
              placeholder={t('option.system.searchAffectedRecords')}
              className="min-w-[220px] flex-1"
            />
            <Select
              className={inputClasses + ' w-auto'}
              options={[
                { value: 'newest', label: t('option.system.sortNewest') },
                { value: 'oldest', label: t('option.system.sortOldest') },
                { value: 'name_asc', label: t('option.system.sortNameAsc') },
                { value: 'name_desc', label: t('option.system.sortNameDesc') },
              ]}
              value={recordSort}
              onChange={(e) => setRecordSort(e.target.value as typeof recordSort)}
            />
          </div>
          <CardBody>
            {pagedRecords.length === 0 ? (
              <EmptyState icon="users" title={t('warning.system.noAffectedRecords')} description={t('warning.system.noAffectedRecordsText')} />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt">
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.system.colEmployeeNumber')}</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.label.name')}</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.label.category')}</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.label.status')}</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.system.colNote')}</th>
                      <th className="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">{t('option.system.colLinked')}</th>
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
                          {record.employee?.deleted_at && <span className="ml-2 text-xs text-danger">{t('status.system.removedBadge')}</span>}
                        </td>
                        <td className="px-4 py-3 text-ink">{record.employee?.category_name ?? '—'}</td>
                        <td className="px-4 py-3 text-ink">{record.employee?.status != null ? (tValue('status', record.employee.status) ?? record.employee.status) : '—'}</td>
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
                <span>{t('warning.common.pageOf', { page: recordPage, pages: totalRecordPages })}</span>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" disabled={recordPage <= 1} onClick={() => setRecordPage((p) => p - 1)}>
                    {t('option.system.previous')}
                  </Button>
                  <Button variant="outline" size="sm" disabled={recordPage >= totalRecordPages} onClick={() => setRecordPage((p) => p + 1)}>
                    {t('option.system.next')}
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
            <h3 className="font-display text-sm font-bold text-ink">{t('option.system.auditTrail')}</h3>
          </CardHeader>
          <CardBody>
            {!issue.audit_logs || issue.audit_logs.length === 0 ? (
              <EmptyState icon="list" title={t('warning.system.noChangesRecorded')} />
            ) : (
              <div className="space-y-3">
                {issue.audit_logs.map((entry) => (
                  <div key={entry.id} className="flex items-start justify-between border-b border-border pb-3 text-sm last:border-0">
                    <div>
                      <p className="text-ink">
                        <span className="font-medium">{entry.admin_name ?? t('option.system.system')}</span> {t('option.system.changed')}{' '}
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
