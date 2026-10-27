import { Icon } from '@/components/ui/Icon';
import { useI18n } from '@/i18n/useI18n';

export function LoadingState({ label }: { label?: string }) {
  const { t } = useI18n();

  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-ink-muted">
      <Icon name="loader" size={22} className="text-primary" />
      <p className="text-sm">{label ?? t('option.label.loading')}</p>
    </div>
  );
}
