import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { canSubmitOverride, normalizeReason, overrideReasonError, overrideTargets, OVERRIDE_MAX_LENGTH } from '@/utils/statusOverride';
import type { Employee, EmployeeStatus } from '@/types/employee';

interface Props {
  isOpen: boolean;
  employee: Employee;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Super Admin only. Changes an employee to a status that is NOT a normal lifecycle step. The server checks the
 * role again, refuses everyone else, and records who did it, when, from/to status and this reason.
 */
export function StatusOverrideDialog({ isOpen, employee, onClose, onDone }: Props) {
  const { show } = useToast();
  const [target, setTarget] = useState<string>('');
  const [reason, setReason] = useState('');
  const [touched, setTouched] = useState(false);

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.updateStatus(employee.id, target as EmployeeStatus, undefined, normalizeReason(reason)),
    onSuccess: () => {
      show('Status changed by Super Admin override. The reason was recorded.');
      setTarget('');
      setReason('');
      setTouched(false);
      onDone();
      onClose();
    },
    onError: (error) => show(friendlyErrorMessage(error, 'Could not change the status.'), 'error'),
  });

  const reasonError = touched ? overrideReasonError(reason) : null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Override employee status (Super Admin)"
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            size="sm"
            variant="danger"
            isLoading={mutation.isPending}
            disabled={!canSubmitOverride(employee.status, target, reason)}
            onClick={() => mutation.mutate()}
          >
            Override status
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="rounded-sm border border-border bg-warning-soft p-3 text-sm text-ink">
          Normally an employee can only move one step along the lifecycle. This skips those rules. Your name, the time, the old and
          new status and your reason are recorded in the status history and the audit log.
        </p>
        <FormField label="Change from" htmlFor="override-from">
          <input id="override-from" className={inputClasses} value={employee.status} disabled readOnly />
        </FormField>
        <FormField label="Change to" htmlFor="override-to">
          <Select
            id="override-to"
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            placeholder="Choose a status"
            options={overrideTargets(employee.status).map((status) => ({ value: status, label: status[0].toUpperCase() + status.slice(1) }))}
          />
        </FormField>
        <FormField label="Reason (required)" htmlFor="override-reason" error={reasonError ?? undefined} hint={`${normalizeReason(reason).length}/${OVERRIDE_MAX_LENGTH}, at least 10 characters.`}>
          <textarea
            id="override-reason"
            rows={3}
            className={inputClasses}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            onBlur={() => setTouched(true)}
          />
        </FormField>
      </div>
    </Modal>
  );
}
