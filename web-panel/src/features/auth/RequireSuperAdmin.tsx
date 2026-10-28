import type { ReactNode } from 'react';

import { PageState } from '@/components/ui/PageState';
import { useAuth } from '@/features/auth/useAuth';
import { useI18n } from '@/i18n/useI18n';

/**
 * Route guard for a page reserved for Super Admin. UX only: the backend checks the role itself on every request this page makes (the `super_admin`
 * middleware) and refuses anyone else, whatever this renders.
 */
export function RequireSuperAdmin({ children }: { children: ReactNode }) {
  const { isSuperAdmin } = useAuth();
  const { t } = useI18n();

  if (!isSuperAdmin) {
    return <PageState icon="shield" title={t('warning.shell.accessRestricted')} description={t('warning.common.noAccessText')} />;
  }

  return <>{children}</>;
}
