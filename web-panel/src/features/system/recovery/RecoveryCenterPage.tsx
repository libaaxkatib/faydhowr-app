import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ApiClientError } from '@/api/client';
import { recoveryApi } from '@/api/recovery';
import { Card } from '@/components/ui/Card';
import { PageHeader } from '@/components/ui/PageHeader';
import { useToast } from '@/components/ui/useToast';
import { blockerTitleKey } from '@/i18n/messages/recovery';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import type { MutatingWorkflowId, RecoveryAssignmentHit, RecoveryParams, RecoveryPreview } from '@/types/recovery';
import { canApply, cleanParams, isReadyToPreview, newIdempotencyKey } from '@/utils/recovery';

import { CorrectionHistory } from './CorrectionHistory';
import { DraftPayrollTab } from './DraftPayrollTab';
import { ExplainTab } from './ExplainTab';
import { AssignmentOverview, AssignmentSearch } from './AssignmentPicker';
import { PreviewPanel } from './PreviewPanel';
import { WorkflowForm } from './WorkflowForm';

type Tab = 'correct' | 'payroll' | 'explain' | 'history';

const TABS: { id: Tab; messageKey: MessageKey }[] = [
  { id: 'correct', messageKey: 'option.recovery.tabCorrect' },
  { id: 'payroll', messageKey: 'option.recovery.tabPayroll' },
  { id: 'explain', messageKey: 'option.recovery.tabExplain' },
  { id: 'history', messageKey: 'option.recovery.tabHistory' },
];

const WORKFLOW_KEYS: Record<MutatingWorkflowId, { title: MessageKey; help: MessageKey }> = {
  reopen_assignment: { title: 'option.recovery.wf.reopen_assignment', help: 'option.recovery.wf.reopen_assignment.help' },
  restore_cancelled_assignment: { title: 'option.recovery.wf.restore_cancelled_assignment', help: 'option.recovery.wf.restore_cancelled_assignment.help' },
  correct_assignment_dates: { title: 'option.recovery.wf.correct_assignment_dates', help: 'option.recovery.wf.correct_assignment_dates.help' },
  correct_absence: { title: 'option.recovery.wf.correct_absence', help: 'option.recovery.wf.correct_absence.help' },
};

/**
 * Super Admin Recovery Center (HR first). One guided path for every correction: find the assignment, choose the correction, preview its verified effect
 * (the server applies it inside a transaction it rolls back and recalculates payroll), then give a reason and confirm. Nothing is saved before the last
 * step, a repeat of the last step is harmless (one idempotency key per preview), and approved / finalized / paid payroll only ever gets guidance.
 */
