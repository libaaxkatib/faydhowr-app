import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import type { Department } from '@/types/employee';

export function DepartmentsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [isOpen, setIsOpen] = useState(false);
  const [editing, setEditing] = useState<Department | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Department | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
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
      show(editing ? t('notify.company.departmentUpdated') : t('notify.company.departmentCreated'));
      setIsOpen(false);
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.somethingWentWrong')), 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hrApi.departments.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['departments'] });
      show(t('notify.company.departmentDeleted'));
      setPendingDelete(null);
      setDeleteError(null);
    },
    // A refusal ("still used by 2 positions …") stays visible in the dialog instead of flashing past as a toast.
    onError: (err) => setDeleteError(friendlyErrorMessage(err, t('notify.company.couldNotDeleteDepartment'))),
  });

  const closeDeleteDialog = () => {
    setPendingDelete(null);
    setDeleteError(null);
  };

  return (
    <div>
      <PageHeader
        title={t('nav.departments')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.departments') }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={openCreate}>
              <Icon name="plus" size={15} />
              {t('action.company.addDepartment')}
            </Button>
          </PermissionGate>
        }
      />

      <Card>
        {isLoading && <LoadingState label={t('option.employees.loadingDepartments')} />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && data.length === 0 && <EmptyState icon="box" title={t('option.employees.noDepartmentsYet')} />}
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
                    <button type="button" aria-label={t('action.company.deleteDepartmentNamed', { name: department.name })} onClick={() => { setDeleteError(null); setPendingDelete(department); }} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
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
        title={editing ? t('confirm.company.editDepartmentTitle') : t('action.company.addDepartment')}
        size="sm"
        footer={
          <>
            <ModalCancelButton />
            <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.label.name')} htmlFor="dept-name" required>
          <input id="dept-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label={t('option.label.description')} htmlFor="dept-description">
          <textarea id="dept-description" rows={2} className={inputClasses + ' h-auto py-2'} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        title={t('confirm.company.deleteDepartmentTitle')}
        description={t('confirm.company.deleteDepartmentBody', { name: pendingDelete?.name ?? '' })}
        confirmLabel={t('confirm.company.deleteDepartmentButton')}
        tone="danger"
        isLoading={deleteMutation.isPending}
        errorMessage={deleteError}
        onConfirm={() => pendingDelete && deleteMutation.mutate(pendingDelete.id)}
        onCancel={closeDeleteDialog}
      />
    </div>
  );
}
