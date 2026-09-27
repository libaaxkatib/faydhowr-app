import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { permissionsApi } from '@/api/permissions';
import { adminsApi } from '@/api/admins';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { ADMIN_ROLE_LABELS, ASSIGNABLE_ADMIN_ROLES } from '@/types/system';
import type { AdminRole } from '@/types/admin';
import type { Permission } from '@/types/system';

/** Groups a flat permission list by its `group` label, preserving first-seen order. */
function groupPermissions(permissions: Permission[]): [string, Permission[]][] {
  const groups = new Map<string, Permission[]>();
  for (const permission of permissions) {
    const bucket = groups.get(permission.group);
    if (bucket) {
      bucket.push(permission);
    } else {
      groups.set(permission.group, [permission]);
    }
  }
  return Array.from(groups.entries());
}

interface PermissionChecklistProps {
  groups: [string, Permission[]][];
  selected: Set<string>;
  onToggle?: (key: string) => void;
  disabled?: boolean;
}

function PermissionChecklist({ groups, selected, onToggle, disabled }: PermissionChecklistProps) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {groups.map(([group, permissions]) => (
        <div key={group} className="rounded-md border border-border p-3.5">
          <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{group}</p>
          <div className="space-y-1.5">
            {permissions.map((permission) => (
              <label key={permission.key} className="flex items-center gap-2 text-sm text-ink">
                <input
                  type="checkbox"
                  checked={selected.has(permission.key)}
                  disabled={disabled || !onToggle}
                  onChange={() => onToggle?.(permission.key)}
                  className="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary/30"
                />
                {permission.name}
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function RoleTab() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const [selectedRole, setSelectedRole] = useState<AdminRole>(ASSIGNABLE_ADMIN_ROLES[0].value);
  const [draft, setDraft] = useState<Set<string>>(new Set());

  const catalog = useQuery({ queryKey: ['permissions-catalog'], queryFn: permissionsApi.list });
  const rolePermissions = useQuery({
    queryKey: ['role-permissions', selectedRole],
    queryFn: () => permissionsApi.getForRole(selectedRole),
  });

  useEffect(() => {
    if (rolePermissions.data) {
      setDraft(new Set(rolePermissions.data.permissions.map((p) => p.key)));
    }
  }, [rolePermissions.data]);

  const saveMutation = useMutation({
    mutationFn: () => permissionsApi.updateForRole(selectedRole, Array.from(draft)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['role-permissions', selectedRole] });
      show(`${ADMIN_ROLE_LABELS[selectedRole]} permissions updated.`);
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not update role permissions.', 'error'),
  });

  const groups = useMemo(() => groupPermissions(catalog.data ?? []), [catalog.data]);
  const isDirty =
    rolePermissions.data &&
    (draft.size !== rolePermissions.data.permissions.length ||
      rolePermissions.data.permissions.some((p) => !draft.has(p.key)));

  return (
    <div className="grid grid-cols-1 gap-5 md:grid-cols-[220px_1fr]">
      <div className="space-y-1">
        {ASSIGNABLE_ADMIN_ROLES.map((r) => (
          <button
            key={r.value}
            type="button"
            onClick={() => setSelectedRole(r.value)}
            className={`w-full rounded-sm px-3 py-2 text-left text-sm font-medium transition ${
              selectedRole === r.value ? 'bg-primary-soft text-primary' : 'text-ink-muted hover:bg-surface-alt'
            }`}
          >
            {r.label}
          </button>
        ))}
        <div className="mt-2 border-t border-border pt-2">
          <p className="px-3 py-1.5 text-xs text-ink-faint">Super Admin holds every permission implicitly and can't be edited here.</p>
        </div>
      </div>

      <Card className="p-5">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="font-display text-base font-bold text-ink">{ADMIN_ROLE_LABELS[selectedRole]} permissions</h3>
          {hasPermission('roles.manage') && (
            <Button size="sm" isLoading={saveMutation.isPending} disabled={!isDirty} onClick={() => saveMutation.mutate()}>
              Save changes
            </Button>
          )}
        </div>

        {(catalog.isLoading || rolePermissions.isLoading) && <LoadingState label="Loading permissions…" />}
        {(catalog.error || rolePermissions.error) && (
          <ErrorState error={catalog.error ?? rolePermissions.error} onRetry={() => rolePermissions.refetch()} />
        )}
        {catalog.data && rolePermissions.data && groups.length === 0 && (
          <EmptyState icon="shield" title="No permissions defined yet" />
        )}
        {catalog.data && rolePermissions.data && groups.length > 0 && (
          <PermissionChecklist groups={groups} selected={draft} onToggle={(key) => {
            setDraft((prev) => {
              const next = new Set(prev);
              if (next.has(key)) {
                next.delete(key);
              } else {
                next.add(key);
              }
              return next;
            });
          }} />
        )}
      </Card>
    </div>
  );
}

function AdminOverridesTab() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const [adminId, setAdminId] = useState<number | ''>('');
  const [draft, setDraft] = useState<Set<string>>(new Set());

  const catalog = useQuery({ queryKey: ['permissions-catalog'], queryFn: permissionsApi.list });
  const adminsList = useQuery({
    queryKey: ['admins-for-override-picker'],
    queryFn: () => adminsApi.list({ per_page: 100, status: 'active' }),
  });
  const adminPermissions = useQuery({
    queryKey: ['admin-permissions', adminId],
    queryFn: () => permissionsApi.getForAdmin(adminId as number),
    enabled: adminId !== '',
  });

  useEffect(() => {
    if (adminPermissions.data) {
      setDraft(new Set(adminPermissions.data.direct_permissions.map((p) => p.key)));
    }
  }, [adminPermissions.data]);

  const saveMutation = useMutation({
    mutationFn: () => permissionsApi.updateForAdmin(adminId as number, Array.from(draft)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-permissions', adminId] });
      show('Admin permission overrides updated.');
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : 'Could not update admin overrides.', 'error'),
  });

  const groups = useMemo(() => groupPermissions(catalog.data ?? []), [catalog.data]);
  const selectedAdmin = adminsList.data?.data.find((a) => a.id === adminId);
  const isSuperAdmin = selectedAdmin?.role === 'super_admin';
  const isDirty =
    adminPermissions.data &&
    (draft.size !== adminPermissions.data.direct_permissions.length ||
      adminPermissions.data.direct_permissions.some((p) => !draft.has(p.key)));

  return (
    <Card className="p-5">
      <FormRow adminId={adminId} onChange={setAdminId} admins={adminsList.data?.data ?? []} />

      {adminId === '' && (
        <EmptyState icon="users" title="Pick an admin" description="Choose an admin above to view or edit their direct permission overrides." />
      )}

      {adminId !== '' && isSuperAdmin && (
        <p className="rounded-md border border-border bg-surface-alt px-3.5 py-3 text-sm text-ink-muted">
          Super Admin already holds every permission implicitly. Direct overrides don't apply.
        </p>
      )}

      {adminId !== '' && !isSuperAdmin && (
        <>
          {(catalog.isLoading || adminPermissions.isLoading) && <LoadingState label="Loading permissions…" />}
          {(catalog.error || adminPermissions.error) && (
            <ErrorState error={catalog.error ?? adminPermissions.error} onRetry={() => adminPermissions.refetch()} />
          )}

          {adminPermissions.data && (
            <>
              <p className="mb-3 text-xs text-ink-faint">
                From role ({ADMIN_ROLE_LABELS[adminPermissions.data.role]}): {adminPermissions.data.role_permissions.length === 0
                  ? 'no permissions granted yet'
                  : adminPermissions.data.role_permissions.map((p) => p.name).join(', ')}
              </p>

              <div className="mb-4 flex items-center justify-between">
                <h3 className="font-display text-base font-bold text-ink">Direct overrides</h3>
                {hasPermission('roles.manage') && (
                  <Button size="sm" isLoading={saveMutation.isPending} disabled={!isDirty} onClick={() => saveMutation.mutate()}>
                    Save changes
                  </Button>
                )}
              </div>

              {groups.length > 0 && (
                <PermissionChecklist groups={groups} selected={draft} onToggle={(key) => {
                  setDraft((prev) => {
                    const next = new Set(prev);
                    if (next.has(key)) {
                      next.delete(key);
                    } else {
                      next.add(key);
                    }
                    return next;
                  });
                }} />
              )}
            </>
          )}
        </>
      )}
    </Card>
  );
}

function FormRow({
  adminId,
  onChange,
  admins,
}: {
  adminId: number | '';
  onChange: (id: number | '') => void;
  admins: { id: number; full_name: string; email: string }[];
}) {
  return (
    <div className="mb-4">
      <Select
        value={adminId === '' ? '' : String(adminId)}
        onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
        placeholder="Select an admin…"
        options={admins.map((a) => ({ value: String(a.id), label: `${a.full_name} (${a.email})` }))}
        className="w-full max-w-sm"
      />
    </div>
  );
}

export function RolesPermissionsPage() {
  const [tab, setTab] = useState<'roles' | 'overrides'>('roles');

  return (
    <div>
      <PageHeader title="Roles & Permissions" breadcrumb={[{ label: 'System', to: '/system/roles' }, { label: 'Roles & Permissions' }]} />

      <div className="mb-5 flex items-center gap-1 border-b border-border">
        <button
          type="button"
          onClick={() => setTab('roles')}
          className={`border-b-2 px-3 py-2.5 text-sm font-semibold transition ${
            tab === 'roles' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'
          }`}
        >
          <Icon name="shield" size={14} /> Roles
        </button>
        <button
          type="button"
          onClick={() => setTab('overrides')}
          className={`border-b-2 px-3 py-2.5 text-sm font-semibold transition ${
            tab === 'overrides' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'
          }`}
        >
          <Icon name="users" size={14} /> Per-Admin Overrides
        </button>
      </div>

      {tab === 'roles' ? <RoleTab /> : <AdminOverridesTab />}
    </div>
  );
}
