import { Fragment, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { friendlyErrorMessage, localMonth, localToday, nextMonth } from '@/utils/hrHelpers';
import { PayrollCorrectionsPanel } from '@/features/hrm/PayrollCorrectionsPanel';
import { useAuth } from '@/features/auth/useAuth';
import { canVoidPayrollPayment, validateWriteOffReason } from '@/utils/payrollCorrections';
import type {
  PayrollAdjustmentKind,
  PayrollDeductionDetail,
  PayrollDeductionKind,
  PayrollItem,
  PayrollRun,
  PayrollRunStatus,
} from '@/types/payroll';

const PERIOD_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

const RUN_STATUS: Record<PayrollRunStatus, { label: string; tone: 'neutral' | 'info' | 'warning' | 'success'; help: string }> = {
  draft: { label: 'Draft', tone: 'neutral', help: 'Still being prepared. It can be regenerated.' },
  reviewed: { label: 'Reviewed', tone: 'info', help: 'Checked by one person. A different person approves it.' },
  approved: { label: 'Approved', tone: 'warning', help: 'The month is locked. It can still be reopened until it is finalized.' },
  finalized: { label: 'Finalized', tone: 'success', help: 'Final. Corrections are recorded as adjustments in a later month.' },
};

const DEDUCTION_LABEL: Record<PayrollDeductionKind, string> = {
  replacement: 'Temporary cover',
  penalty: 'Penalty',
  advance: 'Salary advance',
  absence: 'Absence',
  adjustment: 'Adjustment',
  unpaid_leave: 'Unpaid leave',
  overpayment_recovery: 'Overpayment recovery',
};

function money(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const amount = Number(value);
  return Number.isFinite(amount) ? formatCurrency(amount) : value;
}

/** A deduction is stored as a positive number; show it with a minus sign, or a dash when there is none. */
function deduction(value: string): string {
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount === 0) return '—';
  return `−${formatCurrency(Math.abs(amount))}`;
}

/** A signed amount: positive pays the employee more, negative recovers money. */
function signedMoney(value: string): string {
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount === 0) return '—';
  return `${amount > 0 ? '+' : '−'}${formatCurrency(Math.abs(amount))}`;
}

function monthLabel(period: string): string {
  if (!PERIOD_PATTERN.test(period)) return period;
  return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(new Date(Number(period.slice(0, 4)), Number(period.slice(5, 7)) - 1, 1));
}

/**
 * Month-end payroll. A run moves Draft -> Reviewed -> Approved -> Finalized; the
 * buttons shown depend on both the status and what the signed-in person may do.
 * All figures come from the server - nothing is calculated here.
 */
