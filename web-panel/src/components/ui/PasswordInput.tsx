import { forwardRef, useState, type InputHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { inputClasses } from '@/components/ui/FormField';
import { useI18n } from '@/i18n/useI18n';
import { passwordInputType, toggledVisibility } from '@/utils/passwordVisibility';

type PasswordInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>;

/**
 * The one password field of the panel: hidden by default, with an eye button that shows or hides what was typed.
 *
 *  - The button is `type="button"`, so it can never submit the form it sits in, and it keeps focus on the field (mouse-down is not allowed to move it), so the
 *    caret and the typed text stay exactly as they were. It only flips the input between `password` and `text`; the value, `name`, `autoComplete` and every
 *    other attribute are passed through untouched, so password managers and autofill keep working.
 *  - It is reachable by keyboard, announces its state (`aria-pressed`) and names itself in the active language.
 *  - Nothing is logged and nothing leaves the component: it holds one boolean, never the password.
 */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput({ className, style, disabled, ...rest }, ref) {
  const { t } = useI18n();
  const [visible, setVisible] = useState(false);

  return (
    <div className="relative">
      <input ref={ref} {...rest} disabled={disabled} type={passwordInputType(visible)} className={className ?? inputClasses} style={{ paddingInlineEnd: '2.75rem', ...style }} />
      <button
        type="button"
        onClick={() => setVisible(toggledVisibility)}
        onMouseDown={(event) => event.preventDefault()}
        disabled={disabled}
        aria-label={visible ? t('action.shell.hidePassword') : t('action.shell.showPassword')}
        aria-pressed={visible}
        aria-controls={rest.id}
        data-testid="password-visibility-toggle"
        className="absolute inset-y-0 end-0 flex w-10 items-center justify-center rounded-sm text-ink-muted transition hover:text-ink focus-visible:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
      >
        <Icon name={visible ? 'eye-off' : 'eye'} size={18} aria-hidden="true" />
      </button>
    </div>
  );
});
