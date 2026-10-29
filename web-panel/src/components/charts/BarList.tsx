import { useState } from 'react';

import { useI18n } from '@/i18n/useI18n';
import { formatNumber } from '@/utils/formatters';
import { TONE_FILL, barWidth, chartFill, maxOf, type ChartTone } from '@/utils/chartMath';

export interface BarItem {
  key: string | number;
  label: string;
  value: number;
  /** The figure as it should be read (a money amount, "12 / 20"); defaults to the number. The bar is drawn from `value`, the text is shown as given. */
  valueText?: string;
  /** A second line under the bar ("20 places", "3 overdue"). */
  hint?: string;
  /** The bar is drawn against this instead of the longest bar (progress towards a target such as a workplace's capacity). */
  max?: number;
  tone?: ChartTone;
}

/**
 * Horizontal bars, one row per item: the name and the figure on one line, the bar under them. The figure is always written out beside the bar, so the
 * chart is readable (and works for screen readers and printing) without seeing the bar at all. Long lists show the first rows and a button for the rest.
 */
export function BarList({ items, label, limit = 8, colorByPosition = false, fillClass }: { items: readonly BarItem[]; label: string; limit?: number; colorByPosition?: boolean; fillClass?: string }) {
  const { t } = useI18n();
  const [expanded, setExpanded] = useState(false);
  const longest = maxOf(items.map((item) => item.value));
  const shown = expanded ? items : items.slice(0, limit);

  return (
    <div>
      <ul aria-label={label} className="space-y-2.5">
        {shown.map((item, index) => {
          const fill = item.tone ? TONE_FILL[item.tone] : colorByPosition ? chartFill(index) : (fillClass ?? chartFill(0));

          return (
            <li key={item.key}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate text-ink-muted" title={item.label}>
                  {item.label}
                </span>
                <span className="shrink-0 font-semibold text-ink">{item.valueText ?? formatNumber(item.value)}</span>
              </div>
              <div className="mt-1 h-2.5 overflow-hidden rounded-full bg-surface-alt" aria-hidden="true">
                <div className={`h-full rounded-full motion-safe:transition-[width] motion-safe:duration-500 ${fill}`} style={{ width: `${barWidth(item.value, item.max ?? longest)}%` }} />
              </div>
              {item.hint && <p className="mt-0.5 text-[11px] text-ink-faint">{item.hint}</p>}
            </li>
          );
        })}
      </ul>
      {items.length > limit && (
        <button
          type="button"
          aria-expanded={expanded}
          onClick={() => setExpanded((open) => !open)}
          className="mt-3 text-xs font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {expanded ? t('option.reports.showFewer') : t('option.reports.showAll', { count: items.length })}
        </button>
      )}
    </div>
  );
}
