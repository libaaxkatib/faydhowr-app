import { useEffect, useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';

import { adminsApi } from '@/api/admins';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import { useI18n } from '@/i18n/useI18n';
import type { Admin } from '@/types/admin';

interface ResetPasswordDialogProps {
  isOpen: boolean;
  onClose: () => void;
  admin: Admin | null;
}

/**
 * Super Admin sets a new password for another admin directly (no email/reset-link
 * flow exists in this project). The value never leaves this form except in the
 * request body itself — it isn't logged, stored, or echoed back by the API.
 */
export function ResetPasswordDialog({ isOpen, onClose, admin }: ResetPasswordDialogProps) {
  const { t } = useI18n();
  const { show } = useToast();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (isOpen) {
      setPassword('');
      setConfirm('');
      setFieldErrors({});
    }
  }, [isOpen]);

  const mutation = useMutation({
    mutationFn: () =>
      adminsApi.resetPassword(admin!.id, {
        password,
        password_confirmation: confirm,
      }),
    onSuccess: () => {
      show(t('notify.system.passwordWasReset', { name: admin?.full_name ?? '' }));
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : t('notify.system.passwordResetFailed'), 'error');
      }
    },
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={admin ? t('option.system.resetPasswordFor', { name: admin.full_name }) : t('option.system.titleResetPassword')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            {t('action.cancel')}
          </Button>
          <Button size="sm" variant="danger" onClick={handleSubmit} isLoading={mutation.isPending}>
            {t('action.system.resetPassword')}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <p className="mb-4 text-xs text-ink-muted">
          {t('warning.system.resetPasswordIntro', { name: admin?.full_name ?? t('option.system.thisAdmin') })}
        </p>
        <FormField label={t('option.system.newPassword')} htmlFor="reset-password" required hint={t('warning.system.newPasswordHint')} error={fieldErrors.password?.[0]}>
          <input
            id="reset-password"
            type="password"
            required
            minLength={8}
            className={inputClasses}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </FormField>
        <FormField label={t('option.system.confirmNewPassword')} htmlFor="reset-password-confirm" required error={fieldErrors.password_confirmation?.[0]}>
          <input
            id="reset-password-confirm"
            type="password"
            required
            minLength={8}
            className={inputClasses}
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
          />
        </FormField>
      </form>
    </Modal>
  );
}
