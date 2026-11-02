import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { recoveryApi } from '@/api/recovery';
import { SearchInput } from '@/components/ui/SearchInput';
import { TableScroll } from '@/components/ui/TableScroll';
import { useI18n } from '@/i18n/useI18n';
import type { RecoveryAssignmentHit, RecoveryOverview } from '@/types/recovery';
import { formatCents } from '@/utils/companyEmployees';

/** Step 1: find the assignment by employee name, employee number or assignment id (two letters are enough). */
export function AssignmentSearch({ picked, onPick, onClear }: { picked: RecoveryAssignmentHit | null; onPick: (hit: RecoveryAssignmentHit) => void; onClear: () => void }) {
  const { t } = useI18n();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 300);

    return () => clearTimeout(timer);
  }, [search]);

  const results = useQuery({
    queryKey: ['recovery', 'search', debounced],
    queryFn: () => recoveryApi.searchAssignments(debounced),
    enabled: debounced.length >= 2 || /^\d+$/.test(debounced),
  });

  if (picked !== null) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
        <span className="text-ink">
          {t('option.recovery.selected', { id: picked.id })} — {picked.employee_name} ({picked.employee_number})
        </span>
        <button type="button" onClick={onClear} className="h-8 rounded-sm border border-border bg-surface px-3 text-xs font-semibold text-ink hover:bg-surface-alt">
          {t('action.recovery.reset')}
        </button>
      </div>
    );
  }

  return (
    <div>
      <SearchInput value={search} onChange={setSearch} placeholder={t('option.recovery.searchPlaceholder')} className="mb-3 max-w-md" />
      {results.data && results.data.length === 0 && <p className="text-sm text-ink-muted">{t('warning.recovery.noAssignments')}</p>}
      {results.data && results.data.length > 0 && (
        <TableScroll>
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase text-ink-muted">
                <th className="px-3 py-2">#</th>
                <th className="px-3 py-2">{t('option.recovery.employee')}</th>
                <th className="px-3 py-2">{t('option.recovery.company')}</th>
                <th className="px-3 py-2">{t('option.recovery.status')}</th>
                <th className="px-3 py-2">{t('option.recovery.period')}</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {results.data.map((hit) => (
                <tr key={hit.id} className="border-t border-border">
                  <td className="px-3 py-2">{hit.id}</td>
                  <td className="px-3 py-2">{hit.employee_name} <span className="text-ink-faint">({hit.employee_number})</span></td>
                  <td className="px-3 py-2">{hit.company}</td>
                  <td className="px-3 py-2">{hit.status}</td>
                  <td className="px-3 py-2">{hit.start_date} → {hit.end_date ?? t('option.recovery.open')}</td>
                  <td className="px-3 py-2 text-right">
                    <button type="button" onClick={() => onPick(hit)} className="h-8 rounded-sm bg-primary px-3 text-xs font-semibold text-white hover:bg-primary-ink">
                      {t('action.recovery.choose')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableScroll>
      )}
    </div>
  );
}

/** What the Recovery Center knows about the assignment: the employee, the dates, who took over, the absences and the payroll lines it already has. */
export function AssignmentOverview({ data }: { data: RecoveryOverview }) {
  const { t } = useI18n();
  const a = data.assignment;

  return (
    <div className="space-y-3 text-sm">
      <dl className="grid gap-x-6 gap-y-1 md:grid-cols-3">
        <div><dt className="text-xs text-ink-muted">{t('option.recovery.employee')}</dt><dd>{a.employee.name} ({a.employee.number}) — {a.employee.status}</dd></div>
        <div><dt className="text-xs text-ink-muted">{t('option.recovery.company')}</dt><dd>{a.company ?? '—'}{a.workplace ? ` / ${a.workplace}` : ''}</dd></div>
        <div><dt className="text-xs text-ink-muted">{t('option.recovery.status')}</dt><dd>{a.type} · {a.status}</dd></div>
        <div><dt className="text-xs text-ink-muted">{t('option.recovery.startDate')}</dt><dd>{a.start_date}</dd></div>
        <div><dt className="text-xs text-ink-muted">{t('option.recovery.endDate')}</dt><dd>{a.end_date ?? t('option.recovery.open')}</dd></div>
        <div><dt className="text-xs text-ink-muted">{a.position ?? ''}</dt><dd>{formatCents(Math.round(Number(a.salary_amount) * 100), a.salary_currency)}</dd></div>
      </dl>

      {data.successors.length > 0 && (
        <p><span className="font-semibold">{t('option.recovery.successors')}:</span> {data.successors.map((s) => `#${s.id} (${s.start_date} → ${s.end_date ?? t('option.recovery.open')}, ${s.status})`).join('; ')}</p>
      )}

      {data.absences.length > 0 && (
        <div>
          <p className="font-semibold">{t('option.recovery.absences')}</p>
          <ul className="list-disc pl-5">
            {data.absences.map((x) => (
              <li key={x.id}>#{x.id}: {t('option.recovery.lastWorkingDay')} {x.last_working_day}; {t('option.recovery.returnedOn')} {x.returned_on ?? t('option.recovery.open')}</li>
            ))}
          </ul>
        </div>
      )}

      {data.payroll_lines.length > 0 && (
        <div>
          <p className="font-semibold">{t('option.recovery.payrollLines')}</p>
          <ul className="list-disc pl-5">
            {data.payroll_lines.map((l) => (
              <li key={l.period}>{l.period} — {l.run_status ?? '—'} — {l.days} {t('option.recovery.days')} — {formatCents(l.net_cents, a.salary_currency)} ({l.payment_status})</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
