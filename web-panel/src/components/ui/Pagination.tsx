import { useState } from 'react';

import type { PageMeta } from '@/types/api';
import { Icon } from '@/components/ui/Icon';
import { Msg } from '@/i18n/Msg';
import { useI18n } from '@/i18n/useI18n';
import { pageWindow, parsePageInput } from '@/utils/pageWindow';

interface PaginationProps {
  meta: PageMeta;
  onPageChange: (page: number) => void;
}

const BUTTON = 'flex h-8 min-w-8 items-center justify-center rounded-sm border px-2 text-xs font-medium';
const ARROW = `${BUTTON} border-border text-ink-muted hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-40`;

/**
 * The strip under a list: which rows are shown, previous / next, numbered page buttons (first, last and the pages around the current one) and, once
 * there are more than seven pages, a box to type a page number. Every list in the panel uses it.
 */
export function Pagination({ meta, onPageChange }: PaginationProps) {
  const { t } = useI18n();
  const { current_page, last_page, total, per_page } = meta;
  const from = total === 0 ? 0 : (current_page - 1) * per_page + 1;
  const to = Math.min(current_page * per_page, total);
  const [typed, setTyped] = useState('');
  const target = parsePageInput(typed, last_page);

  const jump = () => {
    if (target !== null && target !== current_page) onPageChange(target);
    setTyped('');
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border px-5 py-3">
      <p className="text-xs text-ink-muted">
        <Msg
          id="warning.common.showingRange"
          values={{
            from: <span className="font-medium text-ink">{from}</span>,
            to: <span className="font-medium text-ink">{to}</span>,
            total: <span className="font-medium text-ink">{total}</span>,
          }}
        />
      </p>
      <nav aria-label={t('option.common.pagination')} className="flex flex-wrap items-center gap-1">
        <button
          type="button"
          aria-label={t('option.system.previous')}
          disabled={current_page <= 1}
          onClick={() => onPageChange(current_page - 1)}
          className={ARROW}
        >
          <Icon name="chevron-left" size={14} />
        </button>
        {pageWindow(current_page, last_page).map((item, index) =>
          item === 'gap' ? (
            <span key={`gap-${index}`} aria-hidden="true" className="px-1 text-xs text-ink-faint">
              …
            </span>
          ) : (
            <button
              key={item}
              type="button"
              aria-label={t('option.common.pageNumber', { page: item })}
              aria-current={item === current_page ? 'page' : undefined}
              onClick={() => item !== current_page && onPageChange(item)}
              className={
                item === current_page
                  ? `${BUTTON} border-primary bg-primary text-white`
                  : `${BUTTON} border-border text-ink hover:bg-surface-alt`
              }
            >
              {item}
            </button>
          ),
        )}
        <button
          type="button"
          aria-label={t('option.system.next')}
          disabled={current_page >= last_page}
          onClick={() => onPageChange(current_page + 1)}
          className={ARROW}
        >
          <Icon name="chevron-right" size={14} />
        </button>
        {last_page > 7 && (
          <form
            className="ml-2 flex items-center gap-1"
            onSubmit={(event) => {
              event.preventDefault();
              jump();
            }}
          >
            <input
              type="text"
              inputMode="numeric"
              value={typed}
              onChange={(event) => setTyped(event.target.value)}
              aria-label={t('option.common.goToPage')}
              aria-invalid={typed.trim() !== '' && target === null}
              placeholder={t('option.common.goToPage')}
              className="h-8 w-24 rounded-sm border border-border bg-surface px-2 text-xs text-ink placeholder:text-ink-faint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary aria-[invalid=true]:border-danger"
            />
            <button type="submit" disabled={target === null} className={ARROW}>
              {t('action.common.go')}
            </button>
          </form>
        )}
      </nav>
    </div>
  );
}
