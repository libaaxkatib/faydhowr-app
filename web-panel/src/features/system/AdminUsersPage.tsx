import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { adminsApi } from '@/api/admins';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { DataTable } from '@/components/ui/DataTable';
import { Pagination } from '@/components/ui/Pagination';
import { ListResultBar, ResetFiltersAction, ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useAuth } from '@/features/auth/useAuth';
import { formatDateTime } from '@/utils/formatters';
import { ASSIGNABLE_ADMIN_ROLES, adminRoleLabel } from '@/types/system';
import { AdminFormDialog } from '@/features/system/AdminFormDialog';
import { ResetPasswordDialog } from '@/features/system/ResetPasswordDialog';
import { useI18n } from '@/i18n/useI18n';
import type { Admin, AdminRole, AdminStatus } from '@/types/admin';

const ROLE_OPTIONS = [{ value: 'super_admin', label: 'Super Admin' }, ...ASSIGNABLE_ADMIN_ROLES];

export function AdminUsersPage() {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { admin: currentAdmin, isSuperAdmin } = useAuth();
  // Viewing the list needs admins.manage, but creating, editing, deleting and resetting passwords are Super Admin only on the server.
  const canManageAdmins = isSuperAdmin;

  // Search, role, status and page live in the address bar, so Back, a reload or a shared link bring the list back as it was.
  const url = useUrlFilters();
  const search = url.get('q');
  const role = url.get('role') as AdminRole | '';
  const status = url.get('status') as AdminStatus | '';
  const page = url.page;
  const setSearch = (value: string) => url.set('q', value);
  const setRole = (value: AdminRole | '') => url.set('role', value);
  const setStatus = (value: AdminStatus | '') => url.set('status', value);
  const setPage = url.setPage;
  const debouncedSearch = useDebouncedValue(search);
  const filterKeys = ['q', 'role', 'status'] as const;
  const filtered = debouncedSearch.trim() !== '' || role !== '' || status !== '';
  const resetFilters = () => url.reset(filterKeys);

  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editing, setEditing] = useState<Admin | undefined>(undefined);
  const [statusTarget, setStatusTarget] = useState<Admin | null>(null);
  const [resetTarget, setResetTarget] = useState<Admin | null>(null);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admins', { search: debouncedSearch, role, status, page }],
    queryFn: () =>
      adminsApi.list({
        search: debouncedSearch || undefined,
        role: role || undefined,
        status: status || undefined,
        page,
        per_page: 15,
      }),
    placeholderData: (previous) => previous,
  });

  const toggleStatusMutation = useMutation({
    mutationFn: (target: Admin) =>
      adminsApi.update(target.id, { status: target.status === 'active' ? 'inactive' : 'active' }),
    onSuccess: (_data, target) => {
      queryClient.invalidateQueries({ queryKey: ['admins'] });
      show(target.status === 'active' ? t('notify.system.adminDeactivated') : t('notify.system.adminReactivated'));
      setStatusTarget(null);
    },
    onError: (err) => {
      show(err instanceof ApiClientError ? err.message : t('notify.system.adminStatusFailed'), 'error');
      setStatusTarget(null);
    },
  });

  const columns = useMemo<ColumnDef<Admin, unknown>[]>(
    () => [
      {
        header: t('option.system.colAdmin'),
        accessorKey: 'full_name',
        cell: ({ row }) => (
          <div>
            <p className="font-medium text-ink">{row.original.full_name}</p>
            <p className="text-xs text-ink-muted">{row.original.email}</p>
          </div>
        ),
      },
      {
        header: t('option.label.phone'),
        accessorKey: 'phone',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.phone ?? '—'}</span>,
      },
      {
        header: t('option.system.role'),
        accessorKey: 'role',
        cell: ({ row }) => <span className="text-ink">{adminRoleLabel(row.original.role, tValue)}</span>,
      },
      {
        header: t('option.label.status'),
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} label={tValue('status', row.original.status)} />,
      },
      {
        header: t('option.system.colLastLogin'),
        accessorKey: 'last_login_at',
        cell: ({ row }) => <span className="text-ink-muted">{formatDateTime(row.original.last_login_at)}</span>,
      },
      {
        header: '',
        id: 'actions',
        cell: ({ row }) =>
          canManageAdmins && (
            <div className="flex items-center justify-end gap-1">
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation();
                  setEditing(row.original);
                  setIsFormOpen(true);
                }}
                className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
                title={t('option.system.titleEditAdmin')}
              >
                <Icon name="pencil" size={14} />
              </button>
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation();
                  setResetTarget(row.original);
                }}
                className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
                title={t('option.system.titleResetPassword')}
              >
                <Icon name="shield" size={14} />
              </button>
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation();
                  setStatusTarget(row.original);
                }}
                className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger"
                title={row.original.status === 'active' ? t('option.system.titleDeactivateAdmin') : t('option.system.titleReactivateAdmin')}
              >
                <Icon name={row.original.status === 'active' ? 'x' : 'check'} size={14} />
              </button>
            </div>
          ),
      },
    ],
    [canManageAdmins, t, tValue],
  );

  const roleOptions = ROLE_OPTIONS.map((option) => ({ value: option.value, label: adminRoleLabel(option.value, tValue) }));
  const statusOptions = [
    { value: 'active', label: t('status.active') },
    { value: 'inactive', label: t('status.inactive') },
  ];

  return (
    <div>
      <PageHeader
        title={t('option.system.pageAdminUsers')}
        breadcrumb={[{ label: t('option.system.system'), to: '/system/admins' }, { label: t('option.system.pageAdminUsers') }]}
        actions={
          canManageAdmins && (
            <Button
              onClick={() => {
                setEditing(undefined);
                setIsFormOpen(true);
              }}
            >
              <Icon name="plus" size={15} />
              {t('action.system.addAdmin')}
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder={t('option.system.searchAdmins')}
            className="w-full max-w-xs"
          />
          <Select
            value={role}
            onChange={(event) => {
              setRole(event.target.value as AdminRole | '');
              setPage(1);
            }}
            options={roleOptions}
            placeholder={t('option.system.allRoles')}
            className="w-48"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as AdminStatus | '');
              setPage(1);
            }}
            options={statusOptions}
            placeholder={t('option.system.allStatuses')}
            className="w-40"
          />
          <ResetFiltersButton count={url.activeCount(filterKeys)} onReset={resetFilters} />
        </div>
        {data && <ListResultBar matching={data.meta.total} filtered={filtered} />}

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle={filtered ? t('warning.common.noMatchTitle') : t('warning.system.noAdmins')}
          emptyDescription={filtered ? t('warning.common.noMatchText') : t('option.system.noMatchHint')}
          emptyAction={filtered ? <ResetFiltersAction onReset={resetFilters} /> : undefined}
        />

        {data && data.meta.total > 0 && <Pagination meta={data.meta} onPageChange={setPage} />}
      </Card>

      <AdminFormDialog
        isOpen={isFormOpen}
        onClose={() => setIsFormOpen(false)}
        mode={editing ? 'edit' : 'create'}
        admin={editing}
      />

      <ResetPasswordDialog isOpen={resetTarget !== null} onClose={() => setResetTarget(null)} admin={resetTarget} />

      <ConfirmDialog
        isOpen={statusTarget !== null}
        title={statusTarget?.status === 'active' ? t('confirm.system.deactivateTitle') : t('confirm.system.reactivateTitle')}
        description={
          statusTarget?.status === 'active'
            ? `${t('confirm.system.deactivateBody', { name: statusTarget?.full_name ?? '' })}${
                statusTarget?.id === currentAdmin?.id ? ` ${t('confirm.system.deactivateOwnAccount')}` : ''
              }`
            : t('confirm.system.reactivateBody', { name: statusTarget?.full_name ?? '' })
        }
        confirmLabel={statusTarget?.status === 'active' ? t('action.system.deactivate') : t('action.system.reactivate')}
        tone={statusTarget?.status === 'active' ? 'danger' : 'primary'}
        isLoading={toggleStatusMutation.isPending}
        onConfirm={() => statusTarget && toggleStatusMutation.mutate(statusTarget)}
        onCancel={() => setStatusTarget(null)}
      />
    </div>
  );
}
