import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { tidyName, validateNewName } from '@/utils/quickCreate';
import type { EmployeeCategory, Position } from '@/types/employee';

function nameError(err: unknown, fallback: string): string {
  return err instanceof ApiClientError ? (err.fieldErrors?.name?.[0] ?? friendlyErrorMessage(err, fallback)) : friendlyErrorMessage(err, fallback);
}

function ErrorBox({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">
      {message}
    </div>
  );
}

/**
 * "+ Create Position" shortcut shown beside a Position selector. It creates the position through the normal Positions endpoint (the server
 * refuses a duplicate name), refreshes the position list everywhere and hands the new position back so the caller can select it.
 */
export function CreatePositionDialog({ isOpen, onClose, onCreated }: { isOpen: boolean; onClose: () => void; onCreated: (position: Position) => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [name, setName] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list, enabled: isOpen });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list, enabled: isOpen });

  useEffect(() => {
    if (isOpen) {
      setName('');
      setDepartmentId('');
      setError(null);
    }
  }, [isOpen]);

  const create = useMutation({
    mutationFn: () => hrApi.positions.create({ name: tidyName(name), department_id: departmentId ? Number(departmentId) : null }),
    onSuccess: async (position) => {
      await queryClient.invalidateQueries({ queryKey: ['positions'] });
      show(`Position "${position.name}" created.`);
      onCreated(position);
      onClose();
    },
    onError: (err) => setError(nameError(err, 'Could not create the position.')),
  });

  const submit = () => {
    const problem = validateNewName(name, positions ?? [], 'position');
    setError(problem);
    if (problem === null) create.mutate();
  };

  return (
    <Modal
      confirmDiscard
      isOpen={isOpen}
      onClose={onClose}
      title="Create position"
      size="sm"
      footer={
        <>
          <ModalCancelButton disabled={create.isPending} />
          <Button size="sm" isLoading={create.isPending} onClick={submit}>Create position</Button>
        </>
      }
    >
      <ErrorBox message={error} />
      <FormField label="Position name" htmlFor="quick-position-name" required>
        <input
          id="quick-position-name"
          className={inputClasses}
          value={name}
          onChange={(event) => setName(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              submit();
            }
          }}
          autoFocus
        />
      </FormField>
      <FormField label="Department (optional)" htmlFor="quick-position-department">
        <Select id="quick-position-department" value={departmentId} onChange={(event) => setDepartmentId(event.target.value)} placeholder="No department" options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))} />
      </FormField>
    </Modal>
  );
}

/** "+ Create Category" shortcut beside a Category selector. Same behaviour: validate, no duplicates, refresh, hand back the new category. */
export function CreateCategoryDialog({ isOpen, onClose, onCreated }: { isOpen: boolean; onClose: () => void; onCreated: (category: EmployeeCategory) => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data: categories } = useQuery({ queryKey: ['employee-categories'], queryFn: hrApi.employeeCategories.list, enabled: isOpen });

  useEffect(() => {
    if (isOpen) {
      setName('');
      setDescription('');
      setError(null);
    }
  }, [isOpen]);

  const create = useMutation({
    mutationFn: () => hrApi.employeeCategories.create({ name: tidyName(name), description: description.trim() === '' ? null : description.trim() }),
    onSuccess: async (category) => {
      await queryClient.invalidateQueries({ queryKey: ['employee-categories'] });
      show(`Category "${category.name}" created.`);
      onCreated(category);
      onClose();
    },
    onError: (err) => setError(nameError(err, 'Could not create the category.')),
  });

  const submit = () => {
    const problem = validateNewName(name, categories ?? [], 'category');
    setError(problem);
    if (problem === null) create.mutate();
  };

  return (
    <Modal
      confirmDiscard
      isOpen={isOpen}
      onClose={onClose}
      title="Create category"
      size="sm"
      footer={
        <>
          <ModalCancelButton disabled={create.isPending} />
          <Button size="sm" isLoading={create.isPending} onClick={submit}>Create category</Button>
        </>
      }
    >
      <ErrorBox message={error} />
      <FormField label="Category name" htmlFor="quick-category-name" required>
        <input
          id="quick-category-name"
          className={inputClasses}
          value={name}
          onChange={(event) => setName(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              submit();
            }
          }}
          autoFocus
        />
      </FormField>
      <FormField label="Description (optional)" htmlFor="quick-category-description">
        <input id="quick-category-description" className={inputClasses} value={description} onChange={(event) => setDescription(event.target.value)} />
      </FormField>
    </Modal>
  );
}
