import { TableScroll } from '@/components/ui/TableScroll';
import { useI18n } from '@/i18n/useI18n';
import type { RecoveryComparison } from '@/types/recovery';
import { formatCents } from '@/utils/companyEmployees';
import { signedMoney } from '@/utils/recovery';

/** Payroll before and after a correction (or the stored draft against the live calculation), one row per payroll line, with the totals. */
export function ImpactTable({ period, comparison, currency }: { period: string; comparison: RecoveryComparison; currency: string }) {
  const { t } = useI18n();
  const money = (cents: number | null) => (cents === null ? '—' : formatCents(cents, currency));
  const rows = comparison.lines.filter((line) => line.change !== 'same');

  return (
    <div className="mb-4">
      <h4 className="mb-1 text-sm font-semibold text-ink">{period}</h4>
      {rows.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('warning.recovery.impactNone')}</p>
      ) : (
        <TableScroll>
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase text-ink-muted">
                <th className="px-3 py-2">{t('option.recovery.employee')}</th>
                <th className="px-3 py-2 text-right">{t('option.recovery.before')} ({t('option.recovery.days')})</th>
                <th className="px-3 py-2 text-right">{t('option.recovery.after')} ({t('option.recovery.days')})</th>
                <th className="px-3 py-2 text-right">{t('option.recovery.before')} ({t('option.recovery.net')})</th>
                <th className="px-3 py-2 text-right">{t('option.recovery.after')} ({t('option.recovery.net')})</th>
                <th className="px-3 py-2 text-right">{t('option.recovery.difference')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((line) => (
                <tr key={line.assignment_id} className="border-t border-border">
                  <td className="px-3 py-2">{line.employee_name} <span className="text-ink-faint">#{line.assignment_id}</span></td>
                  <td className="px-3 py-2 text-right">{line.before_days ?? '—'}</td>
                  <td className="px-3 py-2 text-right">{line.after_days ?? '—'}</td>
                  <td className="px-3 py-2 text-right">{money(line.before_net_cents)}</td>
                  <td className="px-3 py-2 text-right">{money(line.after_net_cents)}</td>
                  <td className={`px-3 py-2 text-right font-semibold ${line.difference_cents < 0 ? 'text-danger' : line.difference_cents > 0 ? 'text-success' : ''}`}>
                    {signedMoney(line.difference_cents, (c) => formatCents(c, currency))}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t border-border font-semibold">
                <td className="px-3 py-2">{t('option.recovery.total')}</td>
                <td colSpan={2} />
                <td className="px-3 py-2 text-right">{money(comparison.before_total_cents)}</td>
                <td className="px-3 py-2 text-right">{money(comparison.after_total_cents)}</td>
                <td className="px-3 py-2 text-right">{signedMoney(comparison.difference_cents, (c) => formatCents(c, currency))}</td>
              </tr>
            </tfoot>
          </table>
        </TableScroll>
      )}
    </div>
  );
}
