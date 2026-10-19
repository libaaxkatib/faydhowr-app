import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';

interface ConfirmDialogProps {
  isOpen: boolean;
  title: string;
  description: string;
  confirmLabel?: string;
  tone?: 'primary' | 'danger';
  isLoading?: boolean;
  /** Shown inside the dialog (and announced) when the action was refused or failed, so the explanation does not vanish like a toast. */
  errorMessage?: string | null;
  onConfirm: () => void;
  onCancel: () => void;
}

export function ConfirmDialog({
  isOpen,
  title,
  description,
  confirmLabel = 'Confirm',
  tone = 'primary',
  isLoading,
  errorMessage,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={onCancel}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={isLoading}>
            Cancel
          </Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} size="sm" onClick={onConfirm} isLoading={isLoading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="text-sm text-ink-muted">{description}</p>
      {errorMessage && (
        <p role="alert" className="mt-3 rounded-sm bg-danger-soft px-3 py-2 text-sm text-danger">
          {errorMessage}
        </p>
      )}
    </Modal>
  );
}
