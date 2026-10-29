import { Fragment, useMemo, useRef, useState } from 'react';
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
import { NoMatchState, ResetFiltersButton, ResultCount } from '@/components/ui/ListFilterControls';
import { Modal } from '@/components/ui/Modal';
import { SearchInput } from '@/components/ui/SearchInput';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { getActiveLanguage } from '@/i18n/active';
import { monthYearLabel } from '@/i18n/dates';
import { useI18n } from '@/i18n/useI18n';
import { TABLE_SCROLL_CLASS } from '@/components/ui/TableScroll';
import type { MessageKey } from '@/i18n/messages';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { friendlyErrorMessage, localMonth, localToday, nextMonth } from '@/utils/hrHelpers';
import { PayrollCorrectionsPanel } from '@/features/hrm/PayrollCorrectionsPanel';
import { useAuth } from '@/features/auth/useAuth';
import { canVoidPayrollPayment, validateWriteOffReason } from '@/utils/payrollCorrections';
import { filterPayrollLines, type PayrollPaymentFilter } from '@/utils/payrollLineFilter';
import { PAYMENT_LIST_SCOPES, buttonLabel, canDownload, chooseRecordLabel, emptyOptionsMessage, needsPreview, optionsFor, recordFieldLabel, scopeLabel, selectedOption, type PaymentListScope } from '@/utils/paymentListFilter';
import { PaymentListPreviewDialog } from '@/features/hrm/PaymentListPreviewDialog';
import type {
  PayrollAdjustmentKind,
  PayrollDeductionDetail,
  PayrollDeductionKind,
  PayrollItem,
  PayrollRun,
  PayrollRunStatus,
} from '@/types/payroll';

const PERIOD_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

const RUN_STATUS: Record<PayrollRunStatus, { labelKey: MessageKey; tone: 'neutral' | 'info' | 'warning' | 'success'; helpKey: MessageKey }> = {
  draft: { labelKey: 'option.payrollStatus.draft', tone: 'neutral', helpKey: 'status.payroll.helpDraft' },
  reviewed: { labelKey: 'option.payrollStatus.reviewed', tone: 'info', helpKey: 'status.payroll.helpReviewed' },
  approved: { labelKey: 'option.payrollStatus.approved', tone: 'warning', helpKey: 'status.payroll.helpApproved' },
  finalized: { labelKey: 'option.payrollStatus.finalized', tone: 'success', helpKey: 'status.payroll.helpFinalized' },
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
  return monthYearLabel(getActiveLanguage(), Number(period.slice(0, 4)), Number(period.slice(5, 7)) - 1);
}

/**
 * Month-end payroll. A run moves Draft -> Reviewed -> Approved -> Finalized; the
 * buttons shown depend on both the status and what the signed-in person may do.
 * All figures come from the server - nothing is calculated here.
 */
