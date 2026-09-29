import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { reconciliationApi } from '@/api/reconciliation';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
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

export function ResolveDataIssueDialog({ isOpen, onClose, issue }: ResolveDataIssueDialogProps) {
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
      show(`${issue.issue_number} marked ${updated.status_label}.`, 'success');
      onClose();
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) {
        setError(err.fieldErrors?.resolution?.[0] ?? err.message);
        return;
      }
      show(err instanceof Error ? err.message : 'Failed to resolve data issue.', 'error');
    },
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Resolve ${issue.issue_number}`}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button isLoading={mutation.isPending} onClick={() => mutation.mutate()}>
            Confirm
          </Button>
        </>
      }
    >
      <FormField label="Outcome" htmlFor="resolve-status" required>
        <Select id="resolve-status" options={STATUS_OPTIONS} value={status} onChange={(e) => setStatus(e.target.value as typeof status)} />
      </FormField>
      <FormField label="Resolution" htmlFor="resolve-resolution" required error={error} hint="Required — explain what was found and what happened.">
        <textarea
          id="resolve-resolution"
          rows={4}
          className={inputClasses + ' h-auto py-2'}
          value={resolution}
          onChange={(e) => setResolution(e.target.value)}
        />
      </FormField>
      <FormField label="Root Cause" htmlFor="resolve-root-cause" hint="Optional — update if this investigation clarified it.">
        <textarea id="resolve-root-cause" rows={2} className={inputClasses + ' h-auto py-2'} value={rootCause} onChange={(e) => setRootCause(e.target.value)} />
      </FormField>
    </Modal>
  );
}
