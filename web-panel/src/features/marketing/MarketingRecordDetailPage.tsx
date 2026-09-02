import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { XarunFormDialog } from '@/features/marketing/XarunFormDialog';
import { ProjectFormDialog } from '@/features/marketing/ProjectFormDialog';
import { formatCurrency, formatDate, formatDateTime } from '@/utils/formatters';
import type { MarketingRecordStatus } from '@/types/marketing';

const STATUS_OPTIONS: MarketingRecordStatus[] = ['pending', 'quotation', 'done', 'cancelled'];

export function MarketingRecordDetailPage() {
  const { id } = useParams<{ id: string }>();
  const recordId = Number(id);
  const queryClient = useQueryClient();
  const { show } = useToast();

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [followUpDate, setFollowUpDate] = useState('');
  const [quotationAmount, setQuotationAmount] = useState('');

  const { data: record, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-record', recordId],
    queryFn: () => marketingApi.records.get(recordId),
    enabled: Number.isFinite(recordId),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['marketing-record', recordId] });
    queryClient.invalidateQueries({ queryKey: ['marketing-records'] });
  };

  const statusMutation = useMutation({
    mutationFn: (status: MarketingRecordStatus) => marketingApi.records.updateStatus(recordId, status),
    onSuccess: () => {
      invalidate();
      show('Status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update status.', 'error'),
  });

  const followUpMutation = useMutation({
    mutationFn: () => marketingApi.followUps.create(recordId, { follow_up_date: followUpDate }),
    onSuccess: () => {
      invalidate();
      setFollowUpDate('');
      show('Follow-up scheduled.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not schedule follow-up.', 'error'),
  });

  const completeFollowUpMutation = useMutation({
    mutationFn: (followUpId: number) => marketingApi.followUps.complete(followUpId),
    onSuccess: () => {
      invalidate();
      show('Follow-up marked completed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not complete follow-up.', 'error'),
  });

  const quotationMutation = useMutation({
    mutationFn: () => marketingApi.quotations.create(recordId, { amount: quotationAmount ? Number(quotationAmount) : null }),
    onSuccess: () => {
      invalidate();
      setQuotationAmount('');
      show('Quotation added.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not add quotation.', 'error'),
  });

  if (isLoading) return <LoadingState label="Loading record…" />;
  if (error || !record) return <ErrorState error={error} onRetry={refetch} />;

  const isXarun = record.type === 'xarun';

  return (
    <div>
      <PageHeader
        title={isXarun ? record.xarun?.facility_name ?? record.record_number : record.project?.responsible_person_name ?? record.record_number}
        breadcrumb={[
          { label: 'Marketing', to: '/marketing' },
          { label: isXarun ? 'XARUN' : 'PROJECT', to: isXarun ? '/marketing/xarun' : '/marketing/project' },
          { label: record.record_number },
        ]}
        actions={
          <PermissionGate module="marketing">
            <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
              <Icon name="pencil" size={14} />
              Edit
            </Button>
          </PermissionGate>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{isXarun ? 'XARUN' : 'PROJECT'} Details</h3>
            <StatusBadge status={record.status} tone={record.status === 'done' ? 'success' : record.status === 'cancelled' ? 'danger' : record.status === 'quotation' ? 'info' : 'warning'} />
          </CardHeader>
          <CardBody>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              {isXarun ? (
                <>
                  <Field label="Facility / Company" value={record.xarun?.facility_name ?? '—'} />
                  <Field label="Manager / Contact" value={record.xarun?.manager_name ?? '—'} />
                  <Field label="Contact title" value={record.xarun?.manager_title ?? '—'} />
                  <Field label="Phone" value={record.xarun?.phone ?? '—'} />
                  <Field label="Location" value={record.xarun?.location ?? '—'} />
                  <Field label="Needs" value={record.xarun?.needs?.join(', ') || '—'} />
                </>
              ) : (
                <>
                  <Field label="Responsible party" value={record.project?.responsible_party_type ?? '—'} />
                  <Field label="Company" value={record.project?.company_name ?? '— (not applicable)'} />
                  <Field label="Responsible person" value={record.project?.responsible_person_name ?? '—'} />
                  <Field label="Phone" value={record.project?.phone ?? '—'} />
                  <Field label="Location" value={record.project?.location ?? '—'} />
                  <Field label="Project type" value={record.project?.project_type ?? '—'} />
                  <Field label="Project size" value={record.project?.project_size ?? '—'} />
                  <Field label="Construction completion" value={formatDate(record.project?.construction_completion_date)} />
                  <Field label="Fayadhowr work date" value={formatDate(record.project?.fayadhowr_work_date)} />
                </>
              )}
              <Field label="Team" value={record.assigned_team_name ?? 'Unassigned'} muted={!record.assigned_team_name} />
              <Field label="Marketing employee" value={record.assigned_admin_name ?? 'Unassigned'} muted={!record.assigned_admin_name} />
              <Field label="Brought by" value={record.brought_by_admin_name ?? '—'} />
              <Field label="Registered" value={formatDateTime(record.created_at)} />
            </dl>

            {record.description && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">Description</p>
                <p className="text-sm text-ink">{record.description}</p>
              </div>
            )}
            {record.feedback && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">Feedback</p>
                <p className="text-sm text-ink">{record.feedback}</p>
              </div>
            )}

            <PermissionGate module="marketing">
              <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-border pt-5">
                <span className="text-xs font-medium text-ink-muted">Change status:</span>
                {STATUS_OPTIONS.map((option) => (
                  <Button
                    key={option}
                    variant={record.status === option ? 'primary' : 'outline'}
                    size="sm"
                    disabled={record.status === option}
                    isLoading={statusMutation.isPending && statusMutation.variables === option}
                    onClick={() => statusMutation.mutate(option)}
                  >
                    {option[0].toUpperCase() + option.slice(1)}
                  </Button>
                ))}
              </div>
            </PermissionGate>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Follow-ups</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {record.follow_ups && record.follow_ups.length > 0 ? (
                record.follow_ups.map((fu) => (
                  <div key={fu.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between">
                      <StatusBadge status={fu.status} tone={fu.status === 'completed' ? 'success' : 'warning'} />
                      <span className="text-xs text-ink-faint">{formatDate(fu.follow_up_date)}</span>
                    </div>
                    {fu.status !== 'completed' && (
                      <PermissionGate module="marketing">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="mt-1 h-6 px-1 text-xs"
                          isLoading={completeFollowUpMutation.isPending}
                          onClick={() => completeFollowUpMutation.mutate(fu.id)}
                        >
                          Mark complete
                        </Button>
                      </PermissionGate>
                    )}
                  </div>
                ))
              ) : (
                <EmptyState icon="calendar" title="No follow-ups yet" />
              )}
              <PermissionGate module="marketing">
                <div className="flex items-center gap-2 border-t border-border pt-3">
                  <input type="date" value={followUpDate} onChange={(e) => setFollowUpDate(e.target.value)} className="h-9 flex-1 rounded-sm border border-border bg-surface px-2 text-sm" />
                  <Button size="sm" disabled={!followUpDate} isLoading={followUpMutation.isPending} onClick={() => followUpMutation.mutate()}>
                    Add
                  </Button>
                </div>
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Quotations</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {record.quotations && record.quotations.length > 0 ? (
                record.quotations.map((q) => (
                  <div key={q.id} className="flex items-center justify-between border-b border-border pb-3 last:border-0 last:pb-0">
                    <div>
                      <p className="text-sm font-semibold text-ink">{q.amount ? formatCurrency(Number(q.amount)) : '—'}</p>
                      <StatusBadge status={q.status} />
                    </div>
                    <span className="text-xs text-ink-faint">{formatDate(q.sent_at)}</span>
                  </div>
                ))
              ) : (
                <EmptyState icon="file-text" title="No quotations yet" />
              )}
              <PermissionGate module="marketing">
                <div className="flex items-center gap-2 border-t border-border pt-3">
                  <input
                    type="number"
                    placeholder="Amount"
                    value={quotationAmount}
                    onChange={(e) => setQuotationAmount(e.target.value)}
                    className="h-9 flex-1 rounded-sm border border-border bg-surface px-2 text-sm"
                  />
                  <Button size="sm" isLoading={quotationMutation.isPending} onClick={() => quotationMutation.mutate()}>
                    Add
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
