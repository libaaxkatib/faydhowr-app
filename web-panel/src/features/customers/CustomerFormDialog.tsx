import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { customersApi } from '@/api/customers';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import type { Customer, CreateCustomerPayload, UpdateCustomerPayload } from '@/types/customer';

interface CustomerFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  customer?: Customer;
}

const emptyForm = {
  full_name: '',
  phone: '',
  email: '',
  password: '',
  gender: '' as '' | 'male' | 'female',
  date_of_birth: '',
  preferred_language: '' as '' | 'so' | 'en' | 'ar',
};

export function CustomerFormDialog({ isOpen, onClose, mode, customer }: CustomerFormDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (isOpen) {
      setFieldErrors({});
      setForm(
        customer
          ? {
              full_name: customer.full_name,
              phone: customer.phone ?? '',
              email: customer.email ?? '',
              password: '',
              gender: customer.gender ?? '',
              date_of_birth: customer.date_of_birth ?? '',
              preferred_language: customer.preferred_language ?? '',
            }
          : emptyForm,
      );
    }
  }, [isOpen, customer]);

  const mutation = useMutation({
    mutationFn: async () => {
      if (mode === 'create') {
        const payload: CreateCustomerPayload = {
          full_name: form.full_name,
          phone: form.phone,
          email: form.email || null,
          password: form.password,
          gender: form.gender || null,
          date_of_birth: form.date_of_birth || null,
          preferred_language: form.preferred_language || null,
        };
        return customersApi.create(payload);
      }
      const payload: UpdateCustomerPayload = {
        full_name: form.full_name,
        phone: form.phone,
        email: form.email || null,
        gender: form.gender || null,
        date_of_birth: form.date_of_birth || null,
        preferred_language: form.preferred_language || null,
      };
      return customersApi.update(customer!.id, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      if (customer) queryClient.invalidateQueries({ queryKey: ['customer', customer.id] });
      show(mode === 'create' ? 'Customer created.' : 'Customer updated.');
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
      title={mode === 'create' ? 'Add Customer' : 'Edit Customer'}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>
            {mode === 'create' ? 'Create Customer' : 'Save Changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <FormField label="Full name" htmlFor="full_name" required error={fieldErrors.full_name?.[0]}>
          <input
            id="full_name"
            required
            className={inputClasses}
            value={form.full_name}
            onChange={(event) => setForm({ ...form, full_name: event.target.value })}
          />
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Phone" htmlFor="phone" required error={fieldErrors.phone?.[0]}>
            <input
              id="phone"
              required
              className={inputClasses}
              value={form.phone}
              onChange={(event) => setForm({ ...form, phone: event.target.value })}
            />
          </FormField>
          <FormField label="Email" htmlFor="email" error={fieldErrors.email?.[0]}>
            <input
              id="email"
              type="email"
              className={inputClasses}
              value={form.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
            />
          </FormField>
        </div>

        {mode === 'create' && (
          <FormField label="Temporary password" htmlFor="password" required hint="Minimum 8 characters." error={fieldErrors.password?.[0]}>
            <input
              id="password"
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
          <FormField label="Gender" htmlFor="gender" error={fieldErrors.gender?.[0]}>
            <Select
              id="gender"
              value={form.gender}
              onChange={(event) => setForm({ ...form, gender: event.target.value as typeof form.gender })}
              placeholder="Not specified"
              options={[
                { value: 'male', label: 'Male' },
                { value: 'female', label: 'Female' },
              ]}
            />
          </FormField>
          <FormField label="Date of birth" htmlFor="date_of_birth" error={fieldErrors.date_of_birth?.[0]}>
            <input
              id="date_of_birth"
              type="date"
              className={inputClasses}
              value={form.date_of_birth}
              onChange={(event) => setForm({ ...form, date_of_birth: event.target.value })}
            />
          </FormField>
        </div>

        <FormField label="Preferred language" htmlFor="preferred_language" error={fieldErrors.preferred_language?.[0]}>
          <Select
            id="preferred_language"
            value={form.preferred_language}
            onChange={(event) => setForm({ ...form, preferred_language: event.target.value as typeof form.preferred_language })}
            placeholder="Not specified"
            options={[
              { value: 'so', label: 'Somali' },
              { value: 'en', label: 'English' },
              { value: 'ar', label: 'Arabic' },
            ]}
          />
        </FormField>
      </form>
    </Modal>
  );
}
