import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatDate } from '@/utils/formatters';
import type { FollowUpFilter } from '@/types/marketing';

const TABS: { value: FollowUpFilter; label: string }[] = [
  { value: 'today', label: "Today's Follow-ups" },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'overdue', label: 'Overdue' },
];

export function FollowUpsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [tab, setTab] = useState<FollowUpFilter>('today');
  const [rescheduleId, setRescheduleId] = useState<number | null>(null);
  const [rescheduleDate, setRescheduleDate] = useState('');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['marketing-follow-ups', tab],
    queryFn: () => marketingApi.followUps.list({ filter: tab }),
  });

  const completeMutation = useMutation({
    mutationFn: (id: number) => marketingApi.followUps.complete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      show('Follow-up marked completed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not complete follow-up.', 'error'),
  });

  const rescheduleMutation = useMutation({
    mutationFn: () => marketingApi.followUps.reschedule(rescheduleId!, rescheduleDate),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-follow-ups'] });
      setRescheduleId(null);
      setRescheduleDate('');
      show('Follow-up rescheduled.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not reschedule follow-up.', 'error'),
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
              <div key={fu.id} className="flex items-center justify-between px-5 py-4">
                <div className="min-w-0">
                  <button
                    type="button"
                    onClick={() => navigate(`/marketing/${fu.record_type}/${fu.marketing_record_id}`)}
                    className="text-left font-medium text-ink hover:text-primary"
                  >
                    {fu.record_number}
                  </button>
                  <p className="text-xs text-ink-muted">
                    {fu.assigned_admin ?? 'Unassigned'} · {formatDate(fu.follow_up_date)}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge status={fu.status} tone={fu.status === 'completed' ? 'success' : 'warning'} />
                  <PermissionGate module="marketing">
                    {rescheduleId === fu.id ? (
                      <div className="flex items-center gap-1.5">
                        <input
                          type="date"
                          value={rescheduleDate}
                          onChange={(e) => setRescheduleDate(e.target.value)}
                          className="h-8 rounded-sm border border-border bg-surface px-2 text-xs"
                        />
                        <Button size="sm" isLoading={rescheduleMutation.isPending} onClick={() => rescheduleMutation.mutate()}>
                          Save
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setRescheduleId(null)}>
                          <Icon name="x" size={14} />
                        </Button>
                      </div>
                    ) : (
                      <>
                        <Button variant="outline" size="sm" onClick={() => setRescheduleId(fu.id)}>
                          Reschedule
                        </Button>
                        {fu.status !== 'completed' && (
                          <Button size="sm" isLoading={completeMutation.isPending} onClick={() => completeMutation.mutate(fu.id)}>
                            Complete
                          </Button>
                        )}
                      </>
                    )}
                  </PermissionGate>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
