import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
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
  const { t, tValue } = useI18n();
  const [target, setTarget] = useState<string>('');
  const [reason, setReason] = useState('');
  const [touched, setTouched] = useState(false);

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.updateStatus(employee.id, target as EmployeeStatus, undefined, normalizeReason(reason)),
    onSuccess: () => {
      show(t('notify.employees.statusOverridden'));
      setTarget('');
      setReason('');
      setTouched(false);
      onDone();
      onClose();
    },
    onError: (error) => show(friendlyErrorMessage(error, t('notify.employees.statusChangeFailed')), 'error'),
  });

  const reasonError = touched ? overrideReasonError(reason) : null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('confirm.employees.overrideTitle')}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            {t('action.cancel')}
          </Button>
          <Button
            size="sm"
            variant="danger"
            isLoading={mutation.isPending}
            disabled={!canSubmitOverride(employee.status, target, reason)}
            onClick={() => mutation.mutate()}
          >
            {t('action.overrideStatus')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="rounded-sm border border-border bg-warning-soft p-3 text-sm text-ink">
          {t('warning.employees.overrideIntro')}
        </p>
        <FormField label={t('option.changeFrom')} htmlFor="override-from">
          <input id="override-from" className={inputClasses} value={tValue('status', employee.status) ?? employee.status} disabled readOnly />
        </FormField>
        <FormField label={t('option.changeTo')} htmlFor="override-to">
          <Select
            id="override-to"
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            placeholder={t('option.chooseStatus')}
            options={overrideTargets(employee.status).map((status) => ({ value: status, label: tValue('status', status) ?? status[0].toUpperCase() + status.slice(1) }))}
          />
        </FormField>
        <FormField
          label={t('option.reasonRequired')}
          htmlFor="override-reason"
          error={reasonError ?? undefined}
          hint={t('validation.employees.reasonCounter', { count: normalizeReason(reason).length, max: OVERRIDE_MAX_LENGTH })}
        >
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
