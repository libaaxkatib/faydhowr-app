import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Select } from '@/components/ui/Select';
import { inputClasses } from '@/components/ui/FormField';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatDate, formatDateTime } from '@/utils/formatters';
import type { FollowUpFilter, MarketingRecordStatus } from '@/types/marketing';

const TABS: { value: FollowUpFilter; label: string }[] = [
  { value: 'today', label: "Today's Follow-ups" },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'overdue', label: 'Overdue' },
];

const RECORD_STATUS_OPTIONS: { value: MarketingRecordStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'quotation', label: 'Quotation' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
];

/** SRS §15's reminder actions. Only one inline editor is open per follow-up at a time. */
type ActiveEditor = { id: number; kind: 'reschedule' | 'feedback' | 'status' } | null;

function whatsAppHref(phone: string): string {
  return `https://wa.me/${phone.replace(/[^\d]/g, '')}`;
}

function mapsHref(location: string): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location)}`;
}

export function FollowUpsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [tab, setTab] = useState<FollowUpFilter>('today');
  const [editor, setEditor] = useState<ActiveEditor>(null);
  const [rescheduleDate, setRescheduleDate] = useState('');
  const [feedbackText, setFeedbackText] = useState('');
  const [statusValue, setStatusValue] = useState<MarketingRecordStatus>('pending');
  const [expandedHistoryId, setExpandedHistoryId] = useState<number | null>(null);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-follow-ups', tab],
    queryFn: () => marketingApi.followUps.list({ filter: tab }),
  });

  function closeEditor() {
    setEditor(null);
    setRescheduleDate('');
    setFeedbackText('');
  }

  const completeMutation = useMutation({
    mutationFn: (id: number) => marketingApi.followUps.complete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      show('Follow-up marked completed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not complete follow-up.', 'error'),
  });

  const rescheduleMutation = useMutation({
    mutationFn: (id: number) => marketingApi.followUps.reschedule(id, rescheduleDate),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      closeEditor();
      show('Follow-up rescheduled.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not reschedule follow-up.', 'error'),
  });

  const feedbackMutation = useMutation({
    mutationFn: (id: number) => marketingApi.followUps.addFeedback(id, feedbackText),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      closeEditor();
      show('Feedback added.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not add feedback.', 'error'),
  });

  const statusMutation = useMutation({
    mutationFn: (id: number) => marketingApi.followUps.updateRecordStatus(id, statusValue),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      closeEditor();
      show('Status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update status.', 'error'),
  });

  return (
    <div>
      <PageHeader title="Follow-ups" breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Follow-ups' }]} />

      <div className="mb-4 flex gap-1 rounded-md border border-border bg-surface p-1">
        {TABS.map((t) => (
          <button
            key={t.value}
            type="button"
            onClick={() => setTab(t.value)}
            className={`flex-1 rounded-sm px-3 py-2 text-sm font-medium transition ${
              tab === t.value ? 'bg-primary text-white' : 'text-ink-muted hover:bg-surface-alt'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <Card>
        {isLoading && <LoadingState label="Loading follow-ups…" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="calendar" title="Nothing here" description="No follow-ups match this view." />}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((fu) => (
              <div key={fu.id} className="px-5 py-4">
                <div className="flex items-center justify-between">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      {fu.record_type && (
                        <span className="rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary">
                          {fu.record_type === 'xarun' ? 'XARUN' : 'PROJECT'}
                        </span>
                      )}
                      <button
                        type="button"
                        onClick={() => navigate(`/marketing/${fu.record_type}/${fu.marketing_record_id}`)}
                        className="text-left font-medium text-ink hover:text-primary"
                      >
                        {fu.record_number}
                      </button>
                    </div>
                    <p className="mt-0.5 text-xs text-ink-muted">
                      <span className="text-ink-faint">Team:</span>{' '}
                      <span className={fu.record_team_name ? '' : 'italic text-ink-faint'}>{fu.record_team_name ?? 'Unassigned'}</span>
                      {' · '}
                      <span className="text-ink-faint">Employee:</span>{' '}
                      <span className={fu.assigned_admin ? '' : 'italic text-ink-faint'}>{fu.assigned_admin ?? 'Unassigned'}</span>
                      {' · '}
                      {formatDate(fu.follow_up_date)}
                    </p>
                  </div>
                  <StatusBadge status={fu.status} tone={fu.status === 'completed' ? 'success' : 'warning'} />
                </div>

                <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                  {fu.phone && (
                    <a
                      href={`tel:${fu.phone}`}
                      className="flex h-7 items-center gap-1 rounded-sm border border-border px-2 text-xs text-ink-muted hover:bg-surface-alt hover:text-primary"
                    >
                      <Icon name="phone" size={13} /> Call
                    </a>
                  )}
                  {fu.phone && (
                    <a
                      href={whatsAppHref(fu.phone)}
                      target="_blank"
                      rel="noreferrer"
                      className="flex h-7 items-center gap-1 rounded-sm border border-border px-2 text-xs text-ink-muted hover:bg-surface-alt hover:text-primary"
                    >
                      <Icon name="message-circle" size={13} /> WhatsApp
                    </a>
                  )}
                  {fu.location && (
                    <a
                      href={mapsHref(fu.location)}
                      target="_blank"
                      rel="noreferrer"
                      className="flex h-7 items-center gap-1 rounded-sm border border-border px-2 text-xs text-ink-muted hover:bg-surface-alt hover:text-primary"
                    >
                      <Icon name="map-pin" size={13} /> Visit
                    </a>
                  )}

                  <PermissionGate module="marketing">
                    <Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => setEditor({ id: fu.id, kind: 'feedback' })}>
                      <Icon name="pencil" size={13} /> Add Feedback
                    </Button>
                    <Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => setEditor({ id: fu.id, kind: 'status' })}>
                      <Icon name="flag" size={13} /> Update Status
                    </Button>
                    <Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => setEditor({ id: fu.id, kind: 'reschedule' })}>
                      Reschedule
                    </Button>
                    {fu.status !== 'completed' && (
                      <Button size="sm" className="h-7 px-2 text-xs" isLoading={completeMutation.isPending} onClick={() => completeMutation.mutate(fu.id)}>
                        Complete
                      </Button>
                    )}
                  </PermissionGate>

                  {fu.histories && fu.histories.length > 0 && (
                    <button
                      type="button"
                      className="ml-auto text-xs text-primary hover:underline"
                      onClick={() => setExpandedHistoryId(expandedHistoryId === fu.id ? null : fu.id)}
                    >
                      {expandedHistoryId === fu.id ? 'Hide history' : 'History'}
                    </button>
                  )}
                </div>

                {editor?.id === fu.id && editor.kind === 'reschedule' && (
                  <div className="mt-2.5 flex items-center gap-1.5 border-t border-border pt-2.5">
                    <input type="date" value={rescheduleDate} onChange={(e) => setRescheduleDate(e.target.value)} className="h-8 rounded-sm border border-border bg-surface px-2 text-xs" />
                    <Button size="sm" disabled={!rescheduleDate} isLoading={rescheduleMutation.isPending} onClick={() => rescheduleMutation.mutate(fu.id)}>
                      Save
                    </Button>
                    <Button size="sm" variant="ghost" onClick={closeEditor}>
                      <Icon name="x" size={14} />
                    </Button>
                  </div>
                )}

                {editor?.id === fu.id && editor.kind === 'feedback' && (
                  <div className="mt-2.5 flex items-start gap-1.5 border-t border-border pt-2.5">
                    <textarea
                      rows={2}
                      placeholder="What was encountered…"
                      value={feedbackText}
                      onChange={(e) => setFeedbackText(e.target.value)}
                      className={inputClasses + ' h-auto flex-1 py-1.5 text-xs'}
                    />
                    <Button size="sm" disabled={!feedbackText.trim()} isLoading={feedbackMutation.isPending} onClick={() => feedbackMutation.mutate(fu.id)}>
                      Save
                    </Button>
                    <Button size="sm" variant="ghost" onClick={closeEditor}>
                      <Icon name="x" size={14} />
                    </Button>
                  </div>
                )}

                {editor?.id === fu.id && editor.kind === 'status' && (
                  <div className="mt-2.5 flex items-center gap-1.5 border-t border-border pt-2.5">
                    <Select value={statusValue} onChange={(e) => setStatusValue(e.target.value as MarketingRecordStatus)} options={RECORD_STATUS_OPTIONS} className="h-8 w-36 text-xs" />
                    <Button size="sm" isLoading={statusMutation.isPending} onClick={() => statusMutation.mutate(fu.id)}>
                      Save
                    </Button>
                    <Button size="sm" variant="ghost" onClick={closeEditor}>
                      <Icon name="x" size={14} />
                    </Button>
                  </div>
                )}

                {expandedHistoryId === fu.id && fu.histories && (
                  <ul className="mt-2.5 space-y-1.5 border-t border-border pt-2.5 pl-1">
                    {fu.histories.map((h) => (
                      <li key={h.id} className="text-xs text-ink-muted">
                        <span className="font-medium text-ink">{h.action.replace('_', ' ')}</span>
                        {h.note && <span> — {h.note}</span>}
                        <span className="text-ink-faint"> · {h.performed_by ?? 'System'} · {formatDateTime(h.created_at)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
