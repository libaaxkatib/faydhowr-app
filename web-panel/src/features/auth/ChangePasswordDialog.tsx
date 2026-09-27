import { useEffect, useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';

import { adminsApi } from '@/api/admins';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';

interface ChangePasswordDialogProps {
  isOpen: boolean;
  onClose: () => void;
}

/**
 * Self-service password change. On success the backend keeps this session's
 * token alive but revokes every other device/session for this admin — the
 * user isn't logged out here, so no further action is needed after saving.
 */
export function ChangePasswordDialog({ isOpen, onClose }: ChangePasswordDialogProps) {
  const { show } = useToast();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (isOpen) {
      setCurrentPassword('');
      setPassword('');
      setConfirm('');
      setFieldErrors({});
    }
  }, [isOpen]);

  const mutation = useMutation({
    mutationFn: () =>
      adminsApi.changeOwnPassword({
        current_password: currentPassword,
        password,
        password_confirmation: confirm,
      }),
    onSuccess: () => {
      show('Password changed. Your other devices have been signed out.');
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.code === 'CURRENT_PASSWORD_INCORRECT') {
        setFieldErrors({ current_password: [error.message] });
      } else if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : 'Could not change password.', 'error');
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
      title="Change password"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>
            Change Password
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <FormField label="Current password" htmlFor="current-password" required error={fieldErrors.current_password?.[0]}>
          <input
            id="current-password"
            type="password"
            required
            className={inputClasses}
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
          />
        </FormField>
        <FormField label="New password" htmlFor="new-password" required hint="Minimum 8 characters." error={fieldErrors.password?.[0]}>
          <input
            id="new-password"
            type="password"
            required
            minLength={8}
            className={inputClasses}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </FormField>
        <FormField
          label="Confirm new password"
          htmlFor="new-password-confirm"
          required
          error={fieldErrors.password_confirmation?.[0]}
        >
          <input
            id="new-password-confirm"
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
