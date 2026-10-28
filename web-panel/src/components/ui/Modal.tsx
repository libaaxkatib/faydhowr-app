import { createContext, type KeyboardEvent as ReactKeyboardEvent, type ReactNode, useCallback, useContext, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useBlocker } from 'react-router-dom';

import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { useI18n } from '@/i18n/useI18n';
import { decideClose } from '@/utils/unsavedChanges';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg';
  /**
   * Protects a form: once the person has changed any field inside, closing (cross, Escape, outside click, Cancel), pressing Back or reloading
   * asks "Discard your changes?" first. Nothing is asked when nothing changed or after a save (the parent closes the modal itself).
   */
  confirmDiscard?: boolean;
  /** For changes that are not typing (button choices, added or removed rows): the form tells the modal it differs from its starting point. */
  dirty?: boolean;
}

const sizeClasses = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' };

// Open modals, oldest first: Escape closes only the topmost one, so a shortcut dialog opened from a form does not also close the form.
const openModals: symbol[] = [];

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** The close handler of the modal a component sits in, so a footer's Cancel button goes through the same "discard changes?" check. */
const ModalCloseContext = createContext<(() => void) | null>(null);

/** Cancel button for a modal footer: closes like the cross does, asking first when the form has unsaved changes. */
export function ModalCancelButton({ children, disabled, onClose }: { children?: ReactNode; disabled?: boolean; onClose?: () => void }) {
  const request = useContext(ModalCloseContext);
  const { t } = useI18n();

  return (
    <Button variant="outline" size="sm" disabled={disabled} onClick={() => (request ? request() : onClose?.())}>
      {children ?? t('action.cancel')}
    </Button>
  );
}

export function Modal({ isOpen, onClose, title, children, footer, size = 'md', confirmDiscard = false, dirty = false }: ModalProps) {
  const { t } = useI18n();
  const titleId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const [touched, setTouched] = useState(false);
  const [asking, setAsking] = useState(false);
  const warn = decideClose({ protectedForm: confirmDiscard && isOpen, touched: touched || dirty }) === 'ask';

  // A new opening starts clean.
  useEffect(() => {
    if (!isOpen) {
      setTouched(false);
      setAsking(false);
    }
  }, [isOpen]);

  const requestClose = useCallback(() => {
    if (warn) setAsking(true);
    else onClose();
  }, [warn, onClose]);

  useEffect(() => {
    if (!isOpen) return;
    const id = Symbol('modal');
    openModals.push(id);
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && openModals[openModals.length - 1] === id) requestClose();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      openModals.splice(openModals.indexOf(id), 1);
    };
  }, [isOpen, requestClose]);

  // Keyboard users: focus moves into the dialog when it opens (first field, else the panel), Tab stays inside it, focus returns to the control that
  // opened it, and the page behind does not scroll.
  useEffect(() => {
    if (!isOpen) return;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const panel = panelRef.current;
    const firstField = panel?.querySelector<HTMLElement>('input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled])');
    (firstField ?? panel)?.focus({ preventScroll: true });
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previousOverflow;
      if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
    };
  }, [isOpen]);

  const keepFocusInside = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    const panel = panelRef.current;
    // React events also bubble out of a dialog opened from this one; only handle keys pressed inside this panel.
    if (event.key !== 'Tab' || !panel || !(event.target instanceof Node) || !panel.contains(event.target)) return;
    const items = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null);
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  // Reload / closing the tab while the form has unsaved changes.
  useEffect(() => {
    if (!warn) return;
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [warn]);

  // Browser Back / any in-app navigation while the form has unsaved changes.
  const blocker = useBlocker(warn);
  useEffect(() => {
    if (blocker.state === 'blocked') setAsking(true);
  }, [blocker.state]);

  if (!isOpen) return null;

  const stay = () => {
    setAsking(false);
    if (blocker.state === 'blocked') blocker.reset();
  };
  const discard = () => {
    setAsking(false);
    setTouched(false);
    if (blocker.state === 'blocked') blocker.proceed();
    else onClose();
  };

  // Rendered into <body>, not where the caller sits: a modal opened from inside a <form> (for example the "+ Create Position" shortcut)
  // must not have its own buttons submit that form.
  return createPortal(
    <ModalCloseContext.Provider value={requestClose}>
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <button type="button" aria-label={t('action.closeLabel')} className="absolute inset-0 cursor-default" onClick={requestClose} />
        {/* A change event bubbles up through React (portals included): this modal marks itself changed and stops it, so typing in a nested
            dialog does not mark the form underneath as changed. */}
        <div
          ref={panelRef}
          tabIndex={-1}
          onKeyDown={keepFocusInside}
          className={`relative w-full ${sizeClasses[size]} rounded-lg border border-border bg-surface shadow-card outline-none`}
          onChange={(event) => {
            if (confirmDiscard) setTouched(true);
            event.stopPropagation();
          }}
        >
          <div className="flex items-center justify-between border-b border-border px-5 py-4">
            <h2 id={titleId} className="font-display text-base font-bold text-ink">{title}</h2>
            <button
              type="button"
              aria-label={t('action.closeDialog')}
              onClick={requestClose}
              className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-ink"
            >
              <Icon name="x" size={16} />
            </button>
          </div>
          <div className="max-h-[70vh] overflow-y-auto px-5 py-5">{children}</div>
          {footer && <div className="flex justify-end gap-2 border-t border-border px-5 py-4">{footer}</div>}
        </div>

        {asking && (
          <div className="absolute inset-0 z-10 flex items-center justify-center bg-ink/30 px-4" data-testid="discard-changes">
            <div role="alertdialog" aria-modal="true" aria-labelledby="discard-title" className="w-full max-w-sm rounded-lg border border-border bg-surface p-5 shadow-card">
              <h3 id="discard-title" className="font-display text-base font-bold text-ink">{t('confirm.discard.title')}</h3>
              <p className="mt-2 text-sm text-ink-muted">{t('confirm.discard.text')}</p>
              <div className="mt-4 flex justify-end gap-2">
                <Button variant="outline" size="sm" onClick={stay} autoFocus>{t('confirm.discard.keep')}</Button>
                <Button variant="danger" size="sm" onClick={discard}>{t('confirm.discard.discard')}</Button>
              </div>
            </div>
          </div>
        )}
      </div>
    </ModalCloseContext.Provider>,
    document.body,
  );
}
