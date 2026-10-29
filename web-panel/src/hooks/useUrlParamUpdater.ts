import { useCallback, useEffect, useRef } from 'react';
import { useSearchParams } from 'react-router-dom';

// The change made in the current event that the router has not rendered yet. It is shared by every hook instance on the page, because a handler can
// change the date range (one hook) and then the page number (another) in the same event.
let pending: { from: string; next: URLSearchParams } | null = null;

/**
 * The address-bar parameters and a way to change them that composes. react-router's own functional `setSearchParams` hands every updater the parameters of
 * the render the event started in, so two changes made in one event (a filter and then "back to page 1") overwrite each other and only the last survives,
 * which left a search box that would not take typing. Here each change is applied to the result of the one before it, until the router has rendered the
 * outcome. Replace, not push, so typing does not fill the Back history.
 */
export function useUrlParamUpdater(): [URLSearchParams, (update: (previous: URLSearchParams) => URLSearchParams) => void] {
  const [params, setParams] = useSearchParams();
  const rendered = useRef(params);
  rendered.current = params;

  // once the router has rendered the new address, the next event starts from it
  useEffect(() => {
    pending = null;
  }, [params]);

  const update = useCallback(
    (change: (previous: URLSearchParams) => URLSearchParams) => {
      const shown = rendered.current;
      const base = pending !== null && pending.from === shown.toString() ? pending.next : shown;
      const next = change(base);
      pending = { from: shown.toString(), next };
      setParams(next, { replace: true });
    },
    [setParams],
  );

  return [params, update];
}
