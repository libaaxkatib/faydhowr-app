// Pure helper for the page buttons under a list (no React, no `@/` imports) so Node's test runner can import it.

export type PageItem = number | 'gap';

/**
 * The page buttons to show: the first and last page, the current page and one either side, with a gap marker where pages are skipped.
 * Up to seven pages are all shown. The result never repeats a page and never marks a gap that hides a single page (that page is shown instead).
 */
export function pageWindow(current: number, last: number): PageItem[] {
  const total = Math.max(1, Math.floor(last));
  const at = Math.min(Math.max(1, Math.floor(current)), total);

  if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1);

  const wanted = new Set([1, total, at - 1, at, at + 1]);
  // Near either end keep the window a constant width so the buttons do not jump around while paging.
  if (at <= 3) [2, 3, 4].forEach((page) => wanted.add(page));
  if (at >= total - 2) [total - 3, total - 2, total - 1].forEach((page) => wanted.add(page));

  const pages = [...wanted].filter((page) => page >= 1 && page <= total).sort((a, b) => a - b);
  const items: PageItem[] = [];
  pages.forEach((page, index) => {
    const previous = pages[index - 1];
    if (previous !== undefined && page - previous === 2) items.push(previous + 1);
    else if (previous !== undefined && page - previous > 2) items.push('gap');
    items.push(page);
  });

  return items;
}

/** The page number typed into "Go to page": a whole number inside 1..last, otherwise null (the box stays as typed and nothing happens). */
export function parsePageInput(value: string, last: number): number | null {
  const text = value.trim();
  if (!/^\d+$/.test(text)) return null;
  const page = Number(text);

  return page >= 1 && page <= Math.max(1, last) ? page : null;
}
