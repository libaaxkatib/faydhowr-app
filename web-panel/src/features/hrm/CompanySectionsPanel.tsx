import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { TABLE_SCROLL_CLASS } from '@/components/ui/TableScroll';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { MONTH_STATUS_LABEL, monthDisplayStatus, sectionActions, sectionName, type SectionAction } from '@/utils/payrollSections';
import type { PayrollRun, PayrollRunSection } from '@/types/payroll';

function money(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

interface CompanySectionsPanelProps {
  run: PayrollRun;
  /** The month as the screen words it ("October 2026" / "Oktoobar 2026"). */
  monthLabel: string;
  canManage: boolean;
  canApprove: boolean;
  /** The company whose lines the table below shows (null = every company). */
  selectedSectionId: number | null;
  onSelect: (sectionId: number | null) => void;
}

/**
 * Payroll by company for a month: every company's own status, employee count, totals, paid and outstanding amounts, who reviewed / approved / finalized
 * it and when, and the buttons that move THAT company one step (Review, Approve, Finalize, Reopen). Each button changes only its own row; the month
 * shows "N of M companies finalized" and is finalized by the server when the last company is. Everything shown comes from the server.
 */
export function CompanySectionsPanel({ run, monthLabel, canManage, canApprove, selectedSectionId, onSelect }: CompanySectionsPanelProps) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [finalizing, setFinalizing] = useState<PayrollRunSection | null>(null);
  const [reopening, setReopening] = useState<PayrollRunSection | null>(null);
  const sections = run.sections ?? [];

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-run', run.id] });
    void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
  };

  const mutation = useMutation({
    mutationFn: ({ section, action, reason }: { section: PayrollRunSection; action: SectionAction; reason?: string }) => {
      switch (action) {
        case 'review':
          return hrApi.payrollRuns.reviewSection(section.id);
        case 'approve':
          return hrApi.payrollRuns.approveSection(section.id);
        case 'finalize':
          return hrApi.payrollRuns.finalizeSection(section.id);
        case 'reopen':
          return hrApi.payrollRuns.reopenSection(section.id, reason ?? '');
      }
    },
    onSuccess: (_result, { action }) => {
      refresh();
      setFinalizing(null);
      setReopening(null);
      show(t(action === 'review' ? 'notify.payroll.reviewed' : action === 'approve' ? 'notify.payroll.approved' : action === 'finalize' ? 'notify.payroll.finalized' : 'notify.payroll.reopened'));
    },
    onError: (err, { action }) => {
      setFinalizing(null);
      show(
        friendlyErrorMessage(err, t(action === 'review' ? 'notify.payroll.reviewFailed' : action === 'approve' ? 'notify.payroll.approveFailed' : action === 'finalize' ? 'notify.payroll.finalizeFailed' : 'notify.payroll.reopenFailed')),
        'error',
      );
    },
  });

  const busy = mutation.isPending;
  const act = (section: PayrollRunSection, action: SectionAction) => {
    if (action === 'finalize') setFinalizing(section);
    else if (action === 'reopen') setReopening(section);
    else mutation.mutate({ section, action });
  };
  const nameOf = (section: PayrollRunSection) => sectionName(section, t('option.payroll.sectionNoCompany'));
  const label: Record<SectionAction, string> = {
    review: t('action.markReviewed'),
    approve: t('action.approvePayrollButton'),
    finalize: t('action.finalize'),
    reopen: t('action.reopen'),
  };
  const display = MONTH_STATUS_LABEL[monthDisplayStatus(run)];

  return (
    <section className="mb-5 rounded-sm border border-border" data-testid="company-sections">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div>
          <h4 className="font-display text-sm font-bold text-ink">{t('option.payroll.sectionsTitle')}</h4>
          <p className="mt-0.5 max-w-3xl text-xs text-ink-muted">{t('option.payroll.sectionsHelp')}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={monthDisplayStatus(run)} label={t(display.labelKey)} tone={display.tone} />
          <span className="text-sm font-semibold text-ink" data-testid="sections-progress">
            {t('option.payroll.companiesFinalized', { finalized: run.sections_finalized ?? 0, total: run.sections_total ?? sections.length })}
          </span>
        </div>
      </div>

      {selectedSectionId !== null && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-primary-soft px-4 py-2 text-sm text-ink" data-testid="sections-filter-note">
          <span>{t('option.payroll.sectionFilteredBy', { name: nameOf(sections.find((s) => s.id === selectedSectionId) ?? sections[0]) })}</span>
          <Button size="sm" variant="outline" onClick={() => onSelect(null)}>{t('option.payroll.sectionAllLines')}</Button>
        </div>
      )}

      <div className={TABLE_SCROLL_CLASS}>
        <table className="w-full min-w-[64rem] text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs text-ink-muted">
              <th className="px-4 py-2 font-medium">{t('option.payroll.fieldCompany')}</th>
              <th className="px-3 py-2 text-right font-medium">{t('option.label.employees')}</th>
              <th className="px-3 py-2 text-right font-medium">{t('option.payroll.gross')}</th>
              <th className="px-3 py-2 text-right font-medium">{t('option.payroll.netPay')}</th>
              <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpPaid')}</th>
              <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpOutstanding')}</th>
              <th className="px-3 py-2 font-medium">{t('option.label.status')}</th>
              <th className="px-3 py-2 font-medium">{t('option.payroll.sectionHistory')}</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {sections.map((section) => {
              const status = MONTH_STATUS_LABEL[section.status];
              const actions = sectionActions(section.status, canManage, canApprove);
              const selected = selectedSectionId === section.id;

              return (
                <tr key={section.id} className={`border-b border-border align-top ${selected ? 'bg-primary-soft' : ''}`} data-testid={`section-row-${section.id}`} data-status={section.status}>
                  <td className="px-4 py-2 font-medium text-ink">{nameOf(section)}</td>
                  <td className="px-3 py-2 text-right text-ink">{section.employee_count}</td>
                  <td className="px-3 py-2 text-right text-ink">{money(section.gross)}</td>
                  <td className="px-3 py-2 text-right font-semibold text-ink">{money(section.net)}</td>
                  <td className="px-3 py-2 text-right text-ink" data-testid="section-paid">{money(section.paid)}</td>
                  <td className="px-3 py-2 text-right text-ink" data-testid="section-outstanding">{money(section.outstanding)}</td>
                  <td className="px-3 py-2">
                    <StatusBadge status={section.status} label={t(status.labelKey)} tone={status.tone} />
                  </td>
                  <td className="px-3 py-2 text-xs text-ink-muted">
                    {section.reviewed_at && <p>{t('option.payroll.trailReviewedBy', { name: section.reviewed_by ?? '—', date: formatDate(section.reviewed_at) })}</p>}
                    {section.approved_at && <p>{t('option.payroll.trailApprovedBy', { name: section.approved_by ?? '—', date: formatDate(section.approved_at) })}</p>}
                    {section.finalized_at && <p>{t('option.payroll.trailFinalizedBy', { name: section.finalized_by ?? '—', date: formatDate(section.finalized_at) })}</p>}
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex flex-wrap justify-end gap-2">
                      {actions.map((action) => (
                        <Button key={action} size="sm" variant={action === 'reopen' ? 'outline' : 'primary'} disabled={busy} onClick={() => act(section, action)} data-testid={`section-${action}-${section.id}`}>
                          {label[action]}
                        </Button>
                      ))}
                      <Button size="sm" variant="ghost" aria-pressed={selected} onClick={() => onSelect(selected ? null : section.id)}>
                        {t('option.payroll.sectionShowLines')}
                      </Button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <ConfirmDialog
        isOpen={finalizing !== null}
        title={t('confirm.payroll.sectionFinalizeTitle', { name: finalizing ? nameOf(finalizing) : '' })}
        description={t('confirm.payroll.sectionFinalizeBody', { name: finalizing ? nameOf(finalizing) : '', month: monthLabel })}
        confirmLabel={t('action.finalize')}
        tone="danger"
        isLoading={busy}
        onConfirm={() => finalizing && mutation.mutate({ section: finalizing, action: 'finalize' })}
        onCancel={() => setFinalizing(null)}
      />

      {reopening && (
        <ReopenSectionDialog
          name={nameOf(reopening)}
          monthLabel={monthLabel}
          isLoading={busy}
          onClose={() => setReopening(null)}
          onConfirm={(reason) => mutation.mutate({ section: reopening, action: 'reopen', reason })}
        />
      )}
    </section>
  );
}

/** Reopening one approved company needs a reason of at least ten characters (the server checks it again). */
function ReopenSectionDialog({ name, monthLabel, isLoading, onClose, onConfirm }: { name: string; monthLabel: string; isLoading: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');

  return (
    <Modal
      isOpen
      onClose={isLoading ? () => undefined : onClose}
      title={t('action.reopenPayroll')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={isLoading}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} disabled={reason.trim().length < 10} onClick={() => onConfirm(reason.trim())}>
            {t('action.reopenPayroll')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.payroll.sectionReopenBody', { name, month: monthLabel })}</p>
      <FormField label={t('option.payroll.reason')} htmlFor="section-reopen-reason" required hint={t('validation.payroll.reasonHint10')}>
        <textarea id="section-reopen-reason" rows={3} maxLength={500} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
