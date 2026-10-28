import type { ReactNode } from 'react';

import { useI18n } from '@/i18n/useI18n';

/** The scroll box's classes, for the few tables that build their own wrapper (see TableScroll). */
export const TABLE_SCROLL_CLASS =
  'max-h-[max(18rem,calc(100dvh-18rem))] overflow-auto focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary [&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10 [&_thead_th]:bg-surface-alt';

interface TableScrollProps {
  children: ReactNode;
  /** Names the scrollable area for screen readers; defaults to "Data table". */
  label?: string;
  className?: string;
}

/**
 * The one place a wide table scrolls. The table scrolls inside this box, never the whole page, and the box is no taller than the screen allows, so
 * its horizontal scrollbar is always on screen with the rows (without it a 15-row table put the scrollbar thousands of pixels down and the last
 * columns and row actions could not be reached). The header row stays visible while the rows scroll, and the box can be scrolled with the keyboard.
 */
export function TableScroll({ children, label, className = '' }: TableScrollProps) {
  const { t } = useI18n();

  return (
    <div
      role="region"
      aria-label={label ?? t('option.common.dataTable')}
      tabIndex={0}
      className={`${TABLE_SCROLL_CLASS} ${className}`}
    >
      {children}
    </div>
  );
}
