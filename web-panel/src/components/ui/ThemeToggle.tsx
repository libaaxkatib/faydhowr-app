import { useRef, type KeyboardEvent } from 'react';

import { Icon, type IconName } from '@/components/ui/Icon';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { THEME_OPTIONS, type ThemeMode } from '@/theme/theme';
import { useTheme } from '@/theme/useTheme';

const OPTION_ICONS: Record<ThemeMode, IconName> = { light: 'sun', dark: 'moon', system: 'monitor' };
const OPTION_LABELS: Record<ThemeMode, MessageKey> = { light: 'option.theme.light', dark: 'option.theme.dark', system: 'option.theme.system' };

/**
 * Light / Dark / System selector. A radio group: one tab stop, the arrow keys move between the three choices (Home and End jump to the first
 * and last), and each choice has a text name for screen readers, not only an icon. Used in the header and on the sign-in page.
 */
export function ThemeToggle({ className = '' }: { className?: string }) {
  const { mode, setMode } = useTheme();
  const { t } = useI18n();
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);

  function choose(index: number) {
    const option = THEME_OPTIONS[(index + THEME_OPTIONS.length) % THEME_OPTIONS.length];
    setMode(option.value);
    buttons.current[THEME_OPTIONS.indexOf(option)]?.focus();
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const current = THEME_OPTIONS.findIndex((option) => option.value === mode);
    const moves: Record<string, number> = { ArrowRight: current + 1, ArrowDown: current + 1, ArrowLeft: current - 1, ArrowUp: current - 1, Home: 0, End: THEME_OPTIONS.length - 1 };
    if (!(event.key in moves)) return;
    event.preventDefault();
    choose(moves[event.key]);
  }

  return (
    <div
      role="radiogroup"
      aria-label={t('option.theme.group')}
      data-theme-toggle
      onKeyDown={onKeyDown}
      className={`inline-flex items-center rounded-sm border border-border bg-surface-muted p-0.5 ${className}`}
    >
      {THEME_OPTIONS.map((option, index) => {
        const selected = option.value === mode;

        return (
          <button
            key={option.value}
            ref={(element) => {
              buttons.current[index] = element;
            }}
            type="button"
            role="radio"
            aria-checked={selected}
            aria-label={t(OPTION_LABELS[option.value])}
            title={t(OPTION_LABELS[option.value])}
            tabIndex={selected ? 0 : -1}
            onClick={() => setMode(option.value)}
            className={`flex h-7 w-7 items-center justify-center rounded-sm transition ${
              selected ? 'bg-surface text-primary shadow-card' : 'text-ink-muted hover:text-ink'
            }`}
          >
            <Icon name={OPTION_ICONS[option.value]} size={15} />
          </button>
        );
      })}
    </div>
  );
}
