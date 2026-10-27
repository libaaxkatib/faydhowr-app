import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { reconciliationApi } from '@/api/reconciliation';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import { useI18n } from '@/i18n/useI18n';
import type { DataIssue, ResolveDataIssuePayload } from '@/types/reconciliation';

interface ResolveDataIssueDialogProps {
  isOpen: boolean;
  onClose: () => void;
  issue: DataIssue;
}

const STATUS_OPTIONS = [
  { value: 'resolved', label: 'Resolved — understood and fixed' },
  { value: 'accepted_difference', label: 'Accepted Difference — known, intentionally left as-is' },
  { value: 'cannot_resolve', label: 'Cannot Resolve — investigated, evidence insufficient' },
];

/** The same options with the Somali label when Somali is chosen. */
function outcomeOptions(tValue: (prefix: string, value: string) => string | undefined) {
  return STATUS_OPTIONS.map((option) => ({ value: option.value, label: tValue('option.system.resolveOutcome', option.value) ?? option.label }));
}

export function ResolveDataIssueDialog({ isOpen, onClose, issue }: ResolveDataIssueDialogProps) {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [status, setStatus] = useState<'resolved' | 'accepted_difference' | 'cannot_resolve'>('resolved');
  const [resolution, setResolution] = useState('');
  const [rootCause, setRootCause] = useState(issue.root_cause ?? '');
  const [error, setError] = useState<string | undefined>();

  useEffect(() => {
    if (isOpen) {
      setStatus('resolved');
      setResolution('');
      setRootCause(issue.root_cause ?? '');
      setError(undefined);
    }
  }, [isOpen, issue.root_cause]);

  const mutation = useMutation({
    mutationFn: () => {
      const payload: ResolveDataIssuePayload = { status, resolution, root_cause: rootCause || null };
      return reconciliationApi.dataIssues.resolve(issue.id, payload);
    },
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['data-issue', issue.id] });
      queryClient.invalidateQueries({ queryKey: ['data-issues'] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-summary'] });
      show(
        t('notify.system.issueMarked', { number: issue.issue_number, status: tValue('option.system.issueStatus', updated.status) ?? updated.status_label }),
        'success',
      );
      onClose();
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) {
        setError(err.fieldErrors?.resolution?.[0] ?? err.message);
        return;
      }
      show(err instanceof Error ? err.message : t('notify.system.issueResolveFailed'), 'error');
    },
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('confirm.system.resolveTitle', { number: issue.issue_number })}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            {t('action.cancel')}
          </Button>
          <Button isLoading={mutation.isPending} onClick={() => mutation.mutate()}>
            {t('action.confirm')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.system.outcome')} htmlFor="resolve-status" required>
        <Select id="resolve-status" options={outcomeOptions(tValue)} value={status} onChange={(e) => setStatus(e.target.value as typeof status)} />
      </FormField>
      <FormField label={t('option.system.resolution')} htmlFor="resolve-resolution" required error={error} hint={t('warning.system.resolutionHint')}>
        <textarea
          id="resolve-resolution"
          rows={4}
          className={inputClasses + ' h-auto py-2'}
          value={resolution}
          onChange={(e) => setResolution(e.target.value)}
        />
      </FormField>
      <FormField label={t('option.system.rootCause')} htmlFor="resolve-root-cause" hint={t('warning.system.rootCauseHint')}>
        <textarea id="resolve-root-cause" rows={2} className={inputClasses + ' h-auto py-2'} value={rootCause} onChange={(e) => setRootCause(e.target.value)} />
      </FormField>
    </Modal>
  );
}
