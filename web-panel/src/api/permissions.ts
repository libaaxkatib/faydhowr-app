import { apiRequest } from '@/api/client';
import type { AdminRole } from '@/types/admin';
import type { AdminPermissionsDetail, Permission, RolePermissions } from '@/types/system';

export const permissionsApi = {
  /**
   * The CALLING admin's own effective permissions (role-derived plus any direct
   * overrides, or every permission for Super Admin). Self-only — no `roles.manage`
   * needed, unlike `list()` below.
   */
  getMine: () => apiRequest<Permission[]>('admin/auth/permissions'),

  /** The full permission catalog (key/name/group), not scoped to any role or admin. */
  list: () => apiRequest<Permission[]>('admin/permissions'),

  /** A role's currently-persisted permissions — read counterpart to `updateRolePermissions`. */
  getForRole: (role: AdminRole) => apiRequest<RolePermissions>(`admin/roles/${role}/permissions`),

  /** Full-replace: the given keys become the role's entire permission set. Rejected for super_admin (implicit/immutable). */
  updateForRole: (role: AdminRole, permissions: string[]) =>
    apiRequest<RolePermissions>(`admin/roles/${role}/permissions`, { method: 'PUT', body: { permissions } }),

  /** A specific admin's role/direct/effective permissions (direct = per-admin override on top of their role). */
  getForAdmin: (adminId: number) => apiRequest<AdminPermissionsDetail>(`admin/admins/${adminId}/permissions`),

  /** Full-replace of this admin's *direct* overrides only — their role permissions are unaffected. */
  updateForAdmin: (adminId: number, permissions: string[]) =>
    apiRequest<AdminPermissionsDetail>(`admin/admins/${adminId}/permissions`, { method: 'PUT', body: { permissions } }),
};
