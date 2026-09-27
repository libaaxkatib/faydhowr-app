import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { adminsApi } from '@/api/admins';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import { ASSIGNABLE_ADMIN_ROLES } from '@/types/system';
import type { Admin, AdminRole, AdminStatus } from '@/types/admin';
import type { CreateAdminPayload, UpdateAdminPayload } from '@/types/system';

interface AdminFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  admin?: Admin;
}

const emptyForm = {
  full_name: '',
  email: '',
  phone: '',
  password: '',
  role: '' as AdminRole | '',
  status: 'active' as AdminStatus,
};

export function AdminFormDialog({ isOpen, onClose, mode, admin }: AdminFormDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (isOpen) {
      setFieldErrors({});
      setForm(
        admin
          ? {
              full_name: admin.full_name,
              email: admin.email,
              phone: admin.phone ?? '',
              password: '',
              role: admin.role,
              status: admin.status,
            }
          : emptyForm,
      );
    }
  }, [isOpen, admin]);

  const mutation = useMutation({
    mutationFn: async () => {
      if (mode === 'create') {
        const payload: CreateAdminPayload = {
          full_name: form.full_name,
          email: form.email,
          phone: form.phone,
          password: form.password,
          role: form.role as AdminRole,
          status: form.status,
        };
        return adminsApi.create(payload);
      }
      const payload: UpdateAdminPayload = {
        full_name: form.full_name,
        email: form.email,
        phone: form.phone,
        role: form.role as AdminRole,
        status: form.status,
      };
      return adminsApi.update(admin!.id, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admins'] });
      show(mode === 'create' ? 'Admin created.' : 'Admin updated.');
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : 'Something went wrong.', 'error');
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
      title={mode === 'create' ? 'Add Admin' : 'Edit Admin'}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>
            {mode === 'create' ? 'Create Admin' : 'Save Changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <FormField label="Full name" htmlFor="admin-full-name" required error={fieldErrors.full_name?.[0]}>
          <input
            id="admin-full-name"
            required
            className={inputClasses}
            value={form.full_name}
            onChange={(event) => setForm({ ...form, full_name: event.target.value })}
          />
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Email" htmlFor="admin-email" required error={fieldErrors.email?.[0]}>
            <input
              id="admin-email"
              type="email"
              required
              className={inputClasses}
              value={form.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
            />
          </FormField>
          <FormField label="Phone" htmlFor="admin-phone" required error={fieldErrors.phone?.[0]}>
            <input
              id="admin-phone"
              required
              className={inputClasses}
              value={form.phone}
              onChange={(event) => setForm({ ...form, phone: event.target.value })}
            />
          </FormField>
        </div>

        {mode === 'create' && (
          <FormField label="Temporary password" htmlFor="admin-password" required hint="Minimum 8 characters. The admin should change this after first login." error={fieldErrors.password?.[0]}>
            <input
              id="admin-password"
              type="password"
              required
              minLength={8}
              className={inputClasses}
              value={form.password}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
            />
          </FormField>
        )}

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Role" htmlFor="admin-role" required error={fieldErrors.role?.[0]}>
            <Select
              id="admin-role"
              required
              value={form.role}
              onChange={(event) => setForm({ ...form, role: event.target.value as AdminRole })}
              placeholder="Select a role"
              options={ASSIGNABLE_ADMIN_ROLES}
            />
          </FormField>
          <FormField label="Status" htmlFor="admin-status" required error={fieldErrors.status?.[0]}>
            <Select
              id="admin-status"
              required
              value={form.status}
              onChange={(event) => setForm({ ...form, status: event.target.value as AdminStatus })}
              options={[
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
              ]}
            />
          </FormField>
        </div>
      </form>
    </Modal>
  );
}
