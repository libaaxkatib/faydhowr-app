import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';

import { facilityApi } from '@/api/facility';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorState } from '@/components/ui/ErrorState';
import { inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { deepCleaningGapText, deepCleaningStateTone, formatCalendarDate, monthLabel, shortMonth } from '@/utils/facility';
import { withParam } from '@/utils/urlFilters';
import type { FacilityDeepCleaningState } from '@/types/facility';

const FILTERS: { value: FacilityDeepCleaningState | ''; label: string }[] = [
  { value: '', label: 'All facilities' },
  { value: 'completed', label: 'Completed this month' },
  { value: 'not_completed', label: 'Not completed this month' },
  { value: 'overdue_multiple', label: 'Overdue for several months' },
  { value: 'no_history', label: 'No Deep Cleaning recorded' },
];

/**
 * The monthly Deep Cleaning overview inside Facility Inspections: for the chosen calendar month, which facilities were deep cleaned, which were
 * not, which have gone several months without one and which have never had one recorded - from recorded completions only. Every state is
 * written out in words (colour is only an extra), and the filters live in the address bar so the view can be shared and survives a refresh.
 */
export function DeepCleaningOverview({ canManage, onRecord }: { canManage: boolean; onRecord: (companyId: number) => void }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const monthParam = /^\d{4}-(0[1-9]|1[0-2])$/.test(searchParams.get('dcMonth') ?? '') ? (searchParams.get('dcMonth') as string) : '';
  const stateParam = FILTERS.some((f) => f.value === searchParams.get('dcState')) ? ((searchParams.get('dcState') ?? '') as FacilityDeepCleaningState | '') : '';
  const search = searchParams.get('dcQ') ?? '';
  const includeInactive = searchParams.get('dcInactive') === '1';
  const setParam = (key: string, value: string) => setSearchParams((previous) => withParam(previous, key, value), { replace: true });

  // The month is left out until the user picks one, so the server decides what "this month" is; its answer is what the page then shows.
  const overview = useQuery({
    queryKey: ['facility-deep-cleaning', monthParam, search, includeInactive],
    queryFn: () => facilityApi.deepCleaning({ month: monthParam || undefined, search: search.trim() || undefined, include_inactive: includeInactive }),
    placeholderData: (previous) => previous,
  });
  const meta = overview.data?.meta;
  const rows = (overview.data?.rows ?? []).filter((row) => stateParam === '' || row.state === stateParam);
  const month = meta?.month.value ?? monthParam;

  return (
    <section className="mb-6" aria-labelledby="deep-cleaning-overview-heading" data-testid="deep-cleaning-overview">
      <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 id="deep-cleaning-overview-heading" className="font-display text-base font-bold text-ink">Monthly Deep Cleaning{month ? ` - ${monthLabel(month)}` : ''}</h2>
          <p className="text-xs text-ink-muted">{meta?.basis ?? 'Built only from Deep Cleaning completions recorded inside facility inspections.'} Months and dates follow Mogadishu time (UTC+3).</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="text-xs font-medium text-ink-muted" htmlFor="dc-month">Month</label>
          <input id="dc-month" type="month" value={month} max={meta?.current_month} onChange={(event) => setParam('dcMonth', event.target.value)} className={`${inputClasses} w-40`} />
          <input value={search} onChange={(event) => setParam('dcQ', event.target.value)} placeholder="Search facility…" aria-label="Search facility" className={`${inputClasses} w-44`} />
        </div>
      </div>

      <div role="group" aria-label="Filter by Deep Cleaning state" className="mb-3 flex flex-wrap items-center gap-2">
        {FILTERS.map((option) => {
          const active = stateParam === option.value;
          const count = meta === undefined ? undefined : option.value === '' ? meta.counts.total : meta.counts[option.value];

          return (
            <button
              key={option.value || 'all'}
              type="button"
              aria-pressed={active}
              onClick={() => setParam('dcState', option.value)}
              className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 ${
                active ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-ink-muted hover:border-primary/40 hover:text-primary'
              }`}
            >
              {option.label} {count !== undefined && <span className={active ? 'text-white/80' : 'text-ink-faint'}>({count})</span>}
            </button>
          );
        })}
        <label className="ml-auto flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={includeInactive} onChange={(event) => setParam('dcInactive', event.target.checked ? '1' : '')} />
          Include inactive facilities
        </label>
      </div>

      {meta !== undefined && !includeInactive && meta.inactive_hidden > 0 && (
        <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-inactive-note">
          {meta.inactive_hidden} inactive {meta.inactive_hidden === 1 ? 'facility is' : 'facilities are'} not listed (nothing recorded for this month). Tick "Include inactive facilities" to see them.
        </p>
      )}

      {/* While a new month or search loads, the previous result stays on screen; say so, so it is never mistaken for the new month. */}
      <p role="status" aria-live="polite" className={`mb-2 text-xs text-ink-muted ${overview.isFetching && overview.isPlaceholderData ? '' : 'sr-only'}`}>
        {overview.isFetching && overview.isPlaceholderData ? 'Updating…' : ''}
      </p>

      <Card className={overview.isFetching && overview.isPlaceholderData ? 'opacity-60' : undefined}>
        {overview.isLoading && <LoadingState label="Loading Deep Cleaning…" />}
        {overview.error && <ErrorState error={overview.error} onRetry={overview.refetch} />}
        {overview.data && rows.length === 0 && (
          <EmptyState
            icon="eye"
            title={meta?.counts.total === 0 ? 'No facilities for this month' : 'No facilities match'}
            description={meta?.counts.total === 0 ? 'Only facilities registered by the end of the month are listed. Change the month or the search.' : 'Change the filter or the search.'}
          />
        )}
        {overview.data && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Deep Cleaning status of each facility for {month ? monthLabel(month) : 'the selected month'}</caption>
              <thead className="border-b border-border text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                  <th scope="col" className="px-4 py-2.5 font-semibold">Company / Guri</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">This month</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">Last Deep Cleaning</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">Last 6 months</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((row) => (
                  <tr key={row.client_company_id}>
                    <td className="px-4 py-2.5 font-medium text-ink">
                      {row.company}
                      {row.category === 'guri' && <span className="ml-1.5 text-xs font-normal text-ink-faint">Guri</span>}
                      {row.facility_status === 'inactive' && <span className="ml-1.5 text-xs font-normal text-ink-faint">Inactive</span>}
                    </td>
                    <td className="px-4 py-2.5">
                      <StatusBadge status={row.state} label={row.state_label} tone={deepCleaningStateTone(row.state)} />
                      <span className="mt-0.5 block text-xs text-ink-muted">{deepCleaningGapText(row)}</span>
                    </td>
                    <td className="px-4 py-2.5 text-ink">{row.last_completed_on ? formatCalendarDate(row.last_completed_on) : <span className="text-ink-faint">Never recorded</span>}</td>
                    <td className="px-4 py-2.5">
                      <ul className="flex gap-1" aria-label={`Last six months for ${row.company}`}>
                        {row.history.map((m) => (
                          <li
                            key={m.month}
                            title={`${monthLabel(m.month)}: ${m.completed ? `done on ${formatCalendarDate(m.completed_on ?? '')}` : 'not recorded'}`}
                            className={`rounded-sm border px-1.5 py-0.5 text-center text-[11px] leading-tight ${m.completed ? 'border-success/40 bg-success-soft text-success' : 'border-border bg-surface-alt text-ink-faint'}`}
                          >
                            {shortMonth(m.month)}
                            <span className="block font-semibold">{m.completed ? 'Done' : 'None'}</span>
                          </li>
                        ))}
                      </ul>
                    </td>
                    <td className="px-4 py-2.5">
                      {canManage && (
                        <div className="flex justify-end">
                          <Button size="sm" variant="outline" onClick={() => onRecord(row.client_company_id)}>Record inspection</Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </section>
  );
}
