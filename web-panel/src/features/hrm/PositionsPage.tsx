import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import type { Position } from '@/types/employee';

export function PositionsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
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
      show(editing ? t('notify.company.positionUpdated') : t('notify.company.positionCreated'));
      setIsOpen(false);
    },
    // Show the real reason (for example "A position with this name already exists.") instead of a generic message.
    onError: (err) => show(err instanceof ApiClientError ? (err.fieldErrors?.name?.[0] ?? err.message) : err instanceof Error ? err.message : t('notify.company.somethingWentWrong'), 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hrApi.positions.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['positions'] });
      show(t('notify.company.positionDeleted'));
      setPendingDelete(null);
      setDeleteError(null);
    },
    // A refusal ("still used by 2 employees …") stays visible in the dialog instead of flashing past as a toast.
    onError: (err) => setDeleteError(friendlyErrorMessage(err, t('notify.company.couldNotDeletePosition'))),
  });

  const closeDeleteDialog = () => {
    setPendingDelete(null);
    setDeleteError(null);
  };

  return (
    <div>
      <PageHeader
        title={t('nav.positions')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.positions') }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={openCreate}>
              <Icon name="plus" size={15} />
              {t('action.company.addPosition')}
            </Button>
          </PermissionGate>
        }
      />

      <Card>
        {isLoading && <LoadingState label={t('option.employees.loadingPositions')} />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="shield" title={t('option.employees.noPositionsYet')} />}
        {data && data.length > 0 && (
          <div className="divide-y divide-border">
            {data.map((position) => (
              <div key={position.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">{position.name}</p>
                  <p className="text-xs text-ink-muted">{position.department_name ?? t('option.employees.noDepartment')}</p>
                </div>
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1">
                    <button type="button" onClick={() => openEdit(position)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary">
                      <Icon name="pencil" size={14} />
                    </button>
                    <button type="button" aria-label={t('action.company.deletePositionNamed', { name: position.name })} onClick={() => { setDeleteError(null); setPendingDelete(position); }} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
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
        confirmDiscard
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        title={editing ? t('confirm.company.editPositionTitle') : t('action.company.addPosition')}
        size="sm"
        footer={
          <>
            <ModalCancelButton />
            <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.label.name')} htmlFor="pos-name" required>
          <input id="pos-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label={t('option.label.department')} htmlFor="pos-department">
          <Select
            id="pos-department"
            value={departmentId}
            onChange={(e) => setDepartmentId(e.target.value)}
            placeholder={t('option.employees.notSet')}
            options={(departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
          />
        </FormField>
      </Modal>

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        title={t('confirm.company.deletePositionTitle')}
        description={t('confirm.company.deletePositionBody', { name: pendingDelete?.name ?? '' })}
        confirmLabel={t('confirm.company.deletePositionButton')}
        tone="danger"
        isLoading={deleteMutation.isPending}
        errorMessage={deleteError}
        onConfirm={() => pendingDelete && deleteMutation.mutate(pendingDelete.id)}
        onCancel={closeDeleteDialog}
      />
    </div>
  );
}
