import { useState } from 'react';
import { returnState } from '@/utils/urlFilters';
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
import { useI18n } from '@/i18n/useI18n';

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
  const { t, tValue } = useI18n();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [batchDate, setBatchDate] = useState(new Date().toISOString().slice(0, 10));
  const [location, setLocation] = useState('');
  const [teamOrGroup, setTeamOrGroup] = useState('');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['practical-batches'], queryFn: hrApi.practicalBatches.list });

  const createMutation = useMutation({
    mutationFn: () => hrApi.practicalBatches.create({ batch_date: batchDate, location: location || null, team_or_group: teamOrGroup || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['practical-batches'] });
      show(t('notify.pipeline.practicalBatchCreated'));
      setIsCreateOpen(false);
      setLocation('');
      setTeamOrGroup('');
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.pipeline.somethingWrong'), 'error'),
  });

  return (
    <div>
      <PageHeader
        title={t('nav.practicalBatches')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.practicalBatches') }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.createPracticalBatch')}
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label={t('option.pipeline.loadingPracticalBatches')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="check" title={t('option.pipeline.noPracticalBatchesYet')} />}

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
                <StatusBadge status={batch.status} label={tValue('status.pipeline.batch', batch.status)} tone={batch.status === 'completed' ? 'success' : 'warning'} />
              </div>

              <p className="mb-1.5 text-xs font-medium text-ink-faint">{t('option.pipeline.assessedHere')}</p>
              {batch.assessments.length > 0 ? (
                <div className="space-y-1.5">
                  {batch.assessments.map((a) => (
                    <button
                      key={a.id}
                      type="button"
                      onClick={() => navigate(`/hr/employees/${a.employee_id}`, { state: returnState(window.location.pathname, window.location.search, 'Practical Batches') })}
                      className="flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
                    >
                      <span className="text-ink">
                        {a.employee_name} {a.attempt_number && <span className="text-xs text-ink-faint">(#{a.attempt_number})</span>}
                      </span>
                      <StatusBadge
                        status={a.result}
                        label={tValue('status.pipeline.assessment', a.result)}
                        tone={a.result === 'approved' ? 'success' : a.result === 'rejected' ? 'danger' : 'warning'}
                      />
                    </button>
                  ))}
                </div>
              ) : (
                <p className="text-xs italic text-ink-faint">
                  {t('option.pipeline.noDecisionsYet')}
                </p>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        title={t('confirm.pipeline.newPracticalBatch')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsCreateOpen(false)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createMutation.isPending} onClick={() => createMutation.mutate()}>{t('action.create')}</Button>
          </>
        }
      >
        <FormField label={t('option.pipeline.batchDate')} htmlFor="pb-date" required>
          <input id="pb-date" type="date" className={inputClasses} value={batchDate} onChange={(e) => setBatchDate(e.target.value)} />
        </FormField>
        <FormField label={t('option.pipeline.location')} htmlFor="pb-location">
          <input id="pb-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
        </FormField>
        <FormField label={t('option.pipeline.teamOrGroup')} htmlFor="pb-team">
          <input id="pb-team" className={inputClasses} value={teamOrGroup} onChange={(e) => setTeamOrGroup(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
