import { useQuery } from '@tanstack/react-query';

import { dashboardApi } from '@/api/dashboard';
import { permissionsApi } from '@/api/permissions';
import { useAuth } from '@/features/auth/useAuth';

/**
 * `data.visible_modules` on GET /admin/dashboard, which the backend derives from the
 * admin's actual permission set — but it's module-level, not per-action, and conflates
 * several permissions per module (e.g. "store" is visible with ANY of products.create/
 * update/delete). This hook exposes that coarse signal for existing module-level UI
 * gating (every current `<PermissionGate module="...">` usage across the app). For
 * precise, per-permission-key checks, use `useEffectivePermissions` below instead —
 * added in Phase 2 specifically to close this gap. The backend's `permission:<key>`
 * middleware remains the real authorization boundary on every mutation either way.
 */
export function usePermissions() {
  const { isSuperAdmin, status } = useAuth();

  const { data, isLoading } = useQuery({
    queryKey: ['dashboard', 'visible-modules'],
    queryFn: () => dashboardApi.get(),
    enabled: status === 'authenticated',
    staleTime: 5 * 60_000,
  });

  const visibleModules = data?.visible_modules ?? [];

  return {
    isLoading,
    isSuperAdmin,
    /** Coarse, module-level UI hint. Never the final authority — see comment above. */
    canAccessModule: (moduleKey: string) => isSuperAdmin || visibleModules.includes(moduleKey),
  };
}

/**
 * The admin's real, per-action effective permissions from `GET /admin/auth/permissions`
 * (role-derived plus any direct overrides, or every permission for Super Admin) — this
 * is the actual AdminPermissionResolver output, not the coarse module-level heuristic
 * above. UX-only: the backend's `permission:<key>` middleware is still the real
 * authorization boundary, and a rejected request still surfaces as a 403 even if this
 * hook gets it wrong.
 */
export function useEffectivePermissions() {
  const { isSuperAdmin, status } = useAuth();

  const { data, isLoading } = useQuery({
    queryKey: ['admin-effective-permissions'],
    queryFn: () => permissionsApi.getMine(),
    enabled: status === 'authenticated',
    staleTime: 5 * 60_000,
  });

  const keys = new Set((data ?? []).map((permission) => permission.key));

  return {
    isLoading,
    isSuperAdmin,
    keys,
    hasPermission: (key: string) => isSuperAdmin || keys.has(key),
  };
}
