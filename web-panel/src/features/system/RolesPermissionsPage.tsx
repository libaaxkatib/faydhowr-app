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
import { useAuth } from '@/features/auth/useAuth';
import { ASSIGNABLE_ADMIN_ROLES, adminRoleLabel, permissionAllowedForRole } from '@/types/system';
import { useI18n } from '@/i18n/useI18n';
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

/** The key a permission group's translation is stored under: the group name in lowercase, any other characters as "_". */
function permissionGroupSlug(group: string): string {
  return group.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
}

interface PermissionChecklistProps {
  groups: [string, Permission[]][];
  selected: Set<string>;
  onToggle?: (key: string) => void;
  disabled?: boolean;
}

function PermissionChecklist({ groups, selected, onToggle, disabled }: PermissionChecklistProps) {
  const { tValue } = useI18n();

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {groups.map(([group, permissions]) => (
        <div key={group} className="rounded-md border border-border p-3.5">
          <p className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{tValue('option.system.permissionGroup', permissionGroupSlug(group)) ?? group}</p>
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
                {tValue('option.system.permission', permission.key) ?? permission.name}
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function RoleTab() {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  // Changing a role's or an admin's permissions is Super Admin only on the server (viewing needs roles.manage).
  const { isSuperAdmin: actorIsSuperAdmin } = useAuth();
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
      show(t('notify.system.rolePermissionsUpdated', { role: adminRoleLabel(selectedRole, tValue) }));
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : t('notify.system.rolePermissionsFailed'), 'error'),
  });

  const groups = useMemo(
    () => groupPermissions((catalog.data ?? []).filter((p) => permissionAllowedForRole(p.key, selectedRole))),
    [catalog.data, selectedRole],
  );
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
            {adminRoleLabel(r.value, tValue)}
          </button>
        ))}
        <div className="mt-2 border-t border-border pt-2">
          <p className="px-3 py-1.5 text-xs text-ink-faint">{t('warning.system.superAdminNote')}</p>
        </div>
      </div>

      <Card className="p-5">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="font-display text-base font-bold text-ink">{t('option.system.rolePermissionsTitle', { role: adminRoleLabel(selectedRole, tValue) })}</h3>
          {actorIsSuperAdmin && (
            <Button size="sm" isLoading={saveMutation.isPending} disabled={!isDirty} onClick={() => saveMutation.mutate()}>
              {t('action.company.saveChanges')}
            </Button>
          )}
        </div>

        {(catalog.isLoading || rolePermissions.isLoading) && <LoadingState label={t('option.system.loadingPermissions')} />}
        {(catalog.error || rolePermissions.error) && (
          <ErrorState error={catalog.error ?? rolePermissions.error} onRetry={() => rolePermissions.refetch()} />
        )}
        {catalog.data && rolePermissions.data && groups.length === 0 && (
          <EmptyState icon="shield" title={t('warning.system.noPermissions')} />
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
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  // Changing a role's or an admin's permissions is Super Admin only on the server (viewing needs roles.manage).
  const { isSuperAdmin: actorIsSuperAdmin } = useAuth();
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
      show(t('notify.system.overridesUpdated'));
    },
    onError: (err) => show(err instanceof ApiClientError ? err.message : t('notify.system.overridesFailed'), 'error'),
  });

  const selectedAdmin = adminsList.data?.data.find((a) => a.id === adminId);
  const groups = useMemo(
    () => groupPermissions((catalog.data ?? []).filter((p) => permissionAllowedForRole(p.key, selectedAdmin?.role))),
    [catalog.data, selectedAdmin?.role],
  );
  const isSuperAdmin = selectedAdmin?.role === 'super_admin';
  const isDirty =
    adminPermissions.data &&
    (draft.size !== adminPermissions.data.direct_permissions.length ||
      adminPermissions.data.direct_permissions.some((p) => !draft.has(p.key)));

  return (
    <Card className="p-5">
      <FormRow adminId={adminId} onChange={setAdminId} admins={adminsList.data?.data ?? []} />

      {adminId === '' && (
        <EmptyState icon="users" title={t('warning.system.pickAdminTitle')} description={t('warning.system.pickAdminText')} />
      )}

      {adminId !== '' && isSuperAdmin && (
        <p className="rounded-md border border-border bg-surface-alt px-3.5 py-3 text-sm text-ink-muted">
          {t('warning.system.superAdminOverrides')}
        </p>
      )}

      {adminId !== '' && !isSuperAdmin && (
        <>
          {(catalog.isLoading || adminPermissions.isLoading) && <LoadingState label={t('option.system.loadingPermissions')} />}
          {(catalog.error || adminPermissions.error) && (
            <ErrorState error={catalog.error ?? adminPermissions.error} onRetry={() => adminPermissions.refetch()} />
          )}

          {adminPermissions.data && (
            <>
              <p className="mb-3 text-xs text-ink-faint">
                {t('option.system.fromRole', {
                  role: adminRoleLabel(adminPermissions.data.role, tValue),
                  permissions:
                    adminPermissions.data.role_permissions.length === 0
                      ? t('warning.system.noPermissionsGranted')
                      : adminPermissions.data.role_permissions.map((p) => tValue('option.system.permission', p.key) ?? p.name).join(', '),
                })}
              </p>

              <div className="mb-4 flex items-center justify-between">
                <h3 className="font-display text-base font-bold text-ink">{t('option.system.directOverrides')}</h3>
                {actorIsSuperAdmin && (
                  <Button size="sm" isLoading={saveMutation.isPending} disabled={!isDirty} onClick={() => saveMutation.mutate()}>
                    {t('action.company.saveChanges')}
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
  const { t } = useI18n();

  return (
    <div className="mb-4">
      <Select
        value={adminId === '' ? '' : String(adminId)}
        onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
        placeholder={t('option.system.selectAdmin')}
        options={admins.map((a) => ({ value: String(a.id), label: `${a.full_name} (${a.email})` }))}
        className="w-full max-w-sm"
      />
    </div>
  );
}

export function RolesPermissionsPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<'roles' | 'overrides'>('roles');

  return (
    <div>
      <PageHeader
        title={t('option.system.pageRoles')}
        breadcrumb={[{ label: t('option.system.system'), to: '/system/roles' }, { label: t('option.system.pageRoles') }]}
      />

      <div className="mb-5 flex items-center gap-1 border-b border-border">
        <button
          type="button"
          onClick={() => setTab('roles')}
          className={`border-b-2 px-3 py-2.5 text-sm font-semibold transition ${
            tab === 'roles' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'
          }`}
        >
          <Icon name="shield" size={14} /> {t('option.system.tabRoles')}
        </button>
        <button
          type="button"
          onClick={() => setTab('overrides')}
          className={`border-b-2 px-3 py-2.5 text-sm font-semibold transition ${
            tab === 'overrides' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'
          }`}
        >
          <Icon name="users" size={14} /> {t('option.system.tabOverrides')}
        </button>
      </div>

      {tab === 'roles' ? <RoleTab /> : <AdminOverridesTab />}
    </div>
  );
}
