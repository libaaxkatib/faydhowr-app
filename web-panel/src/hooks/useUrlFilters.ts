import { useCallback } from 'react';

import { useUrlParamUpdater } from '@/hooks/useUrlParamUpdater';
import { activeFilterCount, pageFromParam, withParam, withoutFilters } from '@/utils/urlFilters';

/**
 * A list's search, filters and page held in the address bar (replace, not push, so typing does not fill the Back history). After opening a
 * record and pressing Back, a reload or a shared link, the list returns exactly as it was left. Changing a filter returns to page 1, and several changes in one event (a filter, then the page) all take effect.
 */
export function useUrlFilters() {
  const [params, update] = useUrlParamUpdater();

  const get = (key: string, fallback = ''): string => params.get(key) ?? fallback;

  const set = useCallback(
    (key: string, value: string) => {
      update((previous) => withParam(previous, key, value));
    },
    [update],
  );

  const page = pageFromParam(params.get('page'));
  const setPage = useCallback((next: number) => set('page', next <= 1 ? '' : String(next)), [set]);

  /** Clears the given filters (and the page) in one step, keeping every other parameter. */
  const reset = useCallback(
    (keys: readonly string[]) => {
      update((previous) => withoutFilters(previous, keys));
    },
    [update],
  );

  /** How many of the given filters hold a value now. */
  const activeCount = (keys: readonly string[]): number => activeFilterCount(params, keys);

  return { get, set, page, setPage, reset, activeCount };
}
