import type { PageMeta } from '@/types/api';
import { Icon } from '@/components/ui/Icon';

interface PaginationProps {
  meta: PageMeta;
  onPageChange: (page: number) => void;
}

export function Pagination({ meta, onPageChange }: PaginationProps) {
  const { current_page, last_page, total, per_page } = meta;
  const from = total === 0 ? 0 : (current_page - 1) * per_page + 1;
  const to = Math.min(current_page * per_page, total);

  return (
    <div className="flex items-center justify-between border-t border-border px-5 py-3">
      <p className="text-xs text-ink-muted">
        Showing <span className="font-medium text-ink">{from}</span>–<span className="font-medium text-ink">{to}</span> of{' '}
        <span className="font-medium text-ink">{total}</span>
      </p>
      <div className="flex items-center gap-1">
        <button
          type="button"
          disabled={current_page <= 1}
          onClick={() => onPageChange(current_page - 1)}
          className="flex h-8 w-8 items-center justify-center rounded-sm border border-border text-ink-muted hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-40"
        >
          <Icon name="chevron-left" size={14} />
        </button>
        <span className="px-3 text-xs font-medium text-ink">
          Page {current_page} of {Math.max(last_page, 1)}
        </span>
        <button
          type="button"
          disabled={current_page >= last_page}
          onClick={() => onPageChange(current_page + 1)}
          className="flex h-8 w-8 items-center justify-center rounded-sm border border-border text-ink-muted hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-40"
        >
          <Icon name="chevron-right" size={14} />
        </button>
      </div>
    </div>
  );
}
