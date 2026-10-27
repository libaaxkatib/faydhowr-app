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
import {
  canSubmitStageMove,
  currentStageValue,
  normalizeStageReason,
  stageMoveOptions,
  stageMoveReasonError,
  STAGE_MOVE_MAX_LENGTH,
  STAGE_MOVE_TARGETS,
} from '@/utils/registrationWorkflow';
import type { Employee } from '@/types/employee';

interface Props {
  isOpen: boolean;
  employee: Employee;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Super Admin / HR Manager. Moves ONE existing registered person to the stage they need (Waiting List, Training, Practice ...).
 * Nothing is deleted; the move is written to the status history and the audit log with the reason. The server checks the role,
 * that the person is an existing record, and that the stage is available, again.
 */
export function MoveStageDialog({ isOpen, employee, onClose, onDone }: Props) {
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const [target, setTarget] = useState<string>('');
  const [reason, setReason] = useState('');
  const [touched, setTouched] = useState(false);

  const current = currentStageValue(employee.status, employee.pipeline_stage);
  const currentTarget = STAGE_MOVE_TARGETS.find((target) => target.value === current);
  const currentLabel = currentTarget ? t(currentTarget.labelKey) : (tValue('status', employee.status) ?? employee.status[0].toUpperCase() + employee.status.slice(1));

  const mutation = useMutation({
    mutationFn: () => hrApi.employees.moveStage(employee.id, target, normalizeStageReason(reason)),
    onSuccess: () => {
      show(t('notify.employees.stageMoved'));
      setTarget('');
      setReason('');
      setTouched(false);
      onDone();
      onClose();
    },
    onError: (error) => show(friendlyErrorMessage(error, t('notify.employees.stageMoveFailed')), 'error'),
  });

  const reasonError = touched ? stageMoveReasonError(reason) : null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('action.moveToStage')}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            {t('action.cancel')}
          </Button>
          <Button
            size="sm"
            isLoading={mutation.isPending}
            disabled={!canSubmitStageMove(employee.status, employee.pipeline_stage, target, reason)}
            onClick={() => mutation.mutate()}
          >
            {t('action.moveToStage')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="rounded-sm border border-border bg-surface-alt p-3 text-sm text-ink">
          {t('warning.employees.moveStageIntro', { name: employee.full_name })}
        </p>
        <FormField label={t('option.currentStage')} htmlFor="move-stage-from">
          <input id="move-stage-from" className={inputClasses} value={currentLabel} disabled readOnly />
        </FormField>
        <FormField label={t('option.moveTo')} htmlFor="move-stage-to">
          <Select
            id="move-stage-to"
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            placeholder={t('option.chooseStage')}
            options={stageMoveOptions(employee.status, employee.pipeline_stage).map((option) => ({ value: option.value, label: t(option.labelKey) }))}
          />
        </FormField>
        <FormField
          label={t('option.reasonRequired')}
          htmlFor="move-stage-reason"
          error={reasonError ?? undefined}
          hint={t('validation.employees.reasonCounter', { count: normalizeStageReason(reason).length, max: STAGE_MOVE_MAX_LENGTH })}
        >
          <textarea
            id="move-stage-reason"
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
