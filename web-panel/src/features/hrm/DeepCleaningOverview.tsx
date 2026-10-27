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
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { withParam } from '@/utils/urlFilters';
import type { FacilityDeepCleaningState } from '@/types/facility';

const FILTERS: { value: FacilityDeepCleaningState | ''; labelKey: MessageKey }[] = [
  { value: '', labelKey: 'option.hr.dcState.all' },
  { value: 'completed', labelKey: 'option.hr.dcState.completed' },
  { value: 'not_completed', labelKey: 'option.hr.dcState.not_completed' },
  { value: 'overdue_multiple', labelKey: 'option.hr.dcState.overdue_multiple' },
  { value: 'no_history', labelKey: 'option.hr.dcState.no_history' },
];

/** The sentence the server sends as the basis of the overview; shown in Somali only when it is exactly this text. */
const SERVER_BASIS = 'Built only from Deep Cleaning completions recorded inside facility inspections. Nothing is assumed for months with no record.';

/**
 * The monthly Deep Cleaning overview inside Facility Inspections: for the chosen calendar month, which facilities were deep cleaned, which were
 * not, which have gone several months without one and which have never had one recorded - from recorded completions only. Every state is
 * written out in words (colour is only an extra), and the filters live in the address bar so the view can be shared and survives a refresh.
 */
export function DeepCleaningOverview({ canManage, onRecord }: { canManage: boolean; onRecord: (companyId: number) => void }) {
  const { t, tCount, tValue } = useI18n();
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
  const basis = meta === undefined ? t('option.hr.dcBasisShort') : meta.basis === SERVER_BASIS ? t('option.hr.dcBasisFull') : meta.basis;

  return (
    <section className="mb-6" aria-labelledby="deep-cleaning-overview-heading" data-testid="deep-cleaning-overview">
      <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 id="deep-cleaning-overview-heading" className="font-display text-base font-bold text-ink">{t('option.hr.dcMonthly')}{month ? ` - ${monthLabel(month)}` : ''}</h2>
          <p className="text-xs text-ink-muted">{basis} {t('option.hr.dcTimeZone')}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="text-xs font-medium text-ink-muted" htmlFor="dc-month">{t('option.label.month')}</label>
          <input id="dc-month" type="month" value={month} max={meta?.current_month} onChange={(event) => setParam('dcMonth', event.target.value)} className={`${inputClasses} w-40`} />
          <input value={search} onChange={(event) => setParam('dcQ', event.target.value)} placeholder={t('option.hr.searchFacilityPlaceholder')} aria-label={t('option.hr.searchFacility')} className={`${inputClasses} w-44`} />
        </div>
      </div>

      <div role="group" aria-label={t('option.hr.dcFilterByState')} className="mb-3 flex flex-wrap items-center gap-2">
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
              {t(option.labelKey)} {count !== undefined && <span className={active ? 'text-white/80' : 'text-ink-faint'}>({count})</span>}
            </button>
          );
        })}
        <label className="ml-auto flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={includeInactive} onChange={(event) => setParam('dcInactive', event.target.checked ? '1' : '')} />
          {t('option.hr.includeInactiveFacilities')}
        </label>
      </div>

      {meta !== undefined && !includeInactive && meta.inactive_hidden > 0 && (
        <p className="mb-2 text-xs text-ink-muted" data-testid="deep-cleaning-inactive-note">
          {tCount('warning.hr.dcInactiveHidden', meta.inactive_hidden)}
        </p>
      )}

      {/* While a new month or search loads, the previous result stays on screen; say so, so it is never mistaken for the new month. */}
      <p role="status" aria-live="polite" className={`mb-2 text-xs text-ink-muted ${overview.isFetching && overview.isPlaceholderData ? '' : 'sr-only'}`}>
        {overview.isFetching && overview.isPlaceholderData ? t('option.hr.updating') : ''}
      </p>

      <Card className={overview.isFetching && overview.isPlaceholderData ? 'opacity-60' : undefined}>
        {overview.isLoading && <LoadingState label={t('option.hr.loadingDeepCleaning')} />}
        {overview.error && <ErrorState error={overview.error} onRetry={overview.refetch} />}
        {overview.data && rows.length === 0 && (
          <EmptyState
            icon="eye"
            title={meta?.counts.total === 0 ? t('option.hr.dcNoFacilities') : t('option.hr.dcNoFacilitiesMatch')}
            description={meta?.counts.total === 0 ? t('option.hr.dcNoFacilitiesHint') : t('option.hr.dcChangeFilterHint')}
          />
        )}
        {overview.data && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">{t('option.hr.dcTableCaption', { month: month ? monthLabel(month) : t('option.hr.dcSelectedMonth') })}</caption>
              <thead className="border-b border-border text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                  <th scope="col" className="px-4 py-2.5 font-semibold">{t('option.label.companyGuri')}</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">{t('option.hr.dcColumnThisMonth')}</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">{t('option.hr.dcColumnLast')}</th>
                  <th scope="col" className="px-4 py-2.5 font-semibold">{t('option.hr.dcColumnLastSix')}</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((row) => (
                  <tr key={row.client_company_id}>
                    <td className="px-4 py-2.5 font-medium text-ink">
                      {row.company}
                      {row.category === 'guri' && <span className="ml-1.5 text-xs font-normal text-ink-faint">{t('option.company.filterGuri')}</span>}
                      {row.facility_status === 'inactive' && <span className="ml-1.5 text-xs font-normal text-ink-faint">{t('status.inactive')}</span>}
                    </td>
                    <td className="px-4 py-2.5">
                      <StatusBadge status={row.state} label={tValue('option.hr.dcState', row.state) ?? row.state_label} tone={deepCleaningStateTone(row.state)} />
                      <span className="mt-0.5 block text-xs text-ink-muted">{deepCleaningGapText(row)}</span>
                    </td>
                    <td className="px-4 py-2.5 text-ink">{row.last_completed_on ? formatCalendarDate(row.last_completed_on) : <span className="text-ink-faint">{t('status.hr.dcNeverRecorded')}</span>}</td>
                    <td className="px-4 py-2.5">
                      <ul className="flex gap-1" aria-label={t('option.hr.dcLastSixAria', { company: row.company })}>
                        {row.history.map((m) => (
                          <li
                            key={m.month}
                            title={m.completed ? t('option.hr.dcMonthDone', { month: monthLabel(m.month), date: formatCalendarDate(m.completed_on ?? '') }) : t('option.hr.dcMonthNone', { month: monthLabel(m.month) })}
                            className={`rounded-sm border px-1.5 py-0.5 text-center text-[11px] leading-tight ${m.completed ? 'border-success/40 bg-success-soft text-success' : 'border-border bg-surface-alt text-ink-faint'}`}
                          >
                            {shortMonth(m.month)}
                            <span className="block font-semibold">{m.completed ? t('status.hr.dcDone') : t('status.hr.dcNone')}</span>
                          </li>
                        ))}
                      </ul>
                    </td>
                    <td className="px-4 py-2.5">
                      {canManage && (
                        <div className="flex justify-end">
                          <Button size="sm" variant="outline" onClick={() => onRecord(row.client_company_id)}>{t('action.facility.recordInspection')}</Button>
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
