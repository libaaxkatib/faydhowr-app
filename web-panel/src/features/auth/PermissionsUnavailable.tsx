import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { useI18n } from '@/i18n/useI18n';

/**
 * Shown when the list of what this admin may do could not be fetched (network or server trouble). It is NOT an "Access restricted" page: the person may
 * well have access, we just could not check yet. A retry asks again.
 */
export function PermissionsUnavailable({ onRetry }: { onRetry: () => void }) {
  const { t } = useI18n();

  return (
    <div role="alert" className="flex min-h-[50vh] flex-col items-center justify-center gap-3 px-6 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-warning-soft text-warning">
        <Icon name="alert-triangle" size={24} />
      </div>
      <h1 className="font-display text-xl font-bold text-ink">{t('warning.shell.permissionsUnavailableTitle')}</h1>
      <p className="max-w-sm text-sm text-ink-muted">{t('warning.shell.permissionsUnavailableText')}</p>
      <Button variant="outline" size="sm" onClick={onRetry}>
        <Icon name="refresh" size={14} />
        {t('action.common.tryAgain')}
      </Button>
    </div>
  );
}
