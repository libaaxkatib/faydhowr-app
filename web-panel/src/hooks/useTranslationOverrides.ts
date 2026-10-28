import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';

import { translationOverridesApi } from '@/api/translationOverrides';
import { setOverrides } from '@/i18n/overrides';

export const TRANSLATION_OVERRIDES_QUERY_KEY = ['i18n', 'overrides'] as const;

/**
 * Loads the wording Super Admin has edited and lays it over the built-in text for the whole panel. Mounted once, in the signed-in layout. A failed
 * request leaves the built-in wording showing, which is always a complete, working text.
 */
export function useLoadTranslationOverrides(): void {
  const { data } = useQuery({
    queryKey: TRANSLATION_OVERRIDES_QUERY_KEY,
    queryFn: () => translationOverridesApi.list(),
    staleTime: 60_000,
    retry: 1,
  });

  useEffect(() => {
    if (data) setOverrides(data);
  }, [data]);
}
