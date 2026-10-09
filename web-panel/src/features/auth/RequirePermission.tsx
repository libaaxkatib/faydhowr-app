import type { ReactNode } from 'react';

import { PageState } from '@/components/ui/PageState';
import { useEffectivePermissions } from '@/hooks/usePermissions';

/**
 * Route-level permission guard, sitting inside `ProtectedRoute` (which only
 * checks login state). Mounts `children` only if the admin's real effective
 * permissions include `permission` — the same `AdminPermission` key the
 * backend route this page calls actually requires (see routes/api.php).
 *
 * This is still UX only: the backend's `permission:<key>` middleware is the
 * real authorization boundary regardless of what this renders, and it keeps
 * 403ing a disallowed request either way. This guard exists so a restricted
 * admin gets a clear "access restricted" page instead of the real page
 * shell mounting and firing API calls that just come back 403.
 */
export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { hasPermission, isLoading } = useEffectivePermissions();

  // Permissions haven't resolved yet - render nothing rather than flashing
  // the access-denied page before the real effective-permission set loads.
  if (isLoading) return null;

  if (!hasPermission(permission)) {
    return (
      <PageState
        icon="shield"
        title="Access restricted"
        description="Your account doesn't have permission to view this page. Ask an administrator if you believe this is a mistake."
      />
    );
  }

  return <>{children}</>;
}
