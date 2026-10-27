import { useRef, type KeyboardEvent } from 'react';

import { LANGUAGE_OPTIONS } from '@/i18n/language';
import { useI18n } from '@/i18n/useI18n';

/**
 * English / Somali selector. A radio group like the theme selector: one tab stop, the arrow keys move between the two choices (Home and End jump to
 * the first and last), and each choice has a full name for screen readers, not only its two-letter code. Somali applies to the HR screens only;
 * everywhere else the panel stays English, so the label says so. It never reads or writes the colour theme.
 */
export function LanguageSwitcher({ className = '' }: { className?: string }) {
  const { language, setLanguage, t } = useI18n();
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);

  function choose(index: number) {
    const option = LANGUAGE_OPTIONS[(index + LANGUAGE_OPTIONS.length) % LANGUAGE_OPTIONS.length];
    setLanguage(option.value);
    buttons.current[LANGUAGE_OPTIONS.indexOf(option)]?.focus();
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const current = LANGUAGE_OPTIONS.findIndex((option) => option.value === language);
    const moves: Record<string, number> = { ArrowRight: current + 1, ArrowDown: current + 1, ArrowLeft: current - 1, ArrowUp: current - 1, Home: 0, End: LANGUAGE_OPTIONS.length - 1 };
    if (!(event.key in moves)) return;
    event.preventDefault();
    choose(moves[event.key]);
  }

  return (
    <div
      role="radiogroup"
      aria-label={t('option.common.languageLabel')}
      title={t('option.common.languageTitle')}
      data-language-switcher
      onKeyDown={onKeyDown}
      className={`inline-flex items-center rounded-sm border border-border bg-surface-muted p-0.5 ${className}`}
    >
      {LANGUAGE_OPTIONS.map((option, index) => {
        const selected = option.value === language;

        return (
          <button
            key={option.value}
            ref={(element) => {
              buttons.current[index] = element;
            }}
            type="button"
            role="radio"
            aria-checked={selected}
            aria-label={option.name}
            lang={option.value}
            tabIndex={selected ? 0 : -1}
            onClick={() => setLanguage(option.value)}
            className={`flex h-7 min-w-[1.75rem] items-center justify-center rounded-sm px-1.5 text-[11px] font-bold tracking-wide transition ${
              selected ? 'bg-surface text-primary shadow-card' : 'text-ink-muted hover:text-ink'
            }`}
          >
            {option.code}
          </button>
        );
      })}
    </div>
  );
}
