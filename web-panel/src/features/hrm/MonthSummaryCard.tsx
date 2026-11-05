import { StatusBadge } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency } from '@/utils/formatters';
import { amountOrDash, showAllCompaniesPaid, summaryCounts } from '@/utils/payrollOverview';
import type { PayrollOverviewSummary } from '@/types/payroll';

/**
 * The month card, driven by the overview's summary. It says "<Month> — All Companies Paid" ONLY when every assigned company has been generated and paid;
 * otherwise it shows the counts (assigned, generated, not generated, draft, reviewed, finalized unpaid, partially paid, paid, overpaid) and the three money
 * figures of the generated companies. Everything comes from the server; nothing is added up here.
 */
export function MonthSummaryCard({ summary, monthLabel }: { summary: PayrollOverviewSummary; monthLabel: string }) {
  const { t } = useI18n();
  const allPaid = showAllCompaniesPaid(summary);
  const money = (value: string) => amountOrDash(value, formatCurrency);

  return (
    <div className="border-b border-border px-4 py-3" data-testid="month-summary" data-all-paid={allPaid}>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h5 className="text-sm font-semibold text-ink">{t('option.payroll.sumTitle')}</h5>
          <p className="text-xs text-ink-muted">{t('option.payroll.sumHelp')}</p>
        </div>
        {allPaid && <StatusBadge status="paid" tone="success" label={t('status.payroll.allCompaniesPaid', { month: monthLabel })} />}
      </div>

      {!allPaid && (
        <div className="mb-2 grid grid-cols-3 gap-2 md:grid-cols-5 lg:grid-cols-9" data-testid="month-summary-counts">
          {summaryCounts(summary).map((count) => (
            <div key={count.labelKey} className="rounded-sm border border-border px-3 py-1.5">
              <p className="text-xs text-ink-faint">{t(count.labelKey)}</p>
              <p className="font-display text-sm font-bold text-ink">{count.value}</p>
            </div>
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-3" data-testid="month-summary-money">
        {([['option.payroll.sumPayrollTotal', summary.payroll_total], ['option.payroll.sumPaymentsReceived', summary.payments_received], ['option.payroll.wpOutstanding', summary.outstanding]] as const).map(([label, value]) => (
          <div key={label} className="rounded-sm border border-border px-3 py-1.5">
            <p className="text-xs text-ink-faint">{t(label)}</p>
            <p className="font-display text-sm font-bold text-ink">{money(value)}</p>
          </div>
        ))}
      </div>
    </div>
  );
}