export function PayrollRunsPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.payroll.manage');
  const canApprove = hasPermission('hr.payroll.approve');
  const { admin } = useAuth();
  // Voiding a payment marked paid: Super Admin or HR Manager (the server checks the role and the permission again).
  const canVoid = canApprove && canVoidPayrollPayment(admin?.role);
  // The list contains employees' payment numbers, so the server requires hr.manage as well as payroll access.
  const canDownloadPaymentList = hasPermission('hr.manage');

  const [period, setPeriod] = useState(localMonth());
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [confirming, setConfirming] = useState<'approve' | 'finalize' | null>(null);
  const [isReopenOpen, setIsReopenOpen] = useState(false);
  const [adjustingItem, setAdjustingItem] = useState<PayrollItem | null>(null);
  const [payingItem, setPayingItem] = useState<PayrollItem | null>(null);
  const [voidingItem, setVoidingItem] = useState<PayrollItem | null>(null);

  const { data: runs, isLoading, error, refetch } = useQuery({ queryKey: ['payroll-runs'], queryFn: hrApi.payrollRuns.list });

  const selectedRunId = selectedId ?? runs?.find((run) => run.period === period)?.id ?? runs?.[0]?.id ?? null;

  const { data: run, isLoading: isRunLoading, error: runError } = useQuery({
    queryKey: ['payroll-run', selectedRunId],
    queryFn: () => hrApi.payrollRuns.get(selectedRunId as number),
    enabled: selectedRunId !== null,
  });

  const lockedForPeriod = runs?.find((candidate) => candidate.period === period && (candidate.status === 'approved' || candidate.status === 'finalized'));

  const refresh = (id?: number) => {
    queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
    queryClient.invalidateQueries({ queryKey: id ? ['payroll-run', id] : ['payroll-run'] });
  };

  const generateMutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.generate(period),
    onSuccess: (created) => {
      setSelectedId(created.id);
      refresh(created.id);
      show(`Draft payroll for ${monthLabel(created.period)} is ready.`);
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not generate the payroll draft.'), 'error'),
  });

  const reviewMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.review(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      show('Payroll marked as reviewed. A different person must approve it.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not mark the payroll as reviewed.'), 'error'),
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.approve(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      setConfirming(null);
      show('Payroll approved.');
    },
    onError: (err) => {
      setConfirming(null);
      show(friendlyErrorMessage(err, 'Could not approve the payroll.'), 'error');
    },
  });

  const finalizeMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.finalize(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      setConfirming(null);
      show('Payroll finalized.');
    },
    onError: (err) => {
      setConfirming(null);
      show(friendlyErrorMessage(err, 'Could not finalize the payroll.'), 'error');
    },
  });

  const reopenMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => hrApi.payrollRuns.reopen(id, reason),
    onSuccess: (updated) => {
      refresh(updated.id);
      setIsReopenOpen(false);
      show('Payroll reopened. It is a draft again.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not reopen the payroll.'), 'error'),
  });

  const [listCompanyId, setListCompanyId] = useState('');
  const { data: listCompanies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list, enabled: canDownloadPaymentList });
  const listCompany = (listCompanies ?? []).find((company) => String(company.id) === listCompanyId);

  const paymentListMutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.downloadPaymentList(period, listCompany ? { id: listCompany.id, name: listCompany.name } : undefined),
    onError: (err) => show(friendlyErrorMessage(err, 'Could not download the payment list.'), 'error'),
  });

  const exportMutation = useMutation({
    mutationFn: (target: PayrollRun) => hrApi.payrollRuns.exportExcel(target),
    onError: (err) => show(friendlyErrorMessage(err, 'Could not download the Excel file.'), 'error'),
  });

  const adjustMutation = useMutation({
    mutationFn: ({ itemId, payload }: { itemId: number; payload: Parameters<typeof hrApi.payrollRuns.addAdjustment>[1] }) =>
      hrApi.payrollRuns.addAdjustment(itemId, payload),
    onSuccess: (result) => {
      refresh(selectedRunId ?? undefined);
      setAdjustingItem(null);
      show(`Adjustment recorded. It will apply in the ${monthLabel(result.applies_in_period)} payroll.`);
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not record the adjustment.'), 'error'),
  });

  const voidMutation = useMutation({
    mutationFn: ({ itemId, reason }: { itemId: number; reason: string }) => hrApi.payrollRuns.voidPayment(itemId, reason),
    onSuccess: () => {
      refresh(selectedRunId ?? undefined);
      queryClient.invalidateQueries({ queryKey: ['payroll-corrections'] });
      setVoidingItem(null);
      show('Payment voided. A linked reversal was recorded and the line can be paid again.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not void this payment. Nothing was changed.'), 'error'),
  });

  const payMutation = useMutation({
    mutationFn: ({ itemId, paymentDate }: { itemId: number; paymentDate: string }) => hrApi.payrollRuns.markPaid(itemId, paymentDate),
    onSuccess: () => {
      refresh(selectedRunId ?? undefined);
      setPayingItem(null);
      show('Marked as paid.');
    },
    onError: (err) => {
      setPayingItem(null);
      show(friendlyErrorMessage(err, 'Could not mark this line as paid.'), 'error');
    },
  });

  const runColumns = useMemo<ColumnDef<PayrollRun, unknown>[]>(
    () => [
      {
        header: 'Month',
        id: 'period',
        cell: ({ row }) => <span className="font-medium text-ink">{monthLabel(row.original.period)}</span>,
      },
      {
        header: 'Status',
        id: 'status',
        cell: ({ row }) => (
          <StatusBadge status={row.original.status} label={RUN_STATUS[row.original.status].label} tone={RUN_STATUS[row.original.status].tone} />
        ),
      },
      { header: 'Employees', id: 'count', cell: ({ row }) => <span className="text-ink">{row.original.item_count}</span> },
      { header: 'Gross', id: 'gross', cell: ({ row }) => <span className="text-ink">{money(row.original.totals.gross)}</span> },
      {
        header: 'Cover deductions',
        id: 'replacement',
        cell: ({ row }) => <span className="text-ink-muted">{deduction(row.original.totals.replacement_deductions ?? '0')}</span>,
      },
      { header: 'Net pay', id: 'net', cell: ({ row }) => <span className="font-semibold text-ink">{money(row.original.totals.net)}</span> },
      { header: 'Generated', id: 'generated', cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.generated_at)}</span> },
    ],
    [],
  );

  return (
    <div>
      <PageHeader title="Payroll" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Payroll' }]} />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="payroll-period" className="mb-1 block text-xs font-medium text-ink-muted">Month</label>
            <input
              id="payroll-period"
              type="month"
              value={period}
              onChange={(e) => {
                setPeriod(e.target.value);
                const match = runs?.find((candidate) => candidate.period === e.target.value);
                if (match) setSelectedId(match.id);
              }}
              className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
            />
          </div>
          {canManage && (
            <Button
              isLoading={generateMutation.isPending}
              disabled={!PERIOD_PATTERN.test(period) || lockedForPeriod !== undefined}
              onClick={() => generateMutation.mutate()}
            >
              <Icon name="refresh" size={15} />
              Generate / refresh draft
            </Button>
          )}
          {canDownloadPaymentList && (
            <div>
              <label htmlFor="payment-list-company" className="mb-1 block text-xs font-medium text-ink-muted">Payment list for</label>
              <select
                id="payment-list-company"
                value={listCompanyId}
                onChange={(e) => setListCompanyId(e.target.value)}
                className="h-9 max-w-[14rem] rounded-sm border border-border bg-surface px-2 text-sm"
              >
                <option value="">All companies</option>
                {(listCompanies ?? []).map((company) => (
                  <option key={company.id} value={company.id}>{company.name}</option>
                ))}
              </select>
            </div>
          )}
          {canDownloadPaymentList && (
            <Button
              variant="outline"
              isLoading={paymentListMutation.isPending}
              disabled={!PERIOD_PATTERN.test(period)}
              onClick={() => paymentListMutation.mutate()}
            >
              <Icon name="file-text" size={15} />
              {listCompany ? `Download ${listCompany.name} list (Excel)` : 'Download payment list (Excel)'}
            </Button>
          )}
          <p className="min-w-[14rem] flex-1 text-xs text-ink-muted">
            {lockedForPeriod
              ? `${monthLabel(period)} is already ${lockedForPeriod.status}, so it cannot be regenerated. ${lockedForPeriod.status === 'approved' ? 'Reopen it first if it needs to change.' : 'Use adjustments for corrections.'}`
              : 'Creates the draft payroll for the month from salaries, absences, penalties, advances and approved temporary cover. Running it again refreshes the draft.'}
            {canDownloadPaymentList &&
              ' The payment list is an Excel file of who to pay for the selected month (an estimate until the month is approved); choose a company to get only that company. Downloading it does not mark anything paid or change anything.'}
          </p>
        </CardBody>
      </Card>

      {isLoading && <LoadingState label="Loading payroll…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {runs && (
        <Card className="mb-4">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Payroll months</h3>
          </CardHeader>
          {runs.length === 0 ? (
            <EmptyState icon="credit-card" title="No payroll yet" description="Pick a month and generate the first draft." />
          ) : (
            <DataTable
              columns={runColumns}
              data={runs}
              onRowClick={(row) => setSelectedId(row.id)}
              emptyTitle="No payroll yet"
            />
          )}
          {runs.length > 0 && <p className="border-t border-border px-5 py-2 text-xs text-ink-faint">Click a month to see its lines.</p>}
        </Card>
      )}

      {selectedRunId !== null && isRunLoading && <LoadingState label="Loading payroll lines…" />}
      {runError && <ErrorState error={runError} />}

      {run && (
        <RunDetail
          run={run}
          canManage={canManage}
          canApprove={canApprove}
          busy={{
            review: reviewMutation.isPending,
            export: exportMutation.isPending,
          }}
          onReview={() => reviewMutation.mutate(run.id)}
          onApprove={() => setConfirming('approve')}
          onFinalize={() => setConfirming('finalize')}
          onReopen={() => setIsReopenOpen(true)}
          onExport={() => exportMutation.mutate(run)}
          onAdjust={setAdjustingItem}
          onPay={setPayingItem}
          canVoid={canVoid}
          onVoid={setVoidingItem}
        />
      )}

      <PayrollCorrectionsPanel />

      <ConfirmDialog
        isOpen={confirming === 'approve'}
        title="Approve this payroll?"
        description={`Approving locks ${run ? monthLabel(run.period) : 'the month'}: salary and temporary-cover changes for that month will be refused. A different person from the reviewer must approve. You can still reopen an approved payroll before it is finalized.`}
        confirmLabel="Approve payroll"
        isLoading={approveMutation.isPending}
        onConfirm={() => run && approveMutation.mutate(run.id)}
        onCancel={() => setConfirming(null)}
      />

      <ConfirmDialog
        isOpen={confirming === 'finalize'}
        title="Finalize this payroll?"
        description="Finalized payroll cannot be edited; corrections are recorded as adjustments in a later month. After finalizing you can mark each line as paid."
        confirmLabel="Finalize payroll"
        tone="danger"
        isLoading={finalizeMutation.isPending}
        onConfirm={() => run && finalizeMutation.mutate(run.id)}
        onCancel={() => setConfirming(null)}
      />

      {isReopenOpen && run && (
        <ReopenModal
          run={run}
          onClose={() => setIsReopenOpen(false)}
          onConfirm={(reason) => reopenMutation.mutate({ id: run.id, reason })}
          isLoading={reopenMutation.isPending}
        />
      )}

      {adjustingItem && (
        <AdjustmentModal
          item={adjustingItem}
          onClose={() => setAdjustingItem(null)}
          onConfirm={(payload) => adjustMutation.mutate({ itemId: adjustingItem.id, payload })}
          isLoading={adjustMutation.isPending}
        />
      )}

      {voidingItem && (
        <VoidPaymentModal
          item={voidingItem}
          onClose={() => setVoidingItem(null)}
          onConfirm={(reason) => voidMutation.mutate({ itemId: voidingItem.id, reason })}
          isLoading={voidMutation.isPending}
        />
      )}

      {payingItem && (
        <MarkPaidModal
          item={payingItem}
          onClose={() => setPayingItem(null)}
          onConfirm={(paymentDate) => payMutation.mutate({ itemId: payingItem.id, paymentDate })}
          isLoading={payMutation.isPending}
        />
      )}
    </div>
  );
}

