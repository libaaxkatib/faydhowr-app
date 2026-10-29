import { formatNumber } from '@/utils/formatters';
import { TONE_FILL, TONE_STROKE, chartFill, chartStroke, chartSummary, donutSlices, drawable, percentOf, type ChartTone } from '@/utils/chartMath';

export interface ShareItem {
  key: string | number;
  label: string;
  value: number;
  tone?: ChartTone;
  /** A fixed place in the colour palette, so the same item keeps its colour wherever it sits in the list. */
  colorIndex?: number;
}

const fillOf = (item: ShareItem, index: number) => (item.tone ? TONE_FILL[item.tone] : chartFill(item.colorIndex ?? index));
const strokeOf = (item: ShareItem, index: number) => (item.tone ? TONE_STROKE[item.tone] : chartStroke(item.colorIndex ?? index));

/** The key of a share chart: a colour dot, the name, the figure and its percentage, for every item. This list is the text version of the chart. */
function Legend({ items, total, label }: { items: readonly ShareItem[]; total: number; label: string }) {
  return (
    <ul aria-label={label} className="space-y-1.5 text-sm">
      {items.map((item, index) => (
        <li key={item.key} className="flex items-center justify-between gap-3">
          <span className="flex min-w-0 items-center gap-2 text-ink-muted">
            <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${fillOf(item, index)}`} aria-hidden="true" />
            <span className="truncate" title={item.label}>
              {item.label}
            </span>
          </span>
          <span className="shrink-0 font-semibold text-ink">
            {formatNumber(item.value)} <span className="font-normal text-ink-faint">{percentOf(item.value, total)}%</span>
          </span>
        </li>
      ))}
    </ul>
  );
}

const keep = (items: readonly ShareItem[]) => items.filter((item) => drawable(item.value) > 0);

/** One long bar split into the shares of a whole (men and women, the pipeline of a month ...), with the key under it. */
export function ShareBar({ items, label }: { items: readonly ShareItem[]; label: string }) {
  const parts = keep(items);
  const total = parts.reduce((sum, item) => sum + item.value, 0);

  return (
    <div>
      <div
        role="img"
        aria-label={chartSummary(label, parts)}
        className="mb-3 flex h-4 w-full overflow-hidden rounded-full bg-surface-alt"
      >
        {parts.map((item, index) => (
          <div key={item.key} className={`h-full ${fillOf(item, index)}`} style={{ width: `${(item.value / total) * 100}%` }} />
        ))}
      </div>
      <Legend items={parts} total={total} label={label} />
    </div>
  );
}

/** A ring split into the shares of a whole, with the total in the middle and the key beside it (under it on a narrow screen). */
export function DonutChart({ items, label, centerLabel }: { items: readonly ShareItem[]; label: string; centerLabel: string }) {
  const parts = keep(items);
  const total = parts.reduce((sum, item) => sum + item.value, 0);
  const radius = 42;
  const slices = donutSlices(parts.map((item) => item.value), radius);
  const circumference = 2 * Math.PI * radius;

  return (
    <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
      <div className="relative h-36 w-36 shrink-0">
        <svg viewBox="0 0 100 100" className="h-full w-full -rotate-90" role="img" aria-label={chartSummary(label, parts)}>
          <circle cx="50" cy="50" r={radius} fill="none" strokeWidth="14" className="stroke-surface-alt" />
          {parts.map((item, index) => (
            <circle
              key={item.key}
              cx="50"
              cy="50"
              r={radius}
              fill="none"
              strokeWidth="14"
              strokeDasharray={`${slices[index].length} ${circumference - slices[index].length}`}
              strokeDashoffset={slices[index].offset}
              className={strokeOf(item, index)}
            />
          ))}
        </svg>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className="font-display text-xl font-bold text-ink">{formatNumber(total)}</span>
          <span className="text-[11px] text-ink-muted">{centerLabel}</span>
        </div>
      </div>
      <div className="w-full min-w-0 flex-1">
        <Legend items={parts} total={total} label={label} />
      </div>
    </div>
  );
}
