import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { GenerationWarningsPanel } from '@/features/hrm/GenerationWarningsPanel';
import { useI18n } from '@/i18n/useI18n';

/**
 * The confirmation of "Mark reviewed" and "Finalize" for ONE company. Above the buttons it lists the company's Temporary Replacement warnings, read from the
 * server when it opens. The warnings never block: there is no extra checkbox and the confirm button stays available whatever they say.
 */
export function SectionActionDialog({
  period,
  company,
  title,
  description,
  confirmLabel,
  tone = 'primary',
  isLoading,
  onConfirm,
  onCancel,
}: {
  period: string;
  /** The company's id, or null for the "no company" lines. */
  company: number | null;
  title: string;
  description: string;
  confirmLabel: string;
  tone?: 'primary' | 'danger';
  isLoading: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const { t } = useI18n();

  return (
    <Modal
      isOpen
      onClose={isLoading ? () => undefined : onCancel}
      title={title}
      size="md"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={isLoading}>{t('action.cancel')}</Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} size="sm" onClick={onConfirm} isLoading={isLoading} data-testid="section-action-confirm">
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="text-sm text-ink-muted">{description}</p>
      <GenerationWarningsPanel period={period} company={company} />
    </Modal>
  );
}
