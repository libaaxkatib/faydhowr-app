import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { formatDate } from '@/utils/formatters';
import { formatCents } from '@/utils/companyEmployees';
import type { PayrollCorrection } from '@/types/payroll';

const KIND_LABEL: Record<PayrollCorrection['kind'], string> = { overpayment: 'Overpaid', underpayment: 'Underpaid' };

const STATUS: Record<PayrollCorrection['status'], { label: string; tone: 'warning' | 'success' | 'info' }> = {
  open: { label: 'Being recovered', tone: 'warning' },
  recovered: { label: 'Fully recovered', tone: 'success' },
  pending_policy: { label: 'Awaiting owner rule', tone: 'info' },
};

/**
 * Read-only ledger of the corrections a Super Admin made to approved/finalized payroll months: what the closed month recorded, what it
 * should have been, how much of an overpayment has been taken back from later salaries, and what is still owed. Nothing here changes
 * payroll; the closed month itself is never edited. Shown only to people who can view payroll (the server enforces it too).
 */
export function PayrollCorrectionsPanel() {
  const { data, isLoading, error } = useQuery({ queryKey: ['payroll-corrections'], queryFn: hrApi.payrollRuns.corrections });

  if (isLoading || error || !data || data.corrections.length === 0) return null;

  const money = (cents: number) => formatCents(cents, data.totals.currency);

  return (
    <Card className="mt-4" data-testid="payroll-corrections">
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">Corrections of closed payroll and overpayment recovery</h3>
      </CardHeader>
      <CardBody>
        <dl className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div><dt className="text-xs text-ink-muted">Overpaid in closed months</dt><dd className="font-medium">{money(data.totals.overpaid_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Recovered so far</dt><dd className="font-medium">{money(data.totals.recovered_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Still owed</dt><dd className="font-medium">{money(data.totals.outstanding_cents)}</dd></div>
          <div><dt className="text-xs text-ink-muted">Underpayments awaiting a rule</dt><dd className="font-medium">{money(data.totals.underpayments_pending_policy_cents)}</dd></div>
        </dl>
        <p className="mb-3 text-xs text-ink-muted">
          The closed month is never changed. An overpayment is taken from the employee's later salaries (never more than that month's pay) until it is repaid. An underpayment is recorded here; no payment rule has been approved yet, so nothing is paid automatically.
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-ink-muted">
              <tr>
                <th className="py-1.5 pr-3 font-medium">Employee</th>
                <th className="py-1.5 pr-3 font-medium">Month</th>
                <th className="py-1.5 pr-3 font-medium">Recorded</th>
                <th className="py-1.5 pr-3 font-medium">Should have been</th>
                <th className="py-1.5 pr-3 font-medium">Difference</th>
                <th className="py-1.5 pr-3 font-medium">Recovered</th>
                <th className="py-1.5 pr-3 font-medium">Still owed</th>
                <th className="py-1.5 pr-3 font-medium">Status</th>
                <th className="py-1.5 font-medium">Reason and who</th>
              </tr>
            </thead>
            <tbody>
              {data.corrections.map((c) => (
                <tr key={c.id} className="border-t border-border align-top">
                  <td className="py-2 pr-3">{c.employee_name} <span className="text-xs text-ink-muted">({c.employee_number})</span></td>
                  <td className="py-2 pr-3">{c.period}</td>
                  <td className="py-2 pr-3">{money(c.recorded_net_cents)}</td>
                  <td className="py-2 pr-3">{money(c.corrected_net_cents)}</td>
                  <td className="py-2 pr-3">{KIND_LABEL[c.kind]} {money(Math.abs(c.difference_cents))}</td>
                  <td className="py-2 pr-3">
                    {c.kind === 'overpayment' ? money(c.recovered_cents) : '—'}
                    {c.recoveries.length > 0 && (
                      <span className="block text-xs text-ink-muted">{c.recoveries.map((r) => `${r.period}: ${money(r.amount_cents)}${r.status === 'scheduled' ? ' (draft)' : ''}`).join('; ')}</span>
                    )}
                  </td>
                  <td className="py-2 pr-3">{c.kind === 'overpayment' ? money(c.outstanding_cents) : '—'}</td>
                  <td className="py-2 pr-3"><StatusBadge status={c.status} label={STATUS[c.status].label} tone={STATUS[c.status].tone} /></td>
                  <td className="py-2 text-xs text-ink-muted">{c.reason}<br />{c.corrected_by ?? '—'}{c.corrected_at ? `, ${formatDate(c.corrected_at)}` : ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardBody>
    </Card>
  );
}
