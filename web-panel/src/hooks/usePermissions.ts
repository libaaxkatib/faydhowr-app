import { useQuery } from '@tanstack/react-query';

import { dashboardApi } from '@/api/dashboard';
import { useAuth } from '@/features/auth/useAuth';

/**
 * The Admin API has no "my effective permissions" endpoint (see AdminPermissionResolver
 * on the backend — the only introspection endpoint requires `roles.manage`, which most
 * non-super-admin accounts don't hold). The closest first-class signal for the *current*
 * admin is `data.visible_modules` on GET /admin/dashboard, which the backend derives from
 * their actual permission set — but it's module-level, not per-action, and conflates
 * several permissions per module (e.g. "store" is visible with ANY of products.create/
 * update/delete). This hook exposes that coarse signal for UI-level gating only; the
 * backend's `permission:<key>` middleware remains the real authorization boundary on
 * every mutation regardless of what this hook returns.
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
