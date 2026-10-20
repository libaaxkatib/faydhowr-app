import { useQuery } from '@tanstack/react-query';

import { companyEmployeesApi } from '@/api/companyEmployees';
import { Modal } from '@/components/ui/Modal';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate, formatDateTime } from '@/utils/formatters';
import { ASSIGNMENT_TYPE_LABELS, assignmentShiftLabel, EVENT_LABELS } from '@/utils/companyEmployees';

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
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['company-employee-history', companyId, employee?.id ?? 'all'],
    queryFn: () => companyEmployeesApi.history(companyId, employee?.id),
    enabled: isOpen,
  });

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={employee ? `History — ${employee.name}` : `Assignment history — ${companyName}`} size="lg">
      {isLoading && <LoadingState label="Loading history…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.chain.length === 0 && <EmptyState icon="inbox" title="No assignments yet" />}
      {data && data.chain.length > 0 && (
        <div className="space-y-6">
          <section aria-label="Assignments">
            <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Assignments</h4>
            <ol className="divide-y divide-border rounded-md border border-border text-sm">
              {data.chain.map((entry) => (
                <li key={entry.assignment_id} className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-2.5">
                  <span>
                    <span className="font-medium text-ink">{entry.employee_name}</span>{' '}
                    <span className="text-xs text-ink-muted">({entry.employee_number}) · {entry.position_name ?? 'no position'} · {ASSIGNMENT_TYPE_LABELS[entry.assignment_type]} · {assignmentShiftLabel(entry.shift)}</span>
                    {entry.replaces_assignment_id !== null && <span className="ml-2 text-xs text-ink-faint">took over from assignment #{entry.replaces_assignment_id}</span>}
                  </span>
                  <span className="text-xs text-ink-muted">
                    {formatDate(entry.start_date)} → {entry.end_date ? formatDate(entry.end_date) : 'open'} · {entry.status}
                  </span>
                </li>
              ))}
            </ol>
          </section>
          <section aria-label="Changes">
            <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-ink-faint">Changes</h4>
            {data.events.length === 0 ? (
              <p className="text-sm text-ink-muted">No recorded changes.</p>
            ) : (
              <ol className="space-y-2">
                {data.events.map((event, index) => (
                  <li key={`${event.assignment_id}-${index}`} className="rounded-md border border-border px-3.5 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-semibold text-ink">{EVENT_LABELS[event.event] ?? event.event}</span>
                      <span className="text-xs text-ink-faint">{formatDateTime(event.at)} · {event.by ?? 'system'}</span>
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
  const before = details.before as Record<string, unknown> | undefined;
  const after = details.after as Record<string, unknown> | undefined;
  if (before && after) {
    return (
      <ul className="mt-1 text-xs text-ink-muted">
        {Object.keys(after).map((key) => (
          <li key={key}>
            {key.replace(/_/g, ' ')}: <span className="line-through">{String(before[key] ?? '—')}</span> → <strong>{String(after[key] ?? '—')}</strong>
          </li>
        ))}
      </ul>
    );
  }
  if (typeof details.reason === 'string' && details.reason !== '') return <p className="mt-1 text-xs text-ink-muted">Reason: {details.reason}</p>;
  return null;
}
