import { useLocation } from 'react-router-dom';

import { PageState } from '@/components/ui/PageState';
import { useI18n } from '@/i18n/useI18n';

/**
 * Shown for an address the panel has no page for. The sidebar's planned Mobile App items point at addresses that have no page yet, and those keep the
 * "not built yet" wording; any other address is simply not found (a mistyped link should not claim a page is on its way).
 */
export function NotFoundPage() {
  const { pathname } = useLocation();
  const { t } = useI18n();
  const planned = pathname.startsWith('/mobile-app/');

  return (
    <PageState
      icon="alert-triangle"
      title={planned ? t('warning.shell.notBuiltTitle') : t('warning.shell.pageNotFoundTitle')}
      description={planned ? t('warning.shell.notBuiltText') : t('warning.shell.pageNotFoundText')}
    />
  );
}
