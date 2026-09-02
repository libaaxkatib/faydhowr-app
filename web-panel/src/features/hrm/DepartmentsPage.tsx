import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import type { Department } from '@/types/employee';

export function DepartmentsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isOpen, setIsOpen] = useState(false);
  const [editing, setEditing] = useState<Department | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const openCreate = () => {
    setEditing(null);
    setName('');
    setDescription('');
    setIsOpen(true);
  };

  const openEdit = (department: Department) => {
    setEditing(department);
    setName(department.name);
    setDescription(department.description ?? '');
    setIsOpen(true);
  };

  const saveMutation = useMutation({
    mutationFn: () =>
      editing
        ? hrApi.departments.update(editing.id, { name, description: description || null })
        : hrApi.departments.create({ name, description: description || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['departments'] });
      show(editing ? 'Department updated.' : 'Department created.');
      setIsOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hrApi.departments.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['departments'] });
      show('Department deleted.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete department.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Departments"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Departments' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={openCreate}>
              <Icon name="plus" size={15} />
              Add Department
            </Button>
          </PermissionGate>
        }
      />

      <Card>
        {isLoading && <LoadingState label="Loading departments…" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="box" title="No departments yet" />}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((department) => (
              <div key={department.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">{department.name}</p>
                  {department.description && <p className="text-xs text-ink-muted">{department.description}</p>}
                </div>
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1">
                    <button type="button" onClick={() => openEdit(department)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary">
                      <Icon name="pencil" size={14} />
                    </button>
                    <button type="button" onClick={() => deleteMutation.mutate(department.id)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
                      <Icon name="trash" size={14} />
                    </button>
                  </div>
                </PermissionGate>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        title={editing ? 'Edit Department' : 'Add Department'}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save</Button>
          </>
        }
      >
        <FormField label="Name" htmlFor="dept-name" required>
          <input id="dept-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label="Description" htmlFor="dept-description">
          <textarea id="dept-description" rows={2} className={inputClasses + ' h-auto py-2'} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
