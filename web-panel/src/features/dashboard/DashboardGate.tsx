import { Navigate } from 'react-router-dom';

import { PageState } from '@/components/ui/PageState';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { NAV_GROUPS } from '@/layouts/nav.config';
import { firstAllowedPath } from '@/layouts/navVisibility';

/**
 * The page everyone lands on after signing in. The backend asks for `dashboard.view`, which only some roles hold: an admin without it used to land on
 * an error. They are taken to the first page in their sidebar instead (or told they have no access when there is none).
 */
export function DashboardGate() {
  const { hasPermission, isSuperAdmin, isLoading } = useEffectivePermissions();
  const { t } = useI18n();

  if (isLoading) return null;
  if (isSuperAdmin || hasPermission('dashboard.view')) return <DashboardPage />;

  const home = firstAllowedPath(NAV_GROUPS, hasPermission, isSuperAdmin);
  if (home) return <Navigate to={home} replace />;

  return <PageState icon="shield" title={t('warning.shell.accessRestricted')} description={t('warning.common.noAccessText')} />;
}
