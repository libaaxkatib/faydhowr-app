import { useEffect, useState } from 'react';
import { useLocation, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { needLabel } from '@/utils/marketingLabels';
import { XarunFormDialog } from '@/features/marketing/XarunFormDialog';
import { ProjectFormDialog } from '@/features/marketing/ProjectFormDialog';
import { formatCurrency, formatDate, formatDateTime } from '@/utils/formatters';
import type { MarketingQuotationStatus, MarketingRecordStatus } from '@/types/marketing';

const STATUS_OPTIONS: MarketingRecordStatus[] = ['pending', 'quotation', 'done', 'cancelled'];
const QUOTATION_STATUS_OPTIONS: { value: MarketingQuotationStatus; label: string; labelKey: MessageKey }[] = [
  { value: 'draft', label: 'Draft', labelKey: 'status.marketing.quotation.draft' },
  { value: 'sent', label: 'Sent', labelKey: 'status.marketing.quotation.sent' },
  { value: 'accepted', label: 'Accepted', labelKey: 'status.marketing.quotation.accepted' },
  { value: 'declined', label: 'Declined', labelKey: 'status.marketing.quotation.declined' },
];

const RECORD_STATUS_KEYS: Record<MarketingRecordStatus, MessageKey> = {
  pending: 'status.marketing.record.pending',
  quotation: 'status.marketing.record.quotation',
  done: 'status.marketing.record.done',
  cancelled: 'status.marketing.record.cancelled',
};

export function MarketingRecordDetailPage() {
  const { id } = useParams<{ id: string }>();
  const origin = useLocation().state as { fromPath?: string; fromLabel?: string } | null;
  const recordId = Number(id);
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [followUpDate, setFollowUpDate] = useState('');
  const [quotationAmount, setQuotationAmount] = useState('');
  const [assignTeamId, setAssignTeamId] = useState('');
  const [assignAdminId, setAssignAdminId] = useState('');
  const [expandedFollowUpIds, setExpandedFollowUpIds] = useState<Set<number>>(new Set());

  function toggleFollowUpHistory(id: number) {
    setExpandedFollowUpIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  const { data: record, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-record', recordId],
    queryFn: () => marketingApi.records.get(recordId),
    enabled: Number.isFinite(recordId),
  });

  const { data: teams } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  useEffect(() => {
    if (record) {
      setAssignTeamId(record.assigned_team_id ? String(record.assigned_team_id) : '');
      setAssignAdminId(record.assigned_admin_id ? String(record.assigned_admin_id) : '');
    }
  }, [record]);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['marketing-record', recordId] });
    queryClient.invalidateQueries({ queryKey: ['marketing-records'] });
  };

  const statusMutation = useMutation({
    mutationFn: (status: MarketingRecordStatus) => marketingApi.records.updateStatus(recordId, status),
    onSuccess: () => {
      invalidate();
      show(t('notify.marketing.statusUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotUpdateStatus'), 'error'),
  });

  const followUpMutation = useMutation({
    mutationFn: () => marketingApi.followUps.create(recordId, { follow_up_date: followUpDate }),
    onSuccess: () => {
      invalidate();
      setFollowUpDate('');
      show(t('notify.marketing.followUpScheduled'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotScheduleFollowUp'), 'error'),
  });

  const completeFollowUpMutation = useMutation({
    mutationFn: (followUpId: number) => marketingApi.followUps.complete(followUpId),
    onSuccess: () => {
      invalidate();
      show(t('notify.marketing.followUpCompleted'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotCompleteFollowUp'), 'error'),
  });

  const quotationMutation = useMutation({
    mutationFn: () => marketingApi.quotations.create(recordId, { amount: quotationAmount ? Number(quotationAmount) : null }),
    onSuccess: () => {
      invalidate();
      setQuotationAmount('');
      show(t('notify.marketing.quotationAdded'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotAddQuotation'), 'error'),
  });

  const quotationStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: MarketingQuotationStatus }) => marketingApi.quotations.updateStatus(id, status),
    onSuccess: () => {
      invalidate();
      show(t('notify.marketing.quotationStatusUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotUpdateQuotationStatus'), 'error'),
  });

  const assignMutation = useMutation({
    mutationFn: () =>
      marketingApi.records.assign(recordId, {
        assigned_team_id: assignTeamId ? Number(assignTeamId) : null,
        assigned_admin_id: assignAdminId ? Number(assignAdminId) : null,
      }),
    onSuccess: () => {
      invalidate();
      show(t('notify.marketing.assignmentUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.couldNotUpdateAssignment'), 'error'),
  });

  if (isLoading) return <LoadingState label={t('option.marketing.loadingRecord')} />;
  if (error || !record) return <ErrorState error={error} onRetry={refetch} />;

  const isXarun = record.type === 'xarun';

  return (
    <div>
      <PageHeader
        title={isXarun ? record.xarun?.facility_name ?? record.record_number : record.project?.responsible_person_name ?? record.record_number}
        breadcrumb={[
          { label: t('nav.marketing.marketing'), to: '/marketing' },
          // Back to the list exactly as it was left (search, status, page), or the Follow-ups tab it came from.
          { label: (origin?.fromLabel === 'Follow-ups' ? t('nav.marketing.followUps') : origin?.fromLabel) ?? (isXarun ? 'XARUN' : 'PROJECT'), to: origin?.fromPath?.startsWith('/marketing/') ? origin.fromPath : isXarun ? '/marketing/xarun' : '/marketing/project' },
          { label: record.record_number },
        ]}
        actions={
          <PermissionGate module="marketing">
            <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
              <Icon name="pencil" size={14} />
              {t('action.edit')}
            </Button>
          </PermissionGate>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.typeDetails', { type: isXarun ? 'XARUN' : 'PROJECT' })}</h3>
            <StatusBadge status={record.status} label={tValue('status.marketing.record', record.status)} tone={record.status === 'done' ? 'success' : record.status === 'cancelled' ? 'danger' : record.status === 'quotation' ? 'info' : 'warning'} />
          </CardHeader>
          <CardBody>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              {isXarun ? (
                <>
                  <Field label={t('option.marketing.facilityCompany')} value={record.xarun?.facility_name ?? '—'} />
                  <Field label={t('option.marketing.managerContact')} value={record.xarun?.manager_name ?? '—'} />
                  <Field label={t('option.marketing.contactTitle')} value={record.xarun?.manager_title ?? '—'} />
                  <Field label={t('option.label.phone')} value={record.xarun?.phone ?? '—'} />
                  <Field label={t('option.label.location')} value={record.xarun?.location ?? '—'} />
                  <Field label={t('option.marketing.needs')} value={record.xarun?.needs?.map((need) => needLabel(need, t)).join(', ') || '—'} />
                </>
              ) : (
                <>
                  <Field label={t('option.marketing.responsibleParty')} value={record.project ? tValue('option.marketing.party', record.project.responsible_party_type) ?? record.project.responsible_party_type : '—'} />
                  <Field label={t('option.label.company')} value={record.project?.company_name ?? t('option.marketing.notApplicable')} />
                  <Field label={t('option.marketing.responsiblePerson')} value={record.project?.responsible_person_name ?? '—'} />
                  <Field label={t('option.label.phone')} value={record.project?.phone ?? '—'} />
                  <Field label={t('option.label.location')} value={record.project?.location ?? '—'} />
                  <Field label={t('option.marketing.projectType')} value={record.project?.project_type ?? '—'} />
                  <Field label={t('option.marketing.projectSize')} value={record.project?.project_size ?? '—'} />
                  <Field label={t('option.marketing.constructionCompletion')} value={formatDate(record.project?.construction_completion_date)} />
                  <Field label={t('option.marketing.fayadhowrWorkDate')} value={formatDate(record.project?.fayadhowr_work_date)} />
                </>
              )}
              <Field label={t('option.marketing.broughtBy')} value={record.brought_by_admin_name ?? '—'} />
              <Field label={t('option.marketing.registered')} value={formatDateTime(record.created_at)} />
            </dl>

            {record.description && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">{t('option.label.description')}</p>
                <p className="text-sm text-ink">{record.description}</p>
              </div>
            )}
            {record.feedback && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">{t('option.marketing.feedback')}</p>
                <p className="text-sm text-ink">{record.feedback}</p>
              </div>
            )}

            <PermissionGate module="marketing">
              <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-border pt-5">
                <span className="text-xs font-medium text-ink-muted">{t('option.marketing.changeStatus')}</span>
                {STATUS_OPTIONS.map((option) => (
                  <Button
                    key={option}
                    variant={record.status === option ? 'primary' : 'outline'}
                    size="sm"
                    disabled={record.status === option}
                    isLoading={statusMutation.isPending && statusMutation.variables === option}
                    onClick={() => statusMutation.mutate(option)}
                  >
                    {t(RECORD_STATUS_KEYS[option])}
                  </Button>
                ))}
              </div>
            </PermissionGate>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.assignment')}</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              <PermissionGate
                module="marketing"
                permission="marketing.assign"
                fallback={
                  <dl className="grid grid-cols-1 gap-3 text-sm">
                    <Field label={t('option.marketing.team')} value={record.assigned_team_name ?? t('option.employees.unassigned')} muted={!record.assigned_team_name} />
                    <Field label={t('option.marketing.marketingEmployee')} value={record.assigned_admin_name ?? t('option.employees.unassigned')} muted={!record.assigned_admin_name} />
                  </dl>
                }
              >
                <FieldSelect label={t('option.marketing.team')} value={assignTeamId} onChange={setAssignTeamId} placeholder={t('option.employees.unassigned')} options={(teams ?? []).map((team) => ({ value: String(team.id), label: team.name }))} />
                <FieldSelect
                  label={t('option.marketing.marketingEmployee')}
                  value={assignAdminId}
                  onChange={setAssignAdminId}
                  placeholder={t('option.employees.unassigned')}
                  options={(employees ?? []).map((e) => ({ value: String(e.id), label: e.full_name }))}
                />
                <Button
                  size="sm"
                  isLoading={assignMutation.isPending}
                  disabled={assignTeamId === (record.assigned_team_id ? String(record.assigned_team_id) : '') && assignAdminId === (record.assigned_admin_id ? String(record.assigned_admin_id) : '')}
                  onClick={() => assignMutation.mutate()}
                >
                  {t('action.marketing.saveAssignment')}
                </Button>
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('nav.marketing.followUps')}</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {record.follow_ups && record.follow_ups.length > 0 ? (
                record.follow_ups.map((fu) => (
                  <div key={fu.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between">
                      <StatusBadge status={fu.status} label={tValue('status.marketing.followUp', fu.status)} tone={fu.status === 'completed' ? 'success' : 'warning'} />
                      <span className="text-xs text-ink-faint">{formatDate(fu.follow_up_date)}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                      {fu.status !== 'completed' && (
                        <PermissionGate module="marketing">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-6 px-1 text-xs"
                            isLoading={completeFollowUpMutation.isPending}
                            onClick={() => completeFollowUpMutation.mutate(fu.id)}
                          >
                            {t('action.marketing.markComplete')}
                          </Button>
                        </PermissionGate>
                      )}
                      {fu.histories && fu.histories.length > 0 && (
                        <button type="button" className="text-xs text-primary hover:underline" onClick={() => toggleFollowUpHistory(fu.id)}>
                          {expandedFollowUpIds.has(fu.id) ? t('action.marketing.hideHistory') : t('action.history')}
                        </button>
                      )}
                    </div>
                    {expandedFollowUpIds.has(fu.id) && fu.histories && (
                      <ul className="mt-2 space-y-1.5 border-l border-border pl-3">
                        {fu.histories.map((h) => (
                          <li key={h.id} className="text-xs text-ink-muted">
                            <span className="font-medium text-ink">{tValue('status.marketing.historyAction', h.action) ?? h.action.replace('_', ' ')}</span>
                            {h.note && <span> — {h.note}</span>}
                            <span className="text-ink-faint"> · {h.performed_by ?? t('option.marketing.system')} · {formatDateTime(h.created_at)}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                ))
              ) : (
                <EmptyState icon="calendar" title={t('warning.marketing.noFollowUpsYet')} />
              )}
              <PermissionGate module="marketing">
                <div className="flex items-center gap-2 border-t border-border pt-3">
                  <input type="date" value={followUpDate} onChange={(e) => setFollowUpDate(e.target.value)} className="h-9 flex-1 rounded-sm border border-border bg-surface px-2 text-sm" />
                  <Button size="sm" disabled={!followUpDate} isLoading={followUpMutation.isPending} onClick={() => followUpMutation.mutate()}>
                    {t('action.add')}
                  </Button>
                </div>
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.quotations')}</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {record.quotations && record.quotations.length > 0 ? (
                record.quotations.map((q) => (
                  <div key={q.id} className="flex items-center justify-between border-b border-border pb-3 last:border-0 last:pb-0">
                    <div>
                      <p className="text-sm font-semibold text-ink">{q.amount ? formatCurrency(Number(q.amount)) : '—'}</p>
                      <PermissionGate module="marketing" fallback={<StatusBadge status={q.status} label={tValue('status.marketing.quotation', q.status)} />}>
                        <Select
                          value={q.status}
                          onChange={(e) => quotationStatusMutation.mutate({ id: q.id, status: e.target.value as MarketingQuotationStatus })}
                          options={QUOTATION_STATUS_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))}
                          className="mt-1 h-7 w-28 text-xs"
                        />
                      </PermissionGate>
                    </div>
                    <span className="text-xs text-ink-faint">{formatDate(q.sent_at)}</span>
                  </div>
                ))
              ) : (
                <EmptyState icon="file-text" title={t('warning.marketing.noQuotationsYet')} />
              )}
              <PermissionGate module="marketing">
                <div className="flex items-center gap-2 border-t border-border pt-3">
                  <input
                    type="number"
                    placeholder={t('option.label.amount')}
                    value={quotationAmount}
                    onChange={(e) => setQuotationAmount(e.target.value)}
                    className="h-9 flex-1 rounded-sm border border-border bg-surface px-2 text-sm"
                  />
                  <Button size="sm" isLoading={quotationMutation.isPending} onClick={() => quotationMutation.mutate()}>
                    {t('action.add')}
                  </Button>
                </div>
              </PermissionGate>
            </CardBody>
          </Card>
        </div>
      </div>

      {isXarun ? (
        <XarunFormDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} mode="edit" record={record} />
      ) : (
        <ProjectFormDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} mode="edit" record={record} />
      )}
    </div>
  );
}

function Field({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
  return (
    <div>
      <dt className="text-xs text-ink-faint">{label}</dt>
      <dd className={muted ? 'italic text-ink-faint' : 'text-ink'}>{value}</dd>
    </div>
  );
}

function FieldSelect({
  label,
  value,
  onChange,
  placeholder,
  options,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
  options: { value: string; label: string }[];
}) {
  return (
    <div>
      <label className="mb-1 block text-xs text-ink-faint">{label}</label>
      <Select value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} options={options} />
    </div>
  );
}
