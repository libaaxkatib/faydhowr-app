import type { ReactNode } from 'react';
import { usePermissions } from '@/hooks/usePermissions';

interface PermissionGateProps {
  /** A `visible_modules` key from the dashboard response, e.g. "customers", "store". */
  module: string;
  children: ReactNode;
  fallback?: ReactNode;
}

/**
 * UI-only gate — hides/disables an action the current admin likely can't perform.
 * The backend's `permission:<key>` middleware is what actually enforces this; a
 * rejected request still surfaces as a 403 even if this component gets it wrong.
 */
export function PermissionGate({ module, children, fallback = null }: PermissionGateProps) {
  const { canAccessModule, isLoading } = usePermissions();
  if (isLoading) return null;
  return canAccessModule(module) ? <>{children}</> : <>{fallback}</>;
}
