import type { ReactNode } from 'react';
import { useEffectivePermissions, usePermissions } from '@/hooks/usePermissions';
import { gateAllows } from '@/utils/permissionGate';

interface PermissionGateProps {
  /** A `visible_modules` key from the dashboard response, e.g. "customers", "store". */
  module: string;
  /** The exact permission the control needs when it is not the module's usual management key (for example marketing.assign). */
  permission?: string;
  children: ReactNode;
  fallback?: ReactNode;
}

/**
 * UI-only gate — hides/disables an action the current admin likely can't perform.
 * The backend's `permission:<key>` middleware is what actually enforces this; a
 * rejected request still surfaces as a 403 even if this component gets it wrong.
 *
 * For the HR and Marketing modules the gate checks the real management permission (see utils/permissionGate.ts); other modules use the
 * module-level hint from the dashboard response.
 */
export function PermissionGate({ module, permission, children, fallback = null }: PermissionGateProps) {
  const { canAccessModule, isLoading } = usePermissions();
  const { hasPermission, isLoading: permissionsLoading } = useEffectivePermissions();
  if (isLoading || permissionsLoading) return null;
  return gateAllows(module, hasPermission, canAccessModule, permission) ? <>{children}</> : <>{fallback}</>;
}
