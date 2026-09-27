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
import { StatusBadge } from '@/components/ui/StatusBadge';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useAuth } from '@/features/auth/useAuth';
import { formatDateTime } from '@/utils/formatters';
import { ADMIN_ROLE_LABELS, ASSIGNABLE_ADMIN_ROLES } from '@/types/system';
import { AdminFormDialog } from '@/features/system/AdminFormDialog';
import { ResetPasswordDialog } from '@/features/system/ResetPasswordDialog';
import type { Admin, AdminRole, AdminStatus } from '@/types/admin';

const ROLE_OPTIONS = [{ value: 'super_admin', label: 'Super Admin' }, ...ASSIGNABLE_ADMIN_ROLES];
const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

export function AdminUsersPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { admin: currentAdmin } = useAuth();
  const { hasPermission } = useEffectivePermissions();
  const canManageAdmins = hasPermission('admins.manage');

  const [search, setSearch] = useState('');
  const [role, setRole] = useState<AdminRole | ''>('');
  const [status, setStatus] = useState<AdminStatus | ''>('');
  const [page, setPage] = useState(1);
  const debouncedSearch = useDebouncedValue(search);

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
      show(target.status === 'active' ? 'Admin deactivated.' : 'Admin reactivated.');
      setStatusTarget(null);
    },
    onError: (err) => {
      show(err instanceof ApiClientError ? err.message : 'Could not update admin status.', 'error');
      setStatusTarget(null);
    },
  });

  const columns = useMemo<ColumnDef<Admin, unknown>[]>(
    () => [
      {
        header: 'Admin',
        accessorKey: 'full_name',
        cell: ({ row }) => (
          <div>
            <p className="font-medium text-ink">{row.original.full_name}</p>
            <p className="text-xs text-ink-muted">{row.original.email}</p>
          </div>
        ),
      },
      {
        header: 'Phone',
        accessorKey: 'phone',
        cell: ({ row }) => <span className="text-ink-muted">{row.original.phone ?? '—'}</span>,
      },
      {
        header: 'Role',
        accessorKey: 'role',
        cell: ({ row }) => <span className="text-ink">{ADMIN_ROLE_LABELS[row.original.role]}</span>,
      },
      {
        header: 'Status',
        accessorKey: 'status',
        cell: ({ row }) => <StatusBadge status={row.original.status} />,
      },
      {
        header: 'Last login',
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
                title="Edit admin"
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
                title="Reset password"
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
                title={row.original.status === 'active' ? 'Deactivate admin' : 'Reactivate admin'}
              >
                <Icon name={row.original.status === 'active' ? 'x' : 'check'} size={14} />
              </button>
            </div>
          ),
      },
    ],
    [canManageAdmins],
  );

  return (
    <div>
      <PageHeader
        title="Admin Users"
        breadcrumb={[{ label: 'System', to: '/system/admins' }, { label: 'Admin Users' }]}
        actions={
          canManageAdmins && (
            <Button
              onClick={() => {
                setEditing(undefined);
                setIsFormOpen(true);
              }}
            >
              <Icon name="plus" size={15} />
              Add Admin
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
            placeholder="Search by name, email, or phone…"
            className="w-full max-w-xs"
          />
          <Select
            value={role}
            onChange={(event) => {
              setRole(event.target.value as AdminRole | '');
              setPage(1);
            }}
            options={ROLE_OPTIONS}
            placeholder="All roles"
            className="w-48"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as AdminStatus | '');
              setPage(1);
            }}
            options={STATUS_OPTIONS}
            placeholder="All statuses"
            className="w-40"
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.data ?? []}
          isLoading={isLoading}
          error={error}
          onRetry={refetch}
          emptyTitle="No admins found"
          emptyDescription="Try adjusting your search or filters."
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
        title={statusTarget?.status === 'active' ? 'Deactivate admin?' : 'Reactivate admin?'}
        description={
          statusTarget?.status === 'active'
            ? `${statusTarget?.full_name} will no longer be able to log in. This can be undone at any time by reactivating them.${
                statusTarget?.id === currentAdmin?.id ? ' This is your own account — you will be signed out immediately and no one will be able to reactivate it except another Super Admin.' : ''
              }`
            : `${statusTarget?.full_name} will be able to log in again.`
        }
        confirmLabel={statusTarget?.status === 'active' ? 'Deactivate' : 'Reactivate'}
        tone={statusTarget?.status === 'active' ? 'danger' : 'primary'}
        isLoading={toggleStatusMutation.isPending}
        onConfirm={() => statusTarget && toggleStatusMutation.mutate(statusTarget)}
        onCancel={() => setStatusTarget(null)}
      />
    </div>
  );
}
