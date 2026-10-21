import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { duplicateMatchesFromError, errorCodeOf, friendlyErrorMessage } from '@/utils/hrHelpers';
import { restoreFailureKind, restoreIsReady, restoreLanding, restorePayload } from '@/utils/employeeRestore';
import type { DuplicateEmployeeMatch, Employee } from '@/types/employee';

interface Props {
  isOpen: boolean;
  employee: Employee;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Brings an archived employee back into the working lists. It changes only the archive mark: the person returns with the status and stage they
 * already have, and their assignments, documents and history are not touched. If a current employee already uses the same phone number the
 * server refuses until it is confirmed with a written reason (the same rule as registering or editing). The server enforces the permission.
 */
export function RestoreEmployeeDialog({ isOpen, employee, onClose, onDone }: Props) {
  const { show } = useToast();
  const [note, setNote] = useState('');
  const [matches, setMatches] = useState<DuplicateEmployeeMatch[]>([]);
  const [confirmed, setConfirmed] = useState(false);
  const [duplicateReason, setDuplicateReason] = useState('');

  useEffect(() => {
    if (isOpen) {
      setNote('');
      setMatches([]);
      setConfirmed(false);
      setDuplicateReason('');
    }
  }, [isOpen]);

  const landing = restoreLanding({ status: employee.status, pipeline_stage: employee.pipeline_stage ?? null });
  const needsConfirmation = matches.length > 0;

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.restore(employee.id, restorePayload(note, needsConfirmation ? { confirmed, reason: duplicateReason } : null)),
    onSuccess: () => {
      show(`${employee.full_name} was restored.`);
      onDone();
      onClose();
    },
    onError: (error) => {
      const kind = restoreFailureKind(errorCodeOf(error));
      if (kind === 'duplicate_phone') {
        setMatches((duplicateMatchesFromError(error) ?? []) as DuplicateEmployeeMatch[]);
        return;
      }
      if (kind === 'not_archived') {
        show('This employee is already restored.', 'error');
        onDone();
        onClose();
        return;
      }
      show(friendlyErrorMessage(error, 'Could not restore this employee.'), 'error');
    },
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Restore employee"
      size="sm"
      footer={
        <>
          <ModalCancelButton disabled={mutation.isPending} />
          <Button size="sm" isLoading={mutation.isPending} disabled={!restoreIsReady(needsConfirmation, confirmed, duplicateReason)} onClick={() => mutation.mutate()}>
            Restore
          </Button>
        </>
      }
    >
      <p className="text-sm text-ink">
        Restore <strong>{employee.full_name}</strong> ({employee.employee_number}) to the working lists?
      </p>
      <p className="mt-2 text-sm text-ink-muted">
        They keep their current status (<strong>{employee.status}</strong>) and will appear in <strong>{landing.label}</strong>. Their assignments, documents and
        history are not changed, and nobody else is affected. The restore is recorded in the audit log.
      </p>

      <div className="mt-4">
        <FormField label="Note (optional)" htmlFor="restore-note" hint="Kept in the audit log.">
          <textarea id="restore-note" rows={2} maxLength={500} className={inputClasses + ' h-auto py-2'} value={note} onChange={(e) => setNote(e.target.value)} />
        </FormField>
      </div>

      {needsConfirmation && (
        <div className="mt-2 rounded-sm border border-danger/40 bg-danger-soft px-3 py-3 text-sm" role="alert">
          <p className="font-semibold text-danger">A current employee already uses this phone number.</p>
          <ul className="mt-1.5 space-y-1">
            {matches.map((match) => (
              <li key={match.id} className="text-ink">
                {match.full_name} ({match.employee_number}, {match.status}){' '}
                <Link to={`/hr/employees/${match.id}`} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">
                  Open profile
                </Link>
              </li>
            ))}
          </ul>
          <label className="mt-3 flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
            This is a different person
          </label>
          {confirmed && (
            <div className="mt-3">
              <FormField label="Why is this a different person?" htmlFor="restore-duplicate-reason" required hint="At least 10 characters. Kept in the audit log.">
                <textarea
                  id="restore-duplicate-reason"
                  rows={2}
                  maxLength={500}
                  className={inputClasses + ' h-auto py-2'}
                  value={duplicateReason}
                  onChange={(e) => setDuplicateReason(e.target.value)}
                />
              </FormField>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
