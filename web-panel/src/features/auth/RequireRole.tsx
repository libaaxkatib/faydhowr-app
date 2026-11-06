import type { ReactNode } from 'react';

import { PageState } from '@/components/ui/PageState';
import { useAuth } from '@/features/auth/useAuth';
import { useI18n } from '@/i18n/useI18n';
import { roleAllowed } from '@/utils/accessRules';

/**
 * Route guard for a page the backend opens by ROLE rather than by permission (the audit log: EnsureAuditLogReader; the compliance report:
 * ComplianceController::deny). The Super Admin always passes. UX only: the backend checks the role on every request this page makes and refuses anyone
 * else, whatever this renders.
 */
export function RequireRole({ roles, children }: { roles: string[]; children: ReactNode }) {
  const { admin, isSuperAdmin } = useAuth();
  const { t } = useI18n();

  if (!roleAllowed(roles, admin?.role, isSuperAdmin)) {
    return <PageState icon="shield" title={t('warning.shell.accessRestricted')} description={t('warning.common.noAccessText')} />;
  }

  return <>{children}</>;
}
