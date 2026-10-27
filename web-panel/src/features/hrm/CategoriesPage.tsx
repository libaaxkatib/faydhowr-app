import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { PageHeader } from '@/components/ui/PageHeader';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { tidyName, validateNewName } from '@/utils/quickCreate';
import type { EmployeeCategory } from '@/types/employee';

/**
 * Employee categories: view, add, rename and ARCHIVE / RESTORE. There is deliberately no delete: archiving hides a category from new
 * registrations and requests and changes nothing that already uses it (ids, employees, requests and history stay linked); restoring offers it
 * again. The Office Staff category belongs to the Office Staff workflow and is locked. The server enforces all of this.
 */
export function CategoriesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tCount } = useI18n();
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.manage');
  const [editing, setEditing] = useState<EmployeeCategory | 'new' | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [showArchived, setShowArchived] = useState(true);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['employee-categories'], queryFn: hrApi.employeeCategories.list });
  const categories = (data ?? []).filter((c) => showArchived || !c.archived);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['employee-categories'] });

  const open = (target: EmployeeCategory | 'new') => {
    setEditing(target);
    setName(target === 'new' ? '' : target.name);
    setDescription(target === 'new' ? '' : (target.description ?? ''));
    setFormError(null);
  };

  const save = useMutation({
    mutationFn: () => {
      const payload = { name: tidyName(name), description: description.trim() === '' ? null : description.trim() };
      return editing === 'new' || editing === null ? hrApi.employeeCategories.create(payload) : hrApi.employeeCategories.update(editing.id, payload);
    },
    onSuccess: () => {
      refresh();
      show(editing === 'new' ? t('notify.company.categoryCreated') : t('notify.company.categoryUpdated'));
      setEditing(null);
    },
    onError: (err) => setFormError(err instanceof ApiClientError ? (err.fieldErrors?.name?.[0] ?? friendlyErrorMessage(err, t('notify.company.couldNotSaveCategory'))) : friendlyErrorMessage(err, t('notify.company.couldNotSaveCategory'))),
  });

  const submit = () => {
    const others = (data ?? []).filter((c) => editing === 'new' || editing === null || c.id !== editing.id);
    const problem = validateNewName(name, others, 'category');
    setFormError(problem);
    if (problem === null) save.mutate();
  };

  const archive = useMutation({
    mutationFn: ({ category, archived }: { category: EmployeeCategory; archived: boolean }) => (archived ? hrApi.employeeCategories.archive(category.id) : hrApi.employeeCategories.restore(category.id)),
    onSuccess: (_row, { archived }) => {
      refresh();
      show(archived ? t('notify.company.categoryArchived') : t('notify.company.categoryRestored'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.couldNotChangeCategory')), 'error'),
  });

  return (
    <div>
      <PageHeader
        title={t('nav.categories')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.categories') }]}
        actions={
          canManage ? (
            <Button onClick={() => open('new')}>
              <Icon name="plus" size={15} />
              {t('action.company.addCategory')}
            </Button>
          ) : undefined
        }
      />

      <label className="mb-3 flex items-center gap-2 text-xs text-ink-muted">
        <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
        {t('option.company.showArchivedCategories')}
      </label>

      <Card>
        {isLoading && <LoadingState label={t('option.employees.loadingCategories')} />}
        {error && <ErrorState error={error} onRetry={refetch} />}
        {data && categories.length === 0 && <EmptyState icon="list" title={t('option.employees.noCategories')} />}
        {categories.length > 0 && (
          <div className="divide-y divide-border">
            {categories.map((category) => (
              <div key={category.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                    {category.name}
                    {category.is_system && <StatusBadge status="system" tone="info" label={t('status.company.usedByOfficeStaff')} />}
                    {category.archived && <StatusBadge status="archived" tone="neutral" label={t('status.company.archived')} />}
                  </p>
                  <p className="text-xs text-ink-muted">
                    {category.description ? `${category.description} · ` : ''}
                    {tCount('option.employees.categoryEmployeeCount', category.employees_count ?? 0)}
                  </p>
                </div>
                {canManage && (
                  <div className="flex items-center gap-1.5">
                    <Button variant="outline" size="sm" onClick={() => open(category)} aria-label={t('action.company.editCategoryNamed', { name: category.name })}>
                      <Icon name="pencil" size={14} />
                      {t('action.edit')}
                    </Button>
                    {!category.is_system && (
                      <>
                        <Button variant="outline" size="sm" isLoading={archive.isPending && archive.variables?.category.id === category.id} onClick={() => archive.mutate({ category, archived: !category.archived })} aria-label={category.archived ? t('action.company.restoreCategoryNamed', { name: category.name }) : t('action.company.archiveCategoryNamed', { name: category.name })}>
                          {category.archived ? t('action.company.restoreCategory') : t('action.company.archiveCategory')}
                        </Button>
                      </>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal
        confirmDiscard
        isOpen={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === 'new' ? t('action.company.addCategory') : t('confirm.company.editCategoryTitle')}
        size="sm"
        footer={
          <>
            <ModalCancelButton disabled={save.isPending} />
            <Button size="sm" isLoading={save.isPending} onClick={submit}>{t('action.save')}</Button>
          </>
        }
      >
        {formError && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{formError}</div>}
        <FormField label={t('option.label.name')} htmlFor="cat-name" required hint={editing !== 'new' && editing?.is_system ? t('confirm.company.categorySystemNameHint') : t('confirm.company.categoryRenameHint')}>
          <input id="cat-name" className={inputClasses} value={name} disabled={editing !== 'new' && editing?.is_system === true} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <FormField label={t('option.employees.descriptionOptional')} htmlFor="cat-description">
          <input id="cat-description" className={inputClasses} value={description} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </Modal>

    </div>
  );
}
