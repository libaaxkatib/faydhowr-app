import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatDate } from '@/utils/formatters';

/**
 * docs/HRM_MARKETING_SRS.md HR §10: batches/sessions for Practical, sitting
 * above the per-employee attempt records. Decisions themselves are recorded
 * from the Employee profile (Approved/Rejected/Ku Celis Practical); this
 * screen manages the batch/session and shows who was assessed in it.
 */
export function PracticalBatchesPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [batchDate, setBatchDate] = useState(new Date().toISOString().slice(0, 10));
  const [location, setLocation] = useState('');
  const [teamOrGroup, setTeamOrGroup] = useState('');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['practical-batches'], queryFn: hrApi.practicalBatches.list });

  const createMutation = useMutation({
    mutationFn: () => hrApi.practicalBatches.create({ batch_date: batchDate, location: location || null, team_or_group: teamOrGroup || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['practical-batches'] });
      show('Practical batch created.');
      setIsCreateOpen(false);
      setLocation('');
      setTeamOrGroup('');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Practical Batches"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Practical Batches' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              Create Practical Batch
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label="Loading practical batches…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="check" title="No practical batches yet" />}

      {data && data.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {data.map((batch) => (
            <Card key={batch.id} className="p-5">
              <div className="mb-3 flex items-center justify-between">
                <div>
                  <h3 className="font-display text-base font-bold text-ink">{batch.batch_number}</h3>
                  <p className="text-xs text-ink-muted">
                    {formatDate(batch.batch_date)} {batch.location && `· ${batch.location}`} {batch.team_or_group && `· ${batch.team_or_group}`}
                  </p>
                </div>
                <StatusBadge status={batch.status} tone={batch.status === 'completed' ? 'success' : 'warning'} />
              </div>

              <p className="mb-1.5 text-xs font-medium text-ink-faint">Assessed here</p>
              {batch.assessments.length > 0 ? (
                <div className="space-y-1.5">
                  {batch.assessments.map((a) => (
                    <button
                      key={a.id}
                      type="button"
                      onClick={() => navigate(`/hr/employees/${a.employee_id}`)}
                      className="flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
                    >
                      <span className="text-ink">
                        {a.employee_name} {a.attempt_number && <span className="text-xs text-ink-faint">(#{a.attempt_number})</span>}
                      </span>
                      <StatusBadge status={a.result} tone={a.result === 'approved' ? 'success' : a.result === 'rejected' ? 'danger' : 'warning'} />
                    </button>
                  ))}
                </div>
              ) : (
                <p className="text-xs italic text-ink-faint">
                  No decisions recorded yet — record them from the employee's profile and select this batch.
                </p>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        title="New Practical Batch"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsCreateOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>Create</Button>
          </>
        }
      >
        <FormField label="Batch date" htmlFor="pb-date" required>
          <input id="pb-date" type="date" className={inputClasses} value={batchDate} onChange={(e) => setBatchDate(e.target.value)} />
        </FormField>
        <FormField label="Location" htmlFor="pb-location">
          <input id="pb-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
        </FormField>
        <FormField label="Team / group" htmlFor="pb-team">
          <input id="pb-team" className={inputClasses} value={teamOrGroup} onChange={(e) => setTeamOrGroup(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