export function PayrollRunsPage() {
  const { t } = useI18n();
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
      show(t('notify.payroll.draftReady', { month: monthLabel(created.period) }));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.draftFailed')), 'error'),
  });

  const reviewMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.review(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      show(t('notify.payroll.reviewed'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.reviewFailed')), 'error'),
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.approve(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      setConfirming(null);
      show(t('notify.payroll.approved'));
    },
    onError: (err) => {
      setConfirming(null);
      show(friendlyErrorMessage(err, t('notify.payroll.approveFailed')), 'error');
    },
  });

  const finalizeMutation = useMutation({
    mutationFn: (id: number) => hrApi.payrollRuns.finalize(id),
    onSuccess: (updated) => {
      refresh(updated.id);
      setConfirming(null);
      show(t('notify.payroll.finalized'));
    },
    onError: (err) => {
      setConfirming(null);
      show(friendlyErrorMessage(err, t('notify.payroll.finalizeFailed')), 'error');
    },
  });

  const reopenMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => hrApi.payrollRuns.reopen(id, reason),
    onSuccess: (updated) => {
      refresh(updated.id);
      setIsReopenOpen(false);
      show(t('notify.payroll.reopened'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.reopenFailed')), 'error'),
  });

  // The payment list: all records, ONE company or ONE guri, chosen from the records that really exist.
  const [listScope, setListScope] = useState<PaymentListScope>('all');
  const [listRecordId, setListRecordId] = useState('');
  const { data: listFilters } = useQuery({ queryKey: ['payment-list-filters'], queryFn: hrApi.payrollRuns.paymentListFilters, enabled: canDownloadPaymentList });
  const listRecords = optionsFor(listScope, listFilters);
  const listSelected = selectedOption(listScope, listRecordId, listFilters);
  const listEmptyMessage = emptyOptionsMessage(listScope, listFilters);

  // One company or guri is checked in a preview first; the person then confirms the download or cancels. "All" downloads directly, as before.
  const [isListPreviewOpen, setIsListPreviewOpen] = useState(false);

  // A ref, not the mutation's state: two clicks in the same instant both see "not pending" until the next render, and must still download once.
  const paymentListLock = useRef(false);
  const paymentListMutation = useMutation({
    mutationFn: () => hrApi.payrollRuns.downloadPaymentList(period, listScope, listSelected),
    onSuccess: () => setIsListPreviewOpen(false),
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.paymentListFailed')), 'error'),
    onSettled: () => {
      paymentListLock.current = false;
    },
  });
  const startPaymentListDownload = () => {
    if (paymentListLock.current) return;
    paymentListLock.current = true;
    paymentListMutation.mutate();
  };

  const exportMutation = useMutation({
    mutationFn: (target: PayrollRun) => hrApi.payrollRuns.exportExcel(target),
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.excelFailed')), 'error'),
  });

  const adjustMutation = useMutation({
    mutationFn: ({ itemId, payload }: { itemId: number; payload: Parameters<typeof hrApi.payrollRuns.addAdjustment>[1] }) =>
      hrApi.payrollRuns.addAdjustment(itemId, payload),
    onSuccess: (result) => {
      refresh(selectedRunId ?? undefined);
      setAdjustingItem(null);
      show(t('notify.payroll.adjustmentRecorded', { month: monthLabel(result.applies_in_period) }));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.adjustmentFailed')), 'error'),
  });

  const voidMutation = useMutation({
    mutationFn: ({ itemId, reason }: { itemId: number; reason: string }) => hrApi.payrollRuns.voidPayment(itemId, reason),
    onSuccess: () => {
      refresh(selectedRunId ?? undefined);
      queryClient.invalidateQueries({ queryKey: ['payroll-corrections'] });
      setVoidingItem(null);
      show(t('notify.payroll.paymentVoided'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.payroll.paymentVoidFailed')), 'error'),
  });

  const payMutation = useMutation({
    mutationFn: ({ itemId, paymentDate }: { itemId: number; paymentDate: string }) => hrApi.payrollRuns.markPaid(itemId, paymentDate),
    onSuccess: () => {
      refresh(selectedRunId ?? undefined);
      setPayingItem(null);
      show(t('notify.payroll.markedPaid'));
    },
    onError: (err) => {
      setPayingItem(null);
      show(friendlyErrorMessage(err, t('notify.payroll.markPaidFailed')), 'error');
    },
  });

  const runColumns = useMemo<ColumnDef<PayrollRun, unknown>[]>(
    () => [
      {
        header: t('option.label.month'),
        id: 'period',
        cell: ({ row }) => <span className="font-medium text-ink">{monthLabel(row.original.period)}</span>,
      },
      {
        header: t('option.label.status'),
        id: 'status',
        cell: ({ row }) => (
          <StatusBadge status={row.original.status} label={t(RUN_STATUS[row.original.status].labelKey)} tone={RUN_STATUS[row.original.status].tone} />
        ),
      },
      { header: t('option.label.employees'), id: 'count', cell: ({ row }) => <span className="text-ink">{row.original.item_count}</span> },
      { header: t('option.payroll.gross'), id: 'gross', cell: ({ row }) => <span className="text-ink">{money(row.original.totals.gross)}</span> },
      {
        header: t('option.payroll.coverDeductions'),
        id: 'replacement',
        cell: ({ row }) => <span className="text-ink-muted">{deduction(row.original.totals.replacement_deductions ?? '0')}</span>,
      },
      { header: t('option.payroll.netPay'), id: 'net', cell: ({ row }) => <span className="font-semibold text-ink">{money(row.original.totals.net)}</span> },
      { header: t('option.payroll.generated'), id: 'generated', cell: ({ row }) => <span className="text-ink-muted">{formatDate(row.original.generated_at)}</span> },
    ],
    [t],
  );

  return (
    <div>
      <PageHeader title={t('nav.payroll')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.payroll') }]} />

      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="payroll-period" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.label.month')}</label>
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
              {t('action.generateDraft')}
            </Button>
          )}
          {canDownloadPaymentList && (
            <div>
              <label htmlFor="payment-list-scope" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.payroll.paymentListFor')}</label>
              <select
                id="payment-list-scope"
                value={listScope}
                onChange={(e) => {
                  setListScope(e.target.value as PaymentListScope);
                  setListRecordId('');
                }}
                className="h-9 max-w-[14rem] rounded-sm border border-border bg-surface px-2 text-sm"
              >
                {PAYMENT_LIST_SCOPES.map((scope) => (
                  <option key={scope} value={scope}>{scopeLabel(scope)}</option>
                ))}
              </select>
            </div>
          )}
          {canDownloadPaymentList && listScope !== 'all' && (
            <div>
              <label htmlFor="payment-list-record" className="mb-1 block text-xs font-medium text-ink-muted">{recordFieldLabel(listScope)}</label>
              <select
                id="payment-list-record"
                value={listRecordId}
                onChange={(e) => setListRecordId(e.target.value)}
                disabled={listRecords.length === 0}
                className="h-9 max-w-[14rem] rounded-sm border border-border bg-surface px-2 text-sm"
              >
                <option value="">{listRecords.length === 0 ? t('option.payroll.noneAvailable') : chooseRecordLabel(listScope)}</option>
                {listRecords.map((record) => (
                  <option key={record.id} value={record.id}>{record.name}</option>
                ))}
              </select>
              {listEmptyMessage && <p className="mt-1 text-xs text-ink-muted" data-testid="payment-list-empty">{listEmptyMessage}</p>}
            </div>
          )}
          {canDownloadPaymentList && (
            <Button
              variant="outline"
              isLoading={paymentListMutation.isPending}
              disabled={!PERIOD_PATTERN.test(period) || !canDownload(listScope, listRecordId, listFilters)}
              onClick={() => (needsPreview(listScope) ? setIsListPreviewOpen(true) : startPaymentListDownload())}
            >
              <Icon name="file-text" size={15} />
              {buttonLabel(listScope, listSelected)}
            </Button>
          )}
          <p className="min-w-[14rem] flex-1 text-xs text-ink-muted">
            {lockedForPeriod
              ? t(lockedForPeriod.status === 'approved' ? 'warning.payroll.lockedApproved' : 'warning.payroll.lockedFinalized', { month: monthLabel(period) })
              : t('warning.payroll.generateNote')}
            {canDownloadPaymentList && ` ${t('warning.payroll.paymentListNote')}`}
          </p>
        </CardBody>
      </Card>

      {canDownloadPaymentList && (
        <PaymentListPreviewDialog
          isOpen={isListPreviewOpen && needsPreview(listScope) && listSelected !== null}
          period={period}
          monthLabel={monthLabel(period)}
          scope={listScope}
          selected={listSelected}
          isDownloading={paymentListMutation.isPending}
          onClose={() => setIsListPreviewOpen(false)}
          onConfirm={startPaymentListDownload}
        />
      )}

      {isLoading && <LoadingState label={t('option.payroll.loadingPayroll')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {runs && (
        <Card className="mb-4">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.payroll.payrollMonths')}</h3>
          </CardHeader>
          {runs.length === 0 ? (
            <EmptyState icon="credit-card" title={t('option.payroll.noPayrollYet')} description={t('option.payroll.pickMonth')} />
          ) : (
            <DataTable
              columns={runColumns}
              data={runs}
              onRowClick={(row) => setSelectedId(row.id)}
              emptyTitle={t('option.payroll.noPayrollYet')}
            />
          )}
          {runs.length > 0 && <p className="border-t border-border px-5 py-2 text-xs text-ink-faint">{t('option.payroll.clickMonth')}</p>}
        </Card>
      )}

      {selectedRunId !== null && isRunLoading && <LoadingState label={t('option.payroll.loadingLines')} />}
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
        title={t('confirm.payroll.approveTitle')}
        description={t('confirm.payroll.approveBody', { month: run ? monthLabel(run.period) : t('confirm.payroll.theMonth') })}
        confirmLabel={t('action.approvePayroll')}
        isLoading={approveMutation.isPending}
        onConfirm={() => run && approveMutation.mutate(run.id)}
        onCancel={() => setConfirming(null)}
      />

      <ConfirmDialog
        isOpen={confirming === 'finalize'}
        title={t('confirm.payroll.finalizeTitle')}
        description={t('confirm.payroll.finalizeBody')}
        confirmLabel={t('action.finalizePayroll')}
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
  const { t } = useI18n();
  const status = RUN_STATUS[run.status];
  const trail = [
    run.generated_at &&
      (run.generated_by
        ? t('option.payroll.trailGeneratedBy', { name: run.generated_by, date: formatDate(run.generated_at) })
        : t('option.payroll.trailGenerated', { date: formatDate(run.generated_at) })),
    run.reviewed_at &&
      (run.reviewed_by
        ? t('option.payroll.trailReviewedBy', { name: run.reviewed_by, date: formatDate(run.reviewed_at) })
        : t('option.payroll.trailReviewed', { date: formatDate(run.reviewed_at) })),
    run.approved_at &&
      (run.approved_by
        ? t('option.payroll.trailApprovedBy', { name: run.approved_by, date: formatDate(run.approved_at) })
        : t('option.payroll.trailApproved', { date: formatDate(run.approved_at) })),
    run.finalized_at &&
      (run.finalized_by
        ? t('option.payroll.trailFinalizedBy', { name: run.finalized_by, date: formatDate(run.finalized_at) })
        : t('option.payroll.trailFinalized', { date: formatDate(run.finalized_at) })),
  ].filter((entry): entry is string => Boolean(entry));

  const totals = [
    { label: t('option.payroll.grossPay'), value: money(run.totals.gross) },
    { label: t('option.payroll.coverDeductionsLong'), value: deduction(run.totals.replacement_deductions ?? '0') },
    { label: t('option.payroll.otherDeductions'), value: deduction(run.totals.other_deductions ?? '0') },
    { label: t('option.payroll.earlierAdjustments'), value: signedMoney(run.totals.adjustments ?? '0') },
    { label: t('option.payroll.netPay'), value: money(run.totals.net) },
  ];

  return (
    <Card>
      <CardHeader className="flex-wrap">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-display text-base font-bold text-ink">{t('option.payroll.payrollFor', { month: monthLabel(run.period) })}</h3>
            <StatusBadge status={run.status} label={t(status.labelKey)} tone={status.tone} />
          </div>
          <p className="mt-0.5 text-xs text-ink-muted">{t(status.helpKey)}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {run.status === 'draft' && canManage && (
            <Button size="sm" isLoading={busy.review} disabled={run.item_count === 0} onClick={onReview}>
              {t('action.markReviewed')}
            </Button>
          )}
          {run.status === 'reviewed' && canApprove && (
            <Button size="sm" onClick={onApprove}>{t('action.approvePayrollButton')}</Button>
          )}
          {run.status === 'approved' && canApprove && (
            <>
              <Button size="sm" onClick={onFinalize}>{t('action.finalize')}</Button>
              <Button size="sm" variant="outline" onClick={onReopen}>{t('action.reopen')}</Button>
            </>
          )}
          <Button size="sm" variant="outline" isLoading={busy.export} onClick={onExport}>
            <Icon name="file-text" size={14} />
            {t('action.payroll.downloadExcel')}
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
            <p className="mb-1 text-sm font-semibold text-warning">{t('warning.payroll.checkBeforeApproving')}</p>
            <ul className="list-disc space-y-0.5 pl-5 text-sm text-ink">
              {run.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </div>
        )}

        {!run.items || run.items.length === 0 ? (
          <EmptyState icon="credit-card" title={t('option.payroll.noLines')} description={t('option.payroll.noLinesRun')} />
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
  const { t, tCount } = useI18n();
  const [expanded, setExpanded] = useState<Set<number>>(new Set());
  // Finding a line (display only): the lines of the month are already loaded, so the search and the payment filter work on them here.
  const [search, setSearch] = useState('');
  const [payment, setPayment] = useState<PayrollPaymentFilter>('');
  const isFinalized = run.status === 'finalized';
  const allItems = run.items ?? [];
  const items = filterPayrollLines(allItems, search, payment);
  const filtered = search.trim() !== '' || payment !== '';
  const reset = () => {
    setSearch('');
    setPayment('');
  };
  const columnCount = isFinalized && canApprove ? 10 : 9;

  function toggle(id: number) {
    const next = new Set(expanded);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setExpanded(next);
  }

  return (
    <>
    <div className="mb-3 flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder={t('option.payroll.searchLines')} className="w-full max-w-sm" />
        <Select
          aria-label={t('status.payroll.paid')}
          value={payment}
          onChange={(event) => setPayment(event.target.value as PayrollPaymentFilter)}
          options={[{ value: 'paid', label: t('option.payroll.paymentPaid') }, { value: 'unpaid', label: t('option.payroll.paymentNotPaid') }]}
          placeholder={t('option.payroll.paymentAny')}
          className="w-48"
        />
        <ResetFiltersButton count={(search.trim() !== '' ? 1 : 0) + (payment !== '' ? 1 : 0)} onReset={reset} />
      </div>
      <ResultCount matching={items.length} total={allItems.length} filtered={filtered} />
      {filtered && <p className="text-xs text-ink-faint">{t('warning.payroll.totalsWholeMonth')}</p>}
    </div>
    {filtered && items.length === 0 ? (
      <NoMatchState onReset={reset} />
    ) : (
    <div role="region" tabIndex={0} aria-label={t('option.common.dataTable')} className={TABLE_SCROLL_CLASS}>
      <table className="w-full min-w-[860px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-border bg-surface-alt">
            {[
              '',
              t('option.label.employee'),
              t('option.payroll.companyWorkplace'),
              t('option.payroll.days'),
              t('option.payroll.gross'),
              t('status.payroll.deduction.replacement'),
              t('option.payroll.otherDeductions'),
              t('status.payroll.deduction.adjustment'),
              t('option.payroll.netPay'),
              t('status.payroll.paid'),
            ].map((heading, index) => (
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
                      aria-label={isOpen ? t('action.payroll.hideDeductions') : t('action.payroll.showDeductions')}
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
                    <p className="text-ink">{item.company_name ?? t('option.payroll.companyMissing')}</p>
                    <p className="text-xs text-ink-muted">{item.workplace_name ?? t('option.workplaceNotSet')}</p>
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
                      <StatusBadge status="paid" label={item.paid_at ? t('status.payroll.paidOn', { date: formatDate(item.paid_at) }) : t('status.payroll.paid')} tone="success" />
                    ) : (
                      <span className="text-xs text-ink-faint">{isFinalized ? t('status.payroll.notPaidYet') : '—'}</span>
                    )}
                    {(item.payment_voids?.length ?? 0) > 0 && (
                      <span className="mt-1 block text-xs text-warning" data-testid="payment-voided-note">
                        {tCount('warning.payroll.earlierVoided', item.payment_voids!.length)}
                      </span>
                    )}
                  </td>
                  {isFinalized && canApprove && (
                    <td className="whitespace-nowrap px-3 py-3">
                      <div className="flex items-center gap-1">
                        <Button size="sm" variant="outline" onClick={() => onAdjust(item)}>{t('action.recordAdjustment')}</Button>
                        {item.payment_status !== 'paid' && (
                          <Button size="sm" onClick={() => onPay(item)}>{t('action.markPaid')}</Button>
                        )}
                        {item.payment_status === 'paid' && canVoid && (
                          <Button size="sm" variant="outline" onClick={() => onVoid(item)}>{t('action.voidPayment')}</Button>
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
    )}
    </>
  );
}

function DeductionList({ item }: { item: PayrollItem }) {
  const { t, tCount, tValue } = useI18n();
  const replacementSummary = (detail: PayrollDeductionDetail): string => {
    const who = detail.temporary_employee_name ?? t('warning.payroll.aTemporaryEmployee');
    const number = detail.temporary_employee_number ? ` (${detail.temporary_employee_number})` : '';
    const days = detail.covered_days ?? detail.verified_days ?? 0;
    return tCount('warning.payroll.coverSummary', days, { who: `${who}${number}` });
  };
  const deductions = item.deductions ?? [];
  const pending = item.calc_snapshot?.pending_replacements_not_deducted ?? [];

  if (deductions.length === 0 && pending.length === 0) {
    return <p className="text-sm text-ink-muted">{t('option.payroll.noDeductions')}</p>;
  }

  return (
    <div className="space-y-2">
      {deductions.map((entry, index) => (
        <div key={`${entry.kind}-${index}`} className="flex flex-wrap items-start justify-between gap-2 text-sm">
          <div>
            <p className="font-medium text-ink">{tValue('status.payroll.deduction', entry.kind) ?? DEDUCTION_LABEL[entry.kind] ?? entry.kind}</p>
            <p className="text-xs text-ink-muted">
              {entry.kind === 'replacement' && entry.detail ? replacementSummary(entry.detail) : entry.description}
              {entry.kind === 'replacement' && entry.detail?.daily_rate && ` ${t('warning.payroll.atDailyRate', { rate: money(entry.detail.daily_rate) })}`}
            </p>
          </div>
          <p className="whitespace-nowrap font-semibold text-ink">{deduction(entry.amount)}</p>
        </div>
      ))}
      {pending.map((detail, index) => (
        <div key={`pending-${detail.replacement_id ?? index}`} className="flex flex-wrap items-start justify-between gap-2 text-sm">
          <div>
            <p className="font-medium text-warning">{t('warning.payroll.temporaryCoverWaiting')}</p>
            <p className="text-xs text-ink-muted">
              {t('warning.payroll.notDeductedYet', { summary: replacementSummary(detail) })}
            </p>
          </div>
          {typeof detail.amount_cents === 'number' && (
            <p className="whitespace-nowrap text-ink-muted">{formatCurrency(detail.amount_cents / 100)} {t('warning.payroll.notDeducted')}</p>
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
  const { t } = useI18n();
  const [reason, setReason] = useState('');

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.reopenPayroll')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} disabled={reason.trim().length < 10} onClick={() => onConfirm(reason.trim())}>
            {t('action.reopenPayroll')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        {t('confirm.payroll.reopenBody', { month: monthLabel(run.period) })}
      </p>
      <FormField label={t('option.payroll.reason')} htmlFor="reopen-reason" required hint={t('validation.payroll.reasonHint10')}>
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
  const { t } = useI18n();
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
      title={t('action.recordAdjustment')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
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
            {t('action.recordAdjustment')}
          </Button>
        </>
      }
    >
      <p className="mb-1 text-sm font-medium text-ink">{item.employee_name} - {monthLabel(item.period)}</p>
      <p className="mb-4 text-xs text-ink-muted">
        {t('confirm.payroll.adjustNote', { net: money(item.net) })}
      </p>

      <fieldset className="mb-4">
        <legend className="mb-1.5 text-sm font-medium text-ink">{t('option.payroll.type')}</legend>
        <label className="mb-1 flex items-center gap-2 text-sm text-ink">
          <input type="radio" name="adjustment-kind" checked={kind === 'correction'} onChange={() => setKind('correction')} />
          {t('option.payroll.adjustCorrection')}
        </label>
        <label className="flex items-center gap-2 text-sm text-ink">
          <input type="radio" name="adjustment-kind" checked={kind === 'reversal'} onChange={() => setKind('reversal')} />
          {t('option.payroll.adjustReversal', { net: money(item.net) })}
        </label>
      </fieldset>

      {kind === 'correction' && (
        <FormField
          label={t('option.payroll.amountUsdLong')}
          htmlFor="adj-amount"
          required
          hint={t('validation.payroll.adjustAmountHint')}
        >
          <input id="adj-amount" type="number" step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
      )}

      <FormField
        label={t('option.payroll.applyInPayroll')}
        htmlFor="adj-period"
        required
        hint={t('validation.payroll.adjustPeriodHint', { month: monthLabel(earliest) })}
        error={appliesIn !== '' && !isPeriodValid ? t('validation.payroll.adjustPeriodError', { month: monthLabel(item.period) }) : undefined}
      >
        <input id="adj-period" type="month" min={earliest} className={inputClasses} value={appliesIn} onChange={(e) => setAppliesIn(e.target.value)} />
      </FormField>

      <FormField label={t('option.payroll.reason')} htmlFor="adj-reason" required hint={t('validation.payroll.reasonHint10')}>
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
  const { t } = useI18n();
  const [reason, setReason] = useState('');
  const problem = reason !== '' ? validateWriteOffReason(reason) : null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.payroll.voidTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} disabled={validateWriteOffReason(reason) !== null} onClick={() => onConfirm(reason.trim())}>{t('action.voidPayment')}</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        {t('confirm.payroll.voidBody', { net: money(item.net), name: item.employee_name ?? t('confirm.payroll.theEmployee'), month: monthLabel(item.period) })}
      </p>
      <FormField label={t('option.payroll.reason')} htmlFor="void-payroll-reason" required error={problem ?? undefined} hint={t('validation.payroll.reasonRequired5')}>
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
  const { t } = useI18n();
  const [paymentDate, setPaymentDate] = useState(localToday());

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.markAsPaid')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} disabled={paymentDate === ''} onClick={() => onConfirm(paymentDate)}>
            {t('action.markAsPaid')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        {t('confirm.payroll.markPaidBody', { name: item.employee_name ?? t('confirm.payroll.theEmployee'), net: money(item.net), month: monthLabel(item.period) })}
      </p>
      <FormField label={t('option.payroll.paymentDate')} htmlFor="paid-date" required>
        <input id="paid-date" type="date" max={localToday()} className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
    </Modal>
  );
}
