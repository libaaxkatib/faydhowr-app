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
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SearchInput } from '@/components/ui/SearchInput';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { completionSummary, needTrainingTruncated, participantOptionLabel } from '@/utils/trainingBatch';
import type { TrainingBatch } from '@/types/employee';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { useI18n } from '@/i18n/useI18n';

/**
 * docs/HRM_MARKETING_SRS.md HR §9: HR creates batches and selects eligible
 * (Need Training) people into them — automatic eligibility never means
 * automatic batch assignment.
 */
export function TrainingBatchesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [batchDate, setBatchDate] = useState(localToday());
  const [location, setLocation] = useState('');
  const [teamOrGroup, setTeamOrGroup] = useState('');
  const [pendingEmployeeByBatch, setPendingEmployeeByBatch] = useState<Record<number, string>>({});
  const [absenteesByBatch, setAbsenteesByBatch] = useState<Record<number, Set<number>>>({});

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['training-batches'], queryFn: hrApi.trainingBatches.list });
  // Everybody in Need Training can be found: the list is a page of 100, and typing a name, number or phone searches all of them.
  const [needSearch, setNeedSearch] = useState('');
  const debouncedNeedSearch = useDebouncedValue(needSearch).trim();
  const [completing, setCompleting] = useState<TrainingBatch | null>(null);
  const { data: eligible } = useQuery({
    queryKey: ['employees', { pipeline_stage: 'need_training', search: debouncedNeedSearch }],
    queryFn: () => hrApi.employees.list({ pipeline_stage: 'need_training', search: debouncedNeedSearch || undefined, per_page: 100 }),
    placeholderData: (previous) => previous,
  });

  const createMutation = useMutation({
    mutationFn: () => hrApi.trainingBatches.create({ batch_date: batchDate, location: location || null, team_or_group: teamOrGroup || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      show(t('notify.pipeline.trainingBatchCreated'));
      setIsCreateOpen(false);
      setLocation('');
      setTeamOrGroup('');
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.somethingWrong')), 'error'),
  });

  const addParticipantMutation = useMutation({
    mutationFn: ({ batchId, employeeId }: { batchId: number; employeeId: number }) => hrApi.trainingBatches.addParticipant(batchId, employeeId),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      setPendingEmployeeByBatch((prev) => ({ ...prev, [variables.batchId]: '' }));
      show(t('notify.pipeline.participantAdded'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotAddParticipant')), 'error'),
  });

  const removeParticipantMutation = useMutation({
    mutationFn: ({ batchId, participantId }: { batchId: number; participantId: number }) => hrApi.trainingBatches.removeParticipant(batchId, participantId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show(t('notify.pipeline.participantRemoved'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotRemoveParticipant')), 'error'),
  });

  const completeMutation = useMutation({
    mutationFn: ({ batchId, absentIds }: { batchId: number; absentIds: number[] }) => hrApi.trainingBatches.complete(batchId, absentIds),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-batches'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      show(t('notify.pipeline.batchCompleted'));
      setCompleting(null);
    },
    onError: (err) => {
      setCompleting(null);
      show(friendlyErrorMessage(err, t('notify.pipeline.couldNotCompleteBatch')), 'error');
    },
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
        title={t('nav.trainingBatches')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.trainingBatches') }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.createTrainingBatch')}
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label={t('option.pipeline.loadingTrainingBatches')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="calendar" title={t('option.pipeline.noTrainingBatchesYet')} />}

      {data && data.some((batch) => batch.status === 'scheduled') && (
        <PermissionGate module="hr">
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <SearchInput value={needSearch} onChange={setNeedSearch} placeholder={t('option.uiAuditB.searchNeedTraining')} className="w-full max-w-sm" />
            {eligible && debouncedNeedSearch !== '' && eligible.data.length === 0 && <span className="text-xs text-ink-muted">{t('option.uiAuditB.needTrainingNoMatch')}</span>}
            {eligible && needTrainingTruncated(eligible.data.length, eligible.meta.total) && (
              <span className="text-xs text-ink-muted" data-testid="need-training-truncated">
                {t('option.uiAuditB.needTrainingShown', { shown: eligible.data.length, total: eligible.meta.total })}
              </span>
            )}
          </div>
        </PermissionGate>
      )}

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
                <StatusBadge
                  status={batch.status}
                  label={tValue('status.pipeline.batch', batch.status)}
                  tone={batch.status === 'completed' ? 'success' : batch.status === 'cancelled' ? 'danger' : 'warning'}
                />
              </div>

              <p className="mb-1.5 text-xs font-medium text-ink-faint">{t('option.pipeline.participants')}</p>
              {batch.participants.length > 0 ? (
                <div className="mb-3 space-y-1.5">
                  {batch.participants.map((p) => (
                    <div key={p.id} className="flex items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-sm">
                      <span className="text-ink">{p.employee_name}</span>
                      <div className="flex items-center gap-2">
                        {p.result !== 'pending' ? (
                          <StatusBadge status={p.result} label={tValue('status.pipeline.participant', p.result)} tone={p.result === 'completed' ? 'success' : 'neutral'} />
                        ) : (
                          batch.status === 'scheduled' && (
                            <PermissionGate module="hr">
                              <label className="flex items-center gap-1 text-xs text-ink-muted">
                                <input type="checkbox" checked={absenteesByBatch[batch.id]?.has(p.employee_id) ?? false} onChange={() => toggleAbsent(batch.id, p.employee_id)} />
                                {t('action.absent')}
                              </label>
                            </PermissionGate>
                          )
                        )}
                        {batch.status === 'scheduled' && (
                          <PermissionGate module="hr">
                            <button type="button" aria-label={t('action.training.removeParticipantNamed', { name: p.employee_name ?? '' })} title={t('action.training.removeParticipantNamed', { name: p.employee_name ?? '' })} onClick={() => removeParticipantMutation.mutate({ batchId: batch.id, participantId: p.id })} className="text-ink-faint hover:text-danger">
                              <Icon name="x" size={13} />
                            </button>
                          </PermissionGate>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mb-3 text-xs italic text-ink-faint">{t('option.pipeline.noParticipantsYet')}</p>
              )}

              {batch.status === 'scheduled' && (
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1.5 border-t border-border pt-3">
                    <Select
                      value={pendingEmployeeByBatch[batch.id] ?? ''}
                      onChange={(e) => setPendingEmployeeByBatch((prev) => ({ ...prev, [batch.id]: e.target.value }))}
                      placeholder={t('option.addEligibleEmployee')}
                      options={(eligible?.data ?? [])
                        .filter((e) => !batch.participants.some((p) => p.employee_id === e.id))
                        .map((e) => ({ value: String(e.id), label: participantOptionLabel(e) }))}
                      className="h-8 flex-1 text-xs"
                    />
                    <Button
                      size="sm"
                      className="h-8 px-2 text-xs"
                      disabled={!pendingEmployeeByBatch[batch.id]}
                      isLoading={addParticipantMutation.isPending}
                      onClick={() => addParticipantMutation.mutate({ batchId: batch.id, employeeId: Number(pendingEmployeeByBatch[batch.id]) })}
                    >
                      {t('action.addParticipant')}
                    </Button>
                  </div>
                  {batch.participants.length > 0 && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="mt-2 w-full"
                      isLoading={completeMutation.isPending && completing?.id === batch.id}
                      onClick={() => setCompleting(batch)}
                    >
                      {t('action.markBatchCompleted')}
                    </Button>
                  )}
                </PermissionGate>
              )}
            </Card>
          ))}
        </div>
      )}

      {(() => {
        const summary = completing ? completionSummary(completing.participants, absenteesByBatch[completing.id]) : { go: 0, absent: 0 };

        return (
          <ConfirmDialog
            isOpen={completing !== null}
            title={t('confirm.uiAuditB.completeBatchTitle', { batch: completing?.batch_number ?? '' })}
            description={t('confirm.uiAuditB.completeBatchBody', { go: summary.go, absent: summary.absent })}
            confirmLabel={t('action.markBatchCompleted')}
            isLoading={completeMutation.isPending}
            onConfirm={() => completing && completeMutation.mutate({ batchId: completing.id, absentIds: Array.from(absenteesByBatch[completing.id] ?? []) })}
            onCancel={() => setCompleting(null)}
          />
        );
      })()}

      <Modal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        title={t('confirm.pipeline.newTrainingBatch')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsCreateOpen(false)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>{t('action.create')}</Button>
          </>
        }
      >
        <FormField label={t('option.pipeline.batchDate')} htmlFor="batch-date" required>
          <input id="batch-date" type="date" className={inputClasses} value={batchDate} onChange={(e) => setBatchDate(e.target.value)} />
        </FormField>
        <FormField label={t('option.pipeline.location')} htmlFor="batch-location">
          <input id="batch-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
        </FormField>
        <FormField label={t('option.pipeline.teamOrGroup')} htmlFor="batch-team">
          <input id="batch-team" className={inputClasses} value={teamOrGroup} onChange={(e) => setTeamOrGroup(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
