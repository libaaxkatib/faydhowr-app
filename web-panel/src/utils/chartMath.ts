// Pure helpers behind the report charts (no React, no `@/` imports) so Node's test runner can import them. They only turn numbers the server already
// reports into bar widths, percentages and donut slices; they never invent, estimate or round a total.

// The seven chart colours are theme colours (tailwind.config.ts, with a lighter set for Dark Mode in index.css). The class names are written out in full
// so Tailwind can see them.
export const CHART_FILL = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4', 'bg-chart-5', 'bg-chart-6', 'bg-chart-7'] as const;
export const CHART_STROKE = ['stroke-chart-1', 'stroke-chart-2', 'stroke-chart-3', 'stroke-chart-4', 'stroke-chart-5', 'stroke-chart-6', 'stroke-chart-7'] as const;

const slot = (index: number) => Math.max(0, Math.floor(index)) % CHART_FILL.length;

/** The background class of the nth item of a chart: the seven colours in turn. */
export function chartFill(index: number): string {
  return CHART_FILL[slot(index)];
}

/** The stroke class of the nth slice of a ring: the same colour as `chartFill` for the same position. */
export function chartStroke(index: number): string {
  return CHART_STROKE[slot(index)];
}

/** Meaning-carrying colours, so a "problem" bar is red and a "good" one green whatever the position in the list. */
export const TONE_FILL = { danger: 'bg-danger', warning: 'bg-warning', success: 'bg-success', neutral: 'bg-ink-faint' } as const;
export const TONE_STROKE = { danger: 'stroke-danger', warning: 'stroke-warning', success: 'stroke-success', neutral: 'stroke-ink-faint' } as const;

export type ChartTone = keyof typeof TONE_FILL;

/** A number that is safe to draw: finite and not negative. */
export function drawable(value: number | null | undefined): number {
  return typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : 0;
}

/** Bar width in whole percent of the longest bar. A value above zero is always at least 3% wide so that it can be seen. */
export function barWidth(value: number, max: number): number {
  const v = drawable(value);
  const m = drawable(max);

  if (m === 0 || v === 0) return 0;

  return Math.min(100, Math.max(3, Math.round((v / m) * 100)));
}

/** The share of the total in whole percent (0 when there is nothing to share). It is only for the label; slices use the exact fraction. */
export function percentOf(value: number, total: number): number {
  const t = drawable(total);

  return t === 0 ? 0 : Math.round((drawable(value) / t) * 100);
}

/** The largest value in a list (0 for an empty list). */
export function maxOf(values: readonly number[]): number {
  return values.reduce((max, value) => Math.max(max, drawable(value)), 0);
}

export interface DonutSlice {
  /** Length of the slice along the circle. */
  length: number;
  /** How far round the circle it starts (negative, as `stroke-dashoffset` wants). */
  offset: number;
}

/** Donut geometry: the slices of a circle of the given radius for these values, end to end. A value of zero gets no slice. */
export function donutSlices(values: readonly number[], radius: number): DonutSlice[] {
  const circumference = 2 * Math.PI * radius;
  const total = values.reduce((sum, value) => sum + drawable(value), 0);
  let used = 0;

  return values.map((value) => {
    const length = total === 0 ? 0 : (drawable(value) / total) * circumference;
    const slice = { length, offset: -used };
    used += length;

    return slice;
  });
}

/**
 * A money amount as the server prints it ("1,234.50", "$1,234.50", "12.00 USD") as a number for drawing a bar. The text itself is always shown beside
 * the bar exactly as received; this is only for the bar's length, and 0 is returned for text that holds no number.
 */
export function moneyNumber(amount: string | number | null | undefined): number {
  // The server's amounts are typed as text but can arrive as plain numbers; both draw the same bar.
  if (typeof amount === 'number') return Number.isFinite(amount) ? amount : 0;
  if (!amount) return 0;

  const text = String(amount);
  const cleaned = text.replace(/[^0-9.,-]/g, '');
  // "1,234.50" -> comma groups thousands; "1.234,50" (comma decimal) -> the last separator is the decimal point.
  const lastComma = cleaned.lastIndexOf(',');
  const lastDot = cleaned.lastIndexOf('.');
  const normalised = lastComma > lastDot ? cleaned.replace(/\./g, '').replace(',', '.') : cleaned.replace(/,/g, '');
  const value = Number.parseFloat(normalised);

  return Number.isFinite(value) ? value : 0;
}

/** Plain-text summary of a chart for screen readers: "People by status: Active 12 (60%), Waiting 8 (40%)". */
export function chartSummary(title: string, items: readonly { label: string; value: number }[]): string {
  const total = items.reduce((sum, item) => sum + drawable(item.value), 0);
  const parts = items.map((item) => `${item.label} ${drawable(item.value)} (${percentOf(item.value, total)}%)`);

  return `${title}: ${parts.join(', ')}`;
}

/** A stored value written for a person when no translation exists: "contract_ended" becomes "Contract ended", "pending" becomes "Pending". */
export function titleCase(value: string): string {
  const spaced = value.replace(/_/g, ' ').trim();

  return spaced === '' ? spaced : spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