export function RecoveryCenterPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>('correct');

  return (
    <div>
      <PageHeader title={t('option.recovery.page')} breadcrumb={[{ label: t('option.recovery.system') }, { label: t('option.recovery.page') }]} />

      <Card className="mb-4 p-4">
        <p className="text-sm text-ink-muted">{t('warning.recovery.intro')}</p>
      </Card>

      <div role="tablist" aria-label={t('option.recovery.page')} className="mb-4 flex flex-wrap gap-2">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`recovery-tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`recovery-panel-${item.id}`}
            onClick={() => setTab(item.id)}
            className={`h-9 rounded-sm px-3 text-sm font-semibold transition ${tab === item.id ? 'bg-primary text-white' : 'border border-border bg-surface text-ink hover:bg-surface-alt'}`}
          >
            {t(item.messageKey)}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`recovery-panel-${tab}`} aria-labelledby={`recovery-tab-${tab}`}>
        {tab === 'correct' && <CorrectTab onShowHistory={() => setTab('history')} />}
        {tab === 'payroll' && <DraftPayrollTab />}
        {tab === 'explain' && <ExplainTab />}
        {tab === 'history' && <CorrectionHistory />}
      </div>
    </div>
  );
}

function CorrectTab({ onShowHistory }: { onShowHistory: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [picked, setPicked] = useState<RecoveryAssignmentHit | null>(null);
  const [workflow, setWorkflow] = useState<MutatingWorkflowId | null>(null);
  const [params, setParams] = useState<RecoveryParams>({});
  const [preview, setPreview] = useState<RecoveryPreview | null>(null);
  const [previewed, setPreviewed] = useState<{ workflow: MutatingWorkflowId; params: RecoveryParams } | null>(null);
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const idempotencyKey = useRef<string>('');

  const overview = useQuery({
    queryKey: ['recovery', 'overview', picked?.id],
    queryFn: () => recoveryApi.overview(picked!.id),
    enabled: picked !== null,
  });

  // Any change of what is being corrected makes the earlier preview (and its confirmation) void.
  function invalidatePreview() {
    setPreview(null);
    setPreviewed(null);
    setConfirmed(false);
  }

  function reset() {
    setPicked(null);
    setWorkflow(null);
    setParams({});
    setReason('');
    invalidatePreview();
  }

  useEffect(() => {
    invalidatePreview();
  }, [workflow, params]);

  const available = useMemo(() => new Set((overview.data?.workflows ?? []).filter((w) => w.available).map((w) => w.id)), [overview.data]);

  const previewMutation = useMutation({
    mutationFn: () => recoveryApi.preview(workflow!, picked!.id, cleanParams(workflow!, params)),
    onSuccess: (data) => {
      idempotencyKey.current = newIdempotencyKey();
      setPreview(data);
      setPreviewed({ workflow: workflow!, params: cleanParams(workflow!, params) });
      setConfirmed(false);
    },
    onError: () => show(t('notify.recovery.previewFailed'), 'error'),
  });

  const applyMutation = useMutation({
    mutationFn: () =>
      recoveryApi.apply({
        workflow: previewed!.workflow,
        assignmentId: picked!.id,
        params: previewed!.params,
        reason: reason.trim(),
        token: preview!.token!,
        idempotencyKey: idempotencyKey.current,
      }),
    onSuccess: async (data) => {
      show(data.replayed ? t('notify.recovery.alreadyApplied') : t('notify.recovery.applied'));
      await queryClient.invalidateQueries({ queryKey: ['recovery'] });
      reset();
      onShowHistory();
    },
    onError: (error) => {
      const stale = error instanceof ApiClientError && (error.code === 'PREVIEW_STALE' || error.code === 'PREVIEW_EXPIRED');
      show(stale ? t('notify.recovery.stale') : error instanceof ApiClientError ? error.message : t('notify.recovery.failed'), 'error');
      if (stale) invalidatePreview();
    },
  });

  const ready = workflow !== null && isReadyToPreview(workflow, params);

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <h2 className="mb-3 font-display text-base font-semibold text-ink">{t('option.recovery.step1')}</h2>
        <AssignmentSearch picked={picked} onPick={(hit) => { setPicked(hit); setWorkflow(null); setParams({}); setReason(''); invalidatePreview(); }} onClear={reset} />
      </Card>

      {picked !== null && (
        <Card className="p-4">
          {overview.isLoading && <p className="text-sm text-ink-muted">{t('option.common.loadingRecords')}</p>}
          {overview.data && <AssignmentOverview data={overview.data} />}
        </Card>
      )}

      {picked !== null && overview.data && (
        <Card className="p-4">
          <h2 className="mb-3 font-display text-base font-semibold text-ink">{t('option.recovery.step2')}</h2>
          {available.size === 0 && <p className="text-sm text-ink-muted">{t('warning.recovery.noWorkflow')}</p>}
          <div role="radiogroup" aria-label={t('option.recovery.step2')} className="grid gap-2 md:grid-cols-2">
            {(Object.keys(WORKFLOW_KEYS) as MutatingWorkflowId[]).filter((id) => available.has(id)).map((id) => {
              const blockers = overview.data.workflows.find((w) => w.id === id)?.blockers ?? [];

              return (
                <label key={id} className={`cursor-pointer rounded-sm border p-3 text-sm transition ${workflow === id ? 'border-primary bg-primary/5' : 'border-border hover:bg-surface-alt'}`}>
                  <input type="radio" name="recovery-workflow" className="mr-2" checked={workflow === id} onChange={() => { setWorkflow(id); setParams({}); }} />
                  <span className="font-semibold text-ink">{t(WORKFLOW_KEYS[id].title)}</span>
                  <span className="mt-1 block text-xs text-ink-muted">{t(WORKFLOW_KEYS[id].help)}</span>
                  {blockers.length > 0 && <span className="mt-1 block text-xs text-warning">{blockers.map((b) => { const key = blockerTitleKey(b.code); return key ? t(key) : b.code; }).join(' · ')}</span>}
                </label>
              );
            })}
          </div>

          {workflow !== null && (
            <>
              <WorkflowForm workflow={workflow} overview={overview.data} params={params} onChange={setParams} />
              <button
                type="button"
                disabled={!ready || previewMutation.isPending}
                onClick={() => previewMutation.mutate()}
                className="mt-2 inline-flex h-10 items-center rounded-sm bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-ink disabled:cursor-not-allowed disabled:bg-primary/50"
              >
                {t('action.recovery.preview')}
              </button>
            </>
          )}
        </Card>
      )}

      {preview !== null && previewed !== null && (
        <PreviewPanel
          preview={preview}
          reason={reason}
          onReason={setReason}
          confirmed={confirmed}
          onConfirmed={setConfirmed}
          canApply={canApply(preview, reason, confirmed)}
          isApplying={applyMutation.isPending}
          onApply={() => applyMutation.mutate()}
        />
      )}
    </div>
  );
}
