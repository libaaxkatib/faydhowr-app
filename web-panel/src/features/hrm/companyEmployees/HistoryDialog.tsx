import { useQuery } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { Modal } from '@/components/ui/Modal';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import type { CompanyCycleEvent } from '@/types/companyEmployees';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate, formatDateTime } from '@/utils/formatters';
import { assignmentShiftLabel, assignmentTypeLabel, eventLabel } from '@/utils/companyEmployees';

/** Each step of a Replacement cycle has its own message (the stored value alone would show the raw event name in English). */
const CYCLE_EVENT_KEYS: Record<CompanyCycleEvent['event_type'], MessageKey> = {
  absence_started: 'option.company.cycleEvent.absence_started',
  replacement_started: 'option.company.cycleEvent.replacement_started',
  replacement_ended: 'option.company.cycleEvent.replacement_ended',
  returned: 'option.company.cycleEvent.returned',
  original_terminated: 'option.company.cycleEvent.original_terminated',
  cover_cancelled: 'option.company.cycleEvent.cover_cancelled',
};

interface HistoryDialogProps {
  isOpen: boolean;
  onClose: () => void;
  companyId: number;
  companyName: string;
  /** Limit the history to one employee. */
  employee?: { id: number; name: string } | null;
}

/**
 * Who held which position when, and every change made (starts, edits, replacements, returns, stops) with who did it. Read from the
 * audit trail and the assignment chain; nothing here can change anything.
 */
export function HistoryDialog({ isOpen, onClose, companyId, companyName, employee }: HistoryDialogProps) {
  const { t, tValue } = useI18n();
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['company-employee-history', companyId, employee?.id ?? 'all'],
    queryFn: () => companyEmployeesApi.history(companyId, employee?.id),
    enabled: isOpen,
  });

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={employee ? t('option.company.historyOfEmployee', { name: employee.name }) : t('option.company.assignmentHistory', { company: companyName })} size="lg">
      {isLoading && <LoadingState label={t('option.company.loadingHistory')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.chain.length === 0 && <EmptyState icon="inbox" title={t('option.company.noAssignmentsYet')} />}
      {data && data.chain.length > 0 && (
        <div className="space-y-6">
          <section aria-label={t('option.company.assignments')}>
            <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{t('option.company.assignments')}</h4>
            <ol className="divide-y divide-border rounded-md border border-border text-sm">
              {data.chain.map((entry) => (
                <li key={entry.assignment_id} className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-2.5">
                  <span>
                    <span className="font-medium text-ink">{entry.employee_name}</span>{' '}
                    <span className="text-xs text-ink-muted">({entry.employee_number}) · {entry.position_name ?? t('option.company.noPosition')} · {assignmentTypeLabel(entry.assignment_type)} · {assignmentShiftLabel(entry.shift)}</span>
                    {entry.replaces_assignment_id !== null && <span className="ml-2 text-xs text-ink-faint">{t('option.company.tookOver', { id: entry.replaces_assignment_id })}</span>}
                  </span>
                  <span className="text-xs text-ink-muted">
                    {formatDate(entry.start_date)} → {entry.end_date ? formatDate(entry.end_date) : t('option.company.openEnd')} · {tValue('status', entry.status) ?? tValue('status.company', entry.status) ?? entry.status}
                  </span>
                </li>
              ))}
            </ol>
          </section>
          {data.cycles.length > 0 && (
            <section aria-label={t('option.company.cycles')} data-testid="cycles">
              <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{t('option.company.cycles')}</h4>
              <ol className="space-y-2">
                {data.cycles.map((cycle) => (
                  <li key={cycle.id} className="rounded-md border border-border px-3.5 py-2.5 text-sm">
                    <p className="font-semibold text-ink">
                      {t('option.company.cycleSummary', { id: cycle.assignment_id, from: formatDate(cycle.absent_from) })}
                      <span className="font-normal text-ink-muted">
                        {' · '}
                        {cycle.terminated_on !== null
                          ? t('option.company.cycleEnded', { date: formatDate(cycle.terminated_on) })
                          : cycle.returned_on !== null && cycle.returned_on === cycle.absent_from
                            ? t('option.company.cycleCancelled')
                            : cycle.returned_on !== null
                              ? t('option.company.cycleBack', { date: formatDate(cycle.returned_on) })
                              : t('option.company.cycleStillAway')}
                      </span>
                    </p>
                    <ol className="mt-1 space-y-0.5 text-xs text-ink-muted">
                      {cycle.events.map((event, index) => (
                        <li key={`${cycle.id}-${index}`}>
                          {formatDate(event.event_date)} · {CYCLE_EVENT_KEYS[event.event_type] ? t(CYCLE_EVENT_KEYS[event.event_type]) : event.event_type}
                          {event.replacement_assignment_id !== null && ` (#${event.replacement_assignment_id})`}
                          {event.by ? ` · ${event.by}` : ''}
                        </li>
                      ))}
                    </ol>
                  </li>
                ))}
              </ol>
            </section>
          )}
          <section aria-label={t('option.company.changes')}>
            <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">{t('option.company.changes')}</h4>
            {data.events.length === 0 ? (
              <p className="text-sm text-ink-muted">{t('option.company.noRecordedChanges')}</p>
            ) : (
              <ol className="space-y-2">
                {data.events.map((event, index) => (
                  <li key={`${event.assignment_id}-${index}`} className="rounded-md border border-border px-3.5 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-semibold text-ink">{eventLabel(event.event)}</span>
                      <span className="text-xs text-ink-faint">{formatDateTime(event.at)} · {event.by ?? t('option.company.bySystem')}</span>
                    </div>
                    <p className="text-ink-muted">{event.description}</p>
                    <ChangeDetails details={event.details} />
                  </li>
                ))}
              </ol>
            )}
          </section>
        </div>
      )}
    </Modal>
  );
}

function ChangeDetails({ details }: { details: Record<string, unknown> }) {
  const { t, tValue } = useI18n();
  const before = details.before as Record<string, unknown> | undefined;
  const after = details.after as Record<string, unknown> | undefined;
  if (before && after) {
    return (
      <ul className="mt-1 text-xs text-ink-muted">
        {Object.keys(after).map((key) => (
          <li key={key}>
            {tValue('option.company.field', key) ?? key.replace(/_/g, ' ')}: <span className="line-through">{String(before[key] ?? '—')}</span> → <strong>{String(after[key] ?? '—')}</strong>
          </li>
        ))}
      </ul>
    );
  }
  if (typeof details.reason === 'string' && details.reason !== '') return <p className="mt-1 text-xs text-ink-muted">{t('option.label.reasonColon')} {details.reason}</p>;
  return null;
}
