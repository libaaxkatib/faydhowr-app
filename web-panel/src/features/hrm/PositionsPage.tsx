import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import type { Position } from '@/types/employee';

export function PositionsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isOpen, setIsOpen] = useState(false);
  const [editing, setEditing] = useState<Position | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Position | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [departmentId, setDepartmentId] = useState('');

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: hrApi.departments.list });

  const openCreate = () => {
    setEditing(null);
    setName('');
    setDepartmentId('');
    setIsOpen(true);
  };

  const openEdit = (position: Position) => {
    setEditing(position);
    setName(position.name);
    setDepartmentId(position.department_id ? String(position.department_id) : '');
    setIsOpen(true);
  };

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = { name, department_id: departmentId ? Number(departmentId) : null };
      return editing ? hrApi.positions.update(editing.id, payload) : hrApi.positions.create(payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['positions'] });
      show(editing ? 'Position updated.' : 'Position created.');
      setIsOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hrApi.positions.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['positions'] });
      show('Position deleted.');
      setPendingDelete(null);
      setDeleteError(null);
    },
    // A refusal ("still used by 2 employees â€¦") stays visible in the dialog instead of flashing past as a toast.
    onError: (err) => setDeleteError(friendlyErrorMessage(err, 'Could not delete the position. Please try again.')),
  });

  const closeDeleteDialog = () => {
    setPendingDelete(null);
    setDeleteError(null);
  };

  return (
    <div>
      <PageHeader
        title="Positions"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Positions' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={openCreate}>
              <Icon name="plus" size={15} />
              Add Position
            </Button>
          </PermissionGate>
        }
      />

      <Card>
        {isLoading && <LoadingState label="Loading positionsâ€¦" />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="shield" title="No positions yet" />}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((position) => (
              <div key={position.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">{position.name}</p>
                  <p className="text-xs text-ink-muted">{position.department_name ?? 'No department'}</p>
                </div>
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1">
                    <button type="button" onClick={() => openEdit(position)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary">
                      <Icon name="pencil" size={14} />
                    </button>
                    <button type="button" aria-label={`Delete position ${position.name}`} onClick={() => { setDeleteError(null); setPendingDelete(position); }} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
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
        title={editing ? 'Edit Position' : 'Add Position'}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save</Button>
          </>
        }
      >
        <FormField label="Name" htmlFor="pos-name" required>
          <input id="pos-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label="Department" htmlFor="pos-department">
          <Select
            id="pos-department"
            value={departmentId}
            onChange={(e) => setDepartmentId(e.target.value)}
            placeholder="Not set"
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
          />
        </FormField>
      </Modal>

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        title="Delete position?"
        description={`Delete â€œ${pendingDelete?.name ?? ''}â€? This cannot be undone. A position that employees, work assignments or workforce requests still use cannot be deleted.`}
        confirmLabel="Delete position"
        tone="danger"
        isLoading={deleteMutation.isPending}
        errorMessage={deleteError}
        onConfirm={() => pendingDelete && deleteMutation.mutate(pendingDelete.id)}
        onCancel={closeDeleteDialog}
      />
    </div>
  );
}