function RunDetail({
  run,
  canManage,
  canApprove,
  busy,
  onReview,
  onApprove,
  onFinalize,
  onReopen,
  onExport,
  onAdjust,
  onPay,
  canVoid,
  onVoid,
}: {
  run: PayrollRun;
  canManage: boolean;
  canApprove: boolean;
  busy: { review: boolean; export: boolean };
  onReview: () => void;
  onApprove: () => void;
  onFinalize: () => void;
  onReopen: () => void;
  onExport: () => void;
  onAdjust: (item: PayrollItem) => void;
  onPay: (item: PayrollItem) => void;
  canVoid: boolean;
  onVoid: (item: PayrollItem) => void;
}) {
  const status = RUN_STATUS[run.status];
  const trail = [
    run.generated_at && `Generated${run.generated_by ? ` by ${run.generated_by}` : ''} on ${formatDate(run.generated_at)}`,
    run.reviewed_at && `Reviewed${run.reviewed_by ? ` by ${run.reviewed_by}` : ''} on ${formatDate(run.reviewed_at)}`,
    run.approved_at && `Approved${run.approved_by ? ` by ${run.approved_by}` : ''} on ${formatDate(run.approved_at)}`,
    run.finalized_at && `Finalized${run.finalized_by ? ` by ${run.finalized_by}` : ''} on ${formatDate(run.finalized_at)}`,
  ].filter((entry): entry is string => Boolean(entry));

  const totals = [
    { label: 'Gross pay', value: money(run.totals.gross) },
    { label: 'Temporary-cover deductions', value: deduction(run.totals.replacement_deductions ?? '0') },
    { label: 'Other deductions', value: deduction(run.totals.other_deductions ?? '0') },
    { label: 'Adjustments from earlier months', value: signedMoney(run.totals.adjustments ?? '0') },
    { label: 'Net pay', value: money(run.totals.net) },
  ];

  return (
    <Card>
      <CardHeader className="flex-wrap">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-display text-base font-bold text-ink">Payroll for {monthLabel(run.period)}</h3>
            <StatusBadge status={run.status} label={status.label} tone={status.tone} />
          </div>
          <p className="mt-0.5 text-xs text-ink-muted">{status.help}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {run.status === 'draft' && canManage && (
            <Button size="sm" isLoading={busy.review} disabled={run.item_count === 0} onClick={onReview}>
              Mark reviewed
            </Button>
          )}
          {run.status === 'reviewed' && canApprove && (
            <Button size="sm" onClick={onApprove}>Approve</Button>
          )}
          {run.status === 'approved' && canApprove && (
            <>
              <Button size="sm" onClick={onFinalize}>Finalize</Button>
              <Button size="sm" variant="outline" onClick={onReopen}>Reopen</Button>
            </>
          )}
          <Button size="sm" variant="outline" isLoading={busy.export} onClick={onExport}>
            <Icon name="file-text" size={14} />
            Download Excel
          </Button>
        </div>
      </CardHeader>

      <CardBody>
        {trail.length > 0 && <p className="mb-4 text-xs text-ink-faint">{trail.join(' · ')}</p>}

        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
          {totals.map((total) => (
            <div key={total.label} className="rounded-sm border border-border px-3 py-2">
              <p className="text-xs text-ink-faint">{total.label}</p>
              <p className="font-display text-base font-bold text-ink">{total.value}</p>
            </div>
          ))}
        </div>

        {run.warnings.length > 0 && (
          <div className="mb-4 rounded-sm border border-warning/40 bg-warning-soft px-3 py-3" role="alert">
            <p className="mb-1 text-sm font-semibold text-warning">Please check before approving</p>
            <ul className="list-disc space-y-0.5 pl-5 text-sm text-ink">
              {run.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </div>
        )}

        {!run.items || run.items.length === 0 ? (
          <EmptyState icon="credit-card" title="No payroll lines" description="No employee had an active assignment with a salary in this month." />
        ) : (
          <ItemsTable run={run} canApprove={canApprove} onAdjust={onAdjust} onPay={onPay} canVoid={canVoid} onVoid={onVoid} />
        )}
      </CardBody>
    </Card>
  );
}

function ItemsTable({
  run,
  canApprove,
  onAdjust,
  onPay,
  canVoid,
  onVoid,
}: {
  run: PayrollRun;
  canApprove: boolean;
  onAdjust: (item: PayrollItem) => void;
  onPay: (item: PayrollItem) => void;
  canVoid: boolean;
  onVoid: (item: PayrollItem) => void;
}) {
  const [expanded, setExpanded] = useState<Set<number>>(new Set());
  const isFinalized = run.status === 'finalized';
  const items = run.items ?? [];
  const columnCount = isFinalized && canApprove ? 10 : 9;

  function toggle(id: number) {
    const next = new Set(expanded);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setExpanded(next);
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[860px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-border bg-surface-alt">
            {['', 'Employee', 'Company / workplace', 'Days', 'Gross', 'Temporary cover', 'Other deductions', 'Adjustment', 'Net pay', 'Paid'].map((heading, index) => (
              <th key={heading || `h${index}`} className="whitespace-nowrap px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-ink-faint">
                {heading}
              </th>
            ))}
            {isFinalized && canApprove && <th className="px-3 py-3" />}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const isOpen = expanded.has(item.id);
            return (
              <Fragment key={item.id}>
                <tr className="border-b border-border align-top">
                  <td className="px-3 py-3">
                    <button
                      type="button"
                      aria-expanded={isOpen}
                      aria-label={isOpen ? 'Hide deductions' : 'Show deductions'}
                      onClick={() => toggle(item.id)}
                      className="flex h-6 w-6 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary"
                    >
                      <Icon name={isOpen ? 'chevron-down' : 'chevron-right'} size={14} />
                    </button>
                  </td>
                  <td className="px-3 py-3">
                    <p className="font-medium text-ink">{item.employee_name ?? '—'}</p>
                    <p className="text-xs text-ink-muted">{item.employee_number ?? ''}</p>
                  </td>
                  <td className="px-3 py-3">
                    <p className="text-ink">{item.company_name ?? 'Company missing'}</p>
                    <p className="text-xs text-ink-muted">{item.workplace_name ?? 'Workplace not set yet'}</p>
                  </td>
                  <td className="whitespace-nowrap px-3 py-3 text-ink">
                    {item.eligible_days} / {item.days_in_month}
                  </td>
                  <td className="whitespace-nowrap px-3 py-3 text-ink">{money(item.gross)}</td>
                  <td className="whitespace-nowrap px-3 py-3 text-ink">{deduction(item.replacement_deduction)}</td>
                  <td className="whitespace-nowrap px-3 py-3 text-ink">{deduction(item.other_deductions)}</td>
                  <td className="whitespace-nowrap px-3 py-3 text-ink">{signedMoney(item.adjustment)}</td>
                  <td className="whitespace-nowrap px-3 py-3 font-semibold text-ink">{money(item.net)}</td>
                  <td className="whitespace-nowrap px-3 py-3">
                    {item.payment_status === 'paid' ? (
                      <StatusBadge status="paid" label={item.paid_at ? `Paid ${formatDate(item.paid_at)}` : 'Paid'} tone="success" />
                    ) : (
                      <span className="text-xs text-ink-faint">{isFinalized ? 'Not paid yet' : '—'}</span>
                    )}
                    {(item.payment_voids?.length ?? 0) > 0 && (
                      <span className="mt-1 block text-xs text-warning" data-testid="payment-voided-note">
                        {item.payment_voids!.length} earlier payment{item.payment_voids!.length > 1 ? 's' : ''} voided (kept in the ledger)
                      </span>
                    )}
                  </td>
                  {isFinalized && canApprove && (
                    <td className="whitespace-nowrap px-3 py-3">
                      <div className="flex items-center gap-1">
                        <Button size="sm" variant="outline" onClick={() => onAdjust(item)}>Record adjustment</Button>
                        {item.payment_status !== 'paid' && (
                          <Button size="sm" onClick={() => onPay(item)}>Mark paid</Button>
                        )}
                        {item.payment_status === 'paid' && canVoid && (
                          <Button size="sm" variant="outline" onClick={() => onVoid(item)}>Void payment</Button>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
                {isOpen && (
                  <tr className="border-b border-border bg-surface-alt/50">
                    <td colSpan={columnCount} className="px-3 py-3 pl-12">
                      <DeductionList item={item} />
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function replacementSummary(detail: PayrollDeductionDetail): string {
  const who = detail.temporary_employee_name ?? 'a Temporary employee';
  const number = detail.temporary_employee_number ? ` (${detail.temporary_employee_number})` : '';
  const days = detail.verified_days ?? 0;
  return `${who}${number} covered for ${days} verified day${days === 1 ? '' : 's'}`;
}

function DeductionList({ item }: { item: PayrollItem }) {
  const deductions = item.deductions ?? [];
  const pending = item.calc_snapshot?.pending_replacements_not_deducted ?? [];

  if (deductions.length === 0 && pending.length === 0) {
    return <p className="text-sm text-ink-muted">No deductions for this employee this month.</p>;
  }

  return (
    <div className="space-y-2">
      {deductions.map((entry, index) => (
        <div key={`${entry.kind}-${index}`} className="flex flex-wrap items-start justify-between gap-2 text-sm">
          <div>
            <p className="font-medium text-ink">{DEDUCTION_LABEL[entry.kind] ?? entry.kind}</p>
            <p className="text-xs text-ink-muted">
              {entry.kind === 'replacement' && entry.detail ? replacementSummary(entry.detail) : entry.description}
              {entry.kind === 'replacement' && entry.detail?.daily_rate && ` at ${money(entry.detail.daily_rate)} per day`}
            </p>
          </div>
          <p className="whitespace-nowrap font-semibold text-ink">{deduction(entry.amount)}</p>
        </div>
      ))}
      {pending.map((detail, index) => (
        <div key={`pending-${detail.replacement_id ?? index}`} className="flex flex-wrap items-start justify-between gap-2 text-sm">
          <div>
            <p className="font-medium text-warning">Temporary cover waiting for approval</p>
            <p className="text-xs text-ink-muted">
              {replacementSummary(detail)}. It was calculated but is NOT deducted until the replacement is approved.
            </p>
          </div>
          {typeof detail.amount_cents === 'number' && (
            <p className="whitespace-nowrap text-ink-muted">{formatCurrency(detail.amount_cents / 100)} (not deducted)</p>
          )}
        </div>
      ))}
    </div>
  );
}

function ReopenModal({
  run,
  onClose,
  onConfirm,
  isLoading,
}: {
  run: PayrollRun;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  isLoading: boolean;
}) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Reopen payroll"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} disabled={reason.trim().length < 10} onClick={() => onConfirm(reason.trim())}>
            Reopen payroll
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        {monthLabel(run.period)} goes back to Draft and has to be reviewed and approved again. Only an approved payroll can be reopened.
      </p>
      <FormField label="Reason" htmlFor="reopen-reason" required hint="At least 10 characters. This is kept in the audit log.">
        <textarea id="reopen-reason" rows={3} maxLength={500} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function AdjustmentModal({
  item,
  onClose,
  onConfirm,
  isLoading,
}: {
  item: PayrollItem;
  onClose: () => void;
  onConfirm: (payload: { kind: PayrollAdjustmentKind; amount?: number; reason: string; applies_in_period: string }) => void;
  isLoading: boolean;
}) {
  const earliest = nextMonth(item.period);
  const [kind, setKind] = useState<PayrollAdjustmentKind>('correction');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [appliesIn, setAppliesIn] = useState(earliest);

  const amountValue = Number(amount);
  const isAmountValid = kind === 'reversal' || (amount.trim() !== '' && Number.isFinite(amountValue) && amountValue !== 0);
  const isPeriodValid = PERIOD_PATTERN.test(appliesIn) && appliesIn > item.period;
  const canSave = isAmountValid && isPeriodValid && reason.trim().length >= 10;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record adjustment"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            disabled={!canSave}
            onClick={() =>
              onConfirm({
                kind,
                ...(kind === 'correction' ? { amount: amountValue } : {}),
                reason: reason.trim(),
                applies_in_period: appliesIn,
              })
            }
          >
            Record adjustment
          </Button>
        </>
      }
    >
      <p className="mb-1 text-sm font-medium text-ink">{item.employee_name} - {monthLabel(item.period)}</p>
      <p className="mb-4 text-xs text-ink-muted">
        Net pay for that month: {money(item.net)}. The finalized month is never edited; the adjustment is applied in the payroll of the later month you choose.
      </p>

      <fieldset className="mb-4">
        <legend className="mb-1.5 text-sm font-medium text-ink">Type</legend>
        <label className="mb-1 flex items-center gap-2 text-sm text-ink">
          <input type="radio" name="adjustment-kind" checked={kind === 'correction'} onChange={() => setKind('correction')} />
          Correction (add or take away an amount)
        </label>
        <label className="flex items-center gap-2 text-sm text-ink">
          <input type="radio" name="adjustment-kind" checked={kind === 'reversal'} onChange={() => setKind('reversal')} />
          Reverse the whole net pay ({money(item.net)}) - can only be done once
        </label>
      </fieldset>

      {kind === 'correction' && (
        <FormField
          label="Amount in US dollars"
          htmlFor="adj-amount"
          required
          hint="A positive amount pays the employee more. A negative amount (for example -25) recovers money."
        >
          <input id="adj-amount" type="number" step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
      )}

      <FormField
        label="Apply in the payroll of"
        htmlFor="adj-period"
        required
        hint={`Must be ${monthLabel(earliest)} or later, and that month must not be approved or finalized yet.`}
        error={appliesIn !== '' && !isPeriodValid ? `Choose a month after ${monthLabel(item.period)}.` : undefined}
      >
        <input id="adj-period" type="month" min={earliest} className={inputClasses} value={appliesIn} onChange={(e) => setAppliesIn(e.target.value)} />
      </FormField>

      <FormField label="Reason" htmlFor="adj-reason" required hint="At least 10 characters. This is kept in the audit log.">
        <textarea id="adj-reason" rows={3} maxLength={500} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function VoidPaymentModal({
  item,
  onClose,
  onConfirm,
  isLoading,
}: {
  item: PayrollItem;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  isLoading: boolean;
}) {
  const [reason, setReason] = useState('');
  const problem = reason !== '' ? validateWriteOffReason(reason) : null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Void this payment"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} disabled={validateWriteOffReason(reason) !== null} onClick={() => onConfirm(reason.trim())}>Void payment</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        This cancels the payment of {money(item.net)} marked as paid to {item.employee_name ?? 'the employee'} for {monthLabel(item.period)}. The original payment is not edited or deleted: a linked reversal is added to the payment ledger, the line goes back to "not paid" and can be paid again. It is recorded in the audit history.
      </p>
      <FormField label="Reason" htmlFor="void-payroll-reason" required error={problem ?? undefined} hint="Required, at least 5 characters.">
        <input id="void-payroll-reason" maxLength={500} className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function MarkPaidModal({
  item,
  onClose,
  onConfirm,
  isLoading,
}: {
  item: PayrollItem;
  onClose: () => void;
  onConfirm: (paymentDate: string) => void;
  isLoading: boolean;
}) {
  const [paymentDate, setPaymentDate] = useState(localToday());

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Mark as paid"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} disabled={paymentDate === ''} onClick={() => onConfirm(paymentDate)}>
            Mark as paid
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        This records that {item.employee_name ?? 'the employee'} was paid {money(item.net)} for {monthLabel(item.period)}. The payment record is created once and cannot be repeated.
      </p>
      <FormField label="Payment date" htmlFor="paid-date" required>
        <input id="paid-date" type="date" max={localToday()} className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
    </Modal>
  );
}
