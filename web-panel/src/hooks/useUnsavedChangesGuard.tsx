import { type ReactNode, useEffect } from 'react';
import { useBlocker } from 'react-router-dom';

import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useI18n } from '@/i18n/useI18n';

/**
 * Protection for a FULL-PAGE form: while `active` (the form has unsaved changes and has not just been saved) leaving the page - a link, the
 * browser Back button, a reload or closing the tab - asks "Discard your changes?" first. Returns the dialog to render in the page.
 * Modals use the same rules through `<Modal confirmDiscard>`.
 */
export function useUnsavedChangesGuard(active: boolean): ReactNode {
  const blocker = useBlocker(active);
  const { t } = useI18n();

  useEffect(() => {
    if (!active) return;
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [active]);

  return (
    <ConfirmDialog
      isOpen={blocker.state === 'blocked'}
      title={t('confirm.discard.title')}
      description={t('confirm.discard.text')}
      confirmLabel={t('confirm.discard.discard')}
      tone="danger"
      onCancel={() => blocker.reset?.()}
      onConfirm={() => blocker.proceed?.()}
    />
  );
}
