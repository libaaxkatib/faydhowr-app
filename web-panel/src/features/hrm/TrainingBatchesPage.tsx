import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatDate } from '@/utils/formatters';

/**
 * docs/HRM_MARKETING_SRS.md HR §9: HR creates batches and selects eligible
 * (Need Training) people into them — automatic eligibility never means
 * automatic batch assignment.
 */
export function TrainingBatchesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [batchDate, setBatchDate] = useState(new Date().toISOString().slice(0, 10));
  const [location, setLocation] = useState('');
  const [teamOrGroup, setTeamOrGroup] = useState('');
  const [pendingEmployeeByBatch, setPendingEmployeeByBatch] = useState<Record<number, string>>({});
  const [absenteesByBatch, setAbsenteesByBatch] = useState<Record<number, Set<number>>>({});

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['training-batches'], queryFn: hrApi.trainingBatches.list });
  const { data: eligible } = useQuery({
    queryKey: ['employees', { pipeline_stage: 'need_training' }],
    queryFn: () => hrApi.employees.list({ pipeline_stage: 'need_training', per_page: 100 }),
  });

  const createMutation = useMutation({
    mutationFn: () => hrApi.trainingBatches.create({ batch_date: batchDate, location: location || null, team_or_group: teamOrGroup || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      show('Training batch created.');
      setIsCreateOpen(false);
      setLocation('');
      setTeamOrGroup('');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const addParticipantMutation = useMutation({
    mutationFn: ({ batchId, employeeId }: { batchId: number; employeeId: number }) => hrApi.trainingBatches.addParticipant(batchId, employeeId),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      setPendingEmployeeByBatch((prev) => ({ ...prev, [variables.batchId]: '' }));
      show('Participant added.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not add participant.', 'error'),
  });

  const removeParticipantMutation = useMutation({
    mutationFn: ({ batchId, participantId }: { batchId: number; participantId: number }) => hrApi.trainingBatches.removeParticipant(batchId, participantId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show('Participant removed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not remove participant.', 'error'),
  });

  const completeMutation = useMutation({
    mutationFn: ({ batchId, absentIds }: { batchId: number; absentIds: number[] }) => hrApi.trainingBatches.complete(batchId, absentIds),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show('Batch marked completed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not complete batch.', 'error'),
  });

  function toggleAbsent(batchId: number, employeeId: number) {
    setAbsenteesByBatch((prev) => {
      const next = new Set(prev[batchId] ?? []);
      if (next.has(employeeId)) next.delete(employeeId);
      else next.add(employeeId);
      return { ...prev, [batchId]: next };
    });
  }

  return (
    <div>
      <PageHeader
        title="Training Batches"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Training Batches' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              Create Training Batch
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label="Loading training batches…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="calendar" title="No training batches yet" />}

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
                <StatusBadge status={batch.status} tone={batch.status === 'completed' ? 'success' : batch.status === 'cancelled' ? 'danger' : 'warning'} />
              </div>

              <p className="mb-1.5 text-xs font-medium text-ink-faint">Participants</p>
              {batch.participants.length > 0 ? (
                <div className="mb-3 space-y-1.5">
                  {batch.participants.map((p) => (
                    <div key={p.id} className="flex items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-sm">
                      <span className="text-ink">{p.employee_name}</span>
                      <div className="flex items-center gap-2">
                        {p.result !== 'pending' ? (
                          <StatusBadge status={p.result} tone={p.result === 'completed' ? 'success' : 'neutral'} />
                        ) : (
                          batch.status === 'scheduled' && (
                            <PermissionGate module="hr">
                              <label className="flex items-center gap-1 text-xs text-ink-muted">
                                <input type="checkbox" checked={absenteesByBatch[batch.id]?.has(p.employee_id) ?? false} onChange={() => toggleAbsent(batch.id, p.employee_id)} />
                                Absent
                              </label>
                            </PermissionGate>
                          )
                        )}
                        {batch.status === 'scheduled' && (
                          <PermissionGate module="hr">
                            <button type="button" onClick={() => removeParticipantMutation.mutate({ batchId: batch.id, participantId: p.id })} className="text-ink-faint hover:text-danger">
                              <Icon name="x" size={13} />
                            </button>
                          </PermissionGate>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mb-3 text-xs italic text-ink-faint">No participants yet.</p>
              )}

              {batch.status === 'scheduled' && (
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1.5 border-t border-border pt-3">
                    <Select
                      value={pendingEmployeeByBatch[batch.id] ?? ''}
                      onChange={(e) => setPendingEmployeeByBatch((prev) => ({ ...prev, [batch.id]: e.target.value }))}
                      placeholder="Add eligible employee…"
                      options={(eligible?.data ?? [])
                        .filter((e) => !batch.participants.some((p) => p.employee_id === e.id))
                        .map((e) => ({ value: String(e.id), label: e.full_name }))}
                      className="h-8 flex-1 text-xs"
                    />
                    <Button
                      size="sm"
                      className="h-8 px-2 text-xs"
                      disabled={!pendingEmployeeByBatch[batch.id]}
                      isLoading={addParticipantMutation.isPending}
                      onClick={() => addParticipantMutation.mutate({ batchId: batch.id, employeeId: Number(pendingEmployeeByBatch[batch.id]) })}
                    >
                      Add
                    </Button>
                  </div>
                  {batch.participants.length > 0 && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="mt-2 w-full"
                      isLoading={completeMutation.isPending}
                      onClick={() => completeMutation.mutate({ batchId: batch.id, absentIds: Array.from(absenteesByBatch[batch.id] ?? []) })}
                    >
                      Mark Batch Completed
                    </Button>
                  )}
                </PermissionGate>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        title="New Training Batch"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsCreateOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>Create</Button>
          </>
        }
      >
        <FormField label="Batch date" htmlFor="batch-date" required>
          <input id="batch-date" type="date" className={inputClasses} value={batchDate} onChange={(e) => setBatchDate(e.target.value)} />
        </FormField>
        <FormField label="Location" htmlFor="batch-location">
          <input id="batch-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
        </FormField>
        <FormField label="Team / group" htmlFor="batch-team">
          <input id="batch-team" className={inputClasses} value={teamOrGroup} onChange={(e) => setTeamOrGroup(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
