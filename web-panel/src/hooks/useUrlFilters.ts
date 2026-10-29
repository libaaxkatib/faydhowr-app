import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

import { activeFilterCount, pageFromParam, withParam, withoutFilters } from '@/utils/urlFilters';

/**
 * A list's search, filters and page held in the address bar (replace, not push, so typing does not fill the Back history). After opening a
 * record and pressing Back, a reload or a shared link, the list returns exactly as it was left. Changing a filter returns to page 1.
 */
export function useUrlFilters() {
  const [params, setParams] = useSearchParams();

  const get = (key: string, fallback = ''): string => params.get(key) ?? fallback;

  const set = useCallback(
    (key: string, value: string) => {
      setParams((previous) => withParam(previous, key, value), { replace: true });
    },
    [setParams],
  );

  const page = pageFromParam(params.get('page'));
  const setPage = useCallback((next: number) => set('page', next <= 1 ? '' : String(next)), [set]);

  /** Clears the given filters (and the page) in one step, keeping every other parameter. */
  const reset = useCallback(
    (keys: readonly string[]) => {
      setParams((previous) => withoutFilters(previous, keys), { replace: true });
    },
    [setParams],
  );

  /** How many of the given filters hold a value now. */
  const activeCount = (keys: readonly string[]): number => activeFilterCount(params, keys);

  return { get, set, page, setPage, reset, activeCount };
}
