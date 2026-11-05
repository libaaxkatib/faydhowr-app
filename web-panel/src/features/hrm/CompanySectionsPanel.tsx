import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { TABLE_SCROLL_CLASS } from '@/components/ui/TableScroll';
import { useToast } from '@/components/ui/useToast';
import { CompanyPaymentDialog } from '@/features/hrm/CompanyPaymentDialog';
import { ReconcileDialog, ReopenFinalizedDialog } from '@/features/hrm/CorrectionDialogs';
import { GenerateCompanyDialog } from '@/features/hrm/GenerateCompanyDialog';
import { MonthSummaryCard } from '@/features/hrm/MonthSummaryCard';
import { SectionActionDialog } from '@/features/hrm/SectionActionDialog';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { amountOrDash, buildCompanyRows, canGenerateCompany, companyStateBadge, reopenNeedsSuperAdmin, type CompanyListRow } from '@/utils/payrollOverview';
import { MONTH_STATUS_LABEL, PAYMENT_STATUS_LABEL, canReconcile, monthDisplayStatus, paymentActions, sectionActions, sectionName, type PaymentAction, type SectionAction } from '@/utils/payrollSections';
import type { PayrollOverviewCompany, PayrollRun, PayrollRunSection } from '@/types/payroll';

const PERIOD_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

function money(value: string): string {
  const number = Number(value);

  return Number.isFinite(number) ? formatCurrency(number) : value;
}

/** A figure that exists only once a company is generated: "—" before that (never 0, never an estimate). */
function generatedMoney(section: PayrollRunSection | null, pick: (section: PayrollRunSection) => string): string {
  return section ? money(pick(section)) : amountOrDash(null, formatCurrency);
}

interface CompanySectionsPanelProps {
  /** The month the list is for (YYYY-MM): every assigned company of it is listed, generated or not. */
  period: string;
  /** The month's payroll, or null while nothing has been generated for it. */
  run: PayrollRun | null;
  /** The month as the screen words it ("October 2026" / "Oktoobar 2026"). */
  monthLabel: string;
  canManage: boolean;
  canApprove: boolean;
  /** Reopening a finalized company that has recorded payments is for a Super Admin only (the server answers 403 to anyone else). */
  isSuperAdmin: boolean;
  /** The company whose lines the table below shows (null = every company). */
  selectedSectionId: number | null;
  onSelect: (sectionId: number | null) => void;
  /** The words of the one Finalize button each company has (there is no month-level Finalize): the approved "Finalize" and "Finalize payroll". */
  finalizeLabel: string;
  finalizeConfirmLabel: string;
  /** The approved "Mark reviewed" and "Generate / refresh draft": the page passes them so the owner-approved wording stays on the payroll screen, verbatim. */
  reviewLabel: string;
  generateLabel: string;
}

/**
 * All companies of a month, generated or not. The list comes from the overview: every assigned company has a STATE (Not Generated / Draft / Reviewed /
 * Finalized / Partially Paid / Paid / Overpaid) and its figures, which are "—" until the company is generated. A company is generated on its own (there is
 * no month-wide Generate); the payroll status (Draft / Reviewed / Finalized, moved by Review and Finalize) and the payment status (Unpaid / Partially paid /
 * Approved) of a generated company stay side by side so they are never confused. A company is approved by being paid in full ("Approve Paid" pays the whole
 * balance, "Approve Partial" an amount) and the month is approved by itself when every company is: there is no Approve button. Each button changes only its
 * own row. Everything shown comes from the server.
 */
export function CompanySectionsPanel({ period, run, monthLabel, canManage, canApprove, isSuperAdmin, selectedSectionId, onSelect, finalizeLabel, finalizeConfirmLabel, reviewLabel, generateLabel }: CompanySectionsPanelProps) {
  const { t } = useI18n();
  const { show } = useToast();
  const queryClient = useQueryClient();
  const [generating, setGenerating] = useState<{ row: PayrollOverviewCompany; name: string } | null>(null);
  const [reviewing, setReviewing] = useState<PayrollRunSection | null>(null);
  const [finalizing, setFinalizing] = useState<PayrollRunSection | null>(null);
  const [reopening, setReopening] = useState<PayrollRunSection | null>(null);
  const [paying, setPaying] = useState<{ section: PayrollRunSection; mode: 'full' | 'partial' } | null>(null);
  const [reopeningFinalized, setReopeningFinalized] = useState<PayrollRunSection | null>(null);
  const [reconciling, setReconciling] = useState<PayrollRunSection | null>(null);
  const sections = run?.sections ?? [];

  const { data: overview, error, isLoading, refetch } = useQuery({
    queryKey: ['payroll-overview', period],
    queryFn: () => hrApi.payrollRuns.overview(period),
    enabled: PERIOD_PATTERN.test(period),
  });
  const rows = buildCompanyRows(overview, sections);

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['payroll-overview'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-warnings'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-runs'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-run'] });
    void queryClient.invalidateQueries({ queryKey: ['workplace-payments'] });
    // Reopening a company's month supersedes its corrections, so the corrections panel is refreshed as well.
    void queryClient.invalidateQueries({ queryKey: ['payroll-corrections'] });
  };

  const mutation = useMutation({
    mutationFn: ({ section, action, reason }: { section: PayrollRunSection; action: SectionAction; reason?: string }) => {
      switch (action) {
        case 'review':
          return hrApi.payrollRuns.reviewSection(section.id);
        case 'finalize':
          return hrApi.payrollRuns.finalizeSection(section.id);
        case 'reopen':
        case 'reopen_finalized':
          return hrApi.payrollRuns.reopenSection(section.id, reason ?? '');
      }
    },
    onSuccess: (_result, { action }) => {
      refresh();
      setReviewing(null);
      setFinalizing(null);
      setReopening(null);
      show(t(action === 'review' ? 'notify.payroll.reviewed' : action === 'finalize' ? 'notify.payroll.finalized' : 'notify.payroll.reopened'));
    },
    onError: (err, { action }) => {
      setReviewing(null);
      setFinalizing(null);
      show(friendlyErrorMessage(err, t(action === 'review' ? 'notify.payroll.reviewFailed' : action === 'finalize' ? 'notify.payroll.finalizeFailed' : 'notify.payroll.reopenFailed')), 'error');
    },
  });

  const busy = mutation.isPending;
  const act = (section: PayrollRunSection, action: SectionAction) => {
    if (action === 'review') setReviewing(section);
    else if (action === 'finalize') setFinalizing(section);
    else if (action === 'reopen') setReopening(section);
    else if (action === 'reopen_finalized') setReopeningFinalized(section);
    else mutation.mutate({ section, action });
  };
  const fallbackName = t('option.payroll.sectionNoCompany');
  const nameOf = (section: PayrollRunSection) => sectionName(section, fallbackName);
  const rowName = (row: CompanyListRow) => (row.section ? nameOf(row.section) : (row.overview?.company_name?.trim() || fallbackName));
  const label: Record<SectionAction, string> = {
    review: reviewLabel,
    finalize: finalizeLabel,
    reopen: t('action.reopen'),
    reopen_finalized: t('action.correction.reopenFinalized'),
  };
  const payLabel: Record<PaymentAction, string> = { approve_paid: t('action.payroll.approvePaid'), approve_partial: t('action.payroll.approvePartial') };
  const display = run ? MONTH_STATUS_LABEL[monthDisplayStatus(run)] : null;

  return (
    <section className="mb-5 rounded-sm border border-border" data-testid="company-sections">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div>
          <h4 className="font-display text-sm font-bold text-ink">{t('option.payroll.allCompaniesTitle')}</h4>
          <p className="mt-0.5 max-w-3xl text-xs text-ink-muted">{t('option.payroll.sectionsHelp')}</p>
        </div>
        {run && display && (
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={monthDisplayStatus(run)} label={t(display.labelKey)} tone={display.tone} />
            <span className="text-sm font-semibold text-ink" data-testid="sections-progress">
              {t('option.payroll.companiesFinalized', { finalized: run.sections_finalized ?? 0, total: run.sections_total ?? sections.length })}
            </span>
          </div>
        )}
      </div>

      {overview && <MonthSummaryCard summary={overview.summary} monthLabel={monthLabel} />}
      {isLoading && sections.length === 0 && <LoadingState label={t('option.payroll.loadingCompanies')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}

      {selectedSectionId !== null && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-primary-soft px-4 py-2 text-sm text-ink" data-testid="sections-filter-note">
          <span>{t('option.payroll.sectionFilteredBy', { name: nameOf(sections.find((s) => s.id === selectedSectionId) ?? sections[0]) })}</span>
          <Button size="sm" variant="outline" onClick={() => onSelect(null)}>{t('option.payroll.sectionAllLines')}</Button>
        </div>
      )}

      {overview && rows.length === 0 && <p className="px-4 py-6 text-center text-sm text-ink-muted">{t('option.payroll.noCompaniesAssigned')}</p>}

      {rows.length > 0 && (
        <div className={TABLE_SCROLL_CLASS}>
          <table className="w-full min-w-[80rem] text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-ink-muted">
                <th className="px-4 py-2 font-medium">{t('option.payroll.fieldCompany')}</th>
                <th className="px-3 py-2 font-medium">{t('option.payroll.colState')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('option.label.employees')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('option.payroll.gross')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('option.payroll.netPay')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpPaid')}</th>
                <th className="px-3 py-2 text-right font-medium">{t('option.payroll.wpOutstanding')}</th>
                <th className="px-3 py-2 font-medium">{t('option.payroll.colPayrollStatus')}</th>
                <th className="px-3 py-2 font-medium">{t('option.payroll.colPaymentStatus')}</th>
                <th className="px-3 py-2 font-medium">{t('option.payroll.sectionHistory')}</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const { section, overview: ov } = row;
                const status = section ? MONTH_STATUS_LABEL[section.status] : null;
                const payStatus = section ? PAYMENT_STATUS_LABEL[section.payment_status] : null;
                const stateBadge = ov ? companyStateBadge(ov) : null;
                const paidReopenBlocked = section ? reopenNeedsSuperAdmin(section, isSuperAdmin) : false;
                const actions = section ? sectionActions(section.status, canManage, canApprove, paidReopenBlocked) : [];
                const payments = section ? paymentActions(section, canApprove) : [];
                const selected = section !== null && selectedSectionId === section.id;
                const warningCount = ov ? ov.generation_warning_count : (section?.generation_warnings?.length ?? 0);
                const generatedAt = section?.generated_at ?? ov?.generated_at ?? null;

                return (
                  <tr
                    key={row.key}
                    className={`border-b border-border align-top ${selected ? 'bg-primary-soft' : ''}`}
                    data-testid={section ? `section-row-${section.id}` : `company-row-${row.key}`}
                    data-status={section?.status ?? 'not_generated'}
                    data-payment-status={section?.payment_status ?? ''}
                    data-state={ov?.state ?? ''}
                  >
                    <td className="px-4 py-2 font-medium text-ink">
                      {rowName(row)}
                      {warningCount > 0 && (
                        <span className="mt-1 block w-fit rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning" data-testid="company-warning-count">
                          {t('option.payroll.warningCount', { count: warningCount })}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2" data-testid="company-state">
                      {stateBadge ? <StatusBadge status={ov?.state ?? 'draft'} label={t(stateBadge.labelKey)} tone={stateBadge.tone} /> : '—'}
                      {ov?.legacy_approved && <p className="mt-1 max-w-[14rem] text-xs text-ink-muted" data-testid="company-legacy-approved">{t('warning.payroll.legacyApproved')}</p>}
                    </td>
                    <td className="px-3 py-2 text-right text-ink">{section ? section.employee_count : '—'}</td>
                    <td className="px-3 py-2 text-right text-ink">{generatedMoney(section, (s) => s.gross)}</td>
                    <td className="px-3 py-2 text-right font-semibold text-ink" data-testid="section-net">{generatedMoney(section, (s) => s.net)}</td>
                    <td className="px-3 py-2 text-right text-ink" data-testid="section-paid">{generatedMoney(section, (s) => s.paid)}</td>
                    <td className="px-3 py-2 text-right text-ink" data-testid="section-outstanding">{generatedMoney(section, (s) => s.outstanding)}</td>
                    <td className="px-3 py-2">
                      {section && status ? <StatusBadge status={section.status} label={t(status.labelKey)} tone={status.tone} /> : '—'}
                    </td>
                    <td className="px-3 py-2" data-testid="section-payment-status">
                      {section && payStatus ? <StatusBadge status={section.payment_status} label={t(payStatus.labelKey)} tone={payStatus.tone} /> : '—'}
                    </td>
                    <td className="px-3 py-2 text-xs text-ink-muted">
                      {generatedAt && (
                        <p>{section?.generated_by ? t('option.payroll.trailGeneratedBy', { name: section.generated_by, date: formatDate(generatedAt) }) : t('option.payroll.trailGenerated', { date: formatDate(generatedAt) })}</p>
                      )}
                      {section?.reviewed_at && <p>{t('option.payroll.trailReviewedBy', { name: section.reviewed_by ?? '—', date: formatDate(section.reviewed_at) })}</p>}
                      {section?.finalized_at && <p>{t('option.payroll.trailFinalizedBy', { name: section.finalized_by ?? '—', date: formatDate(section.finalized_at) })}</p>}
                      {section && (section.unreconciled_overpaid_cents ?? 0) > 0 && <p className="font-semibold text-danger" data-testid="section-overpaid">{t('option.correction.overpaidAmount', { amount: money(section.unreconciled_overpaid ?? '0') })}</p>}
                      {section && (section.reopenings ?? []).length > 0 && (
                        <details className="mt-1" data-testid={`section-reopenings-${section.id}`}>
                          <summary className="cursor-pointer font-semibold text-ink">{t('option.correction.reopeningHistory')} ({section.reopenings?.length})</summary>
                          {(section.reopenings ?? []).map((r) => (
                            <p key={r.id} className="mt-1">
                              {t('option.correction.reopeningLine', { who: r.reopened_by ?? '—', role: r.reopened_by_role ?? '—', date: r.reopened_at ? formatDate(r.reopened_at) : '—', reason: r.reason, paid: money((r.paid_cents_at_reopen / 100).toFixed(2)) })}
                              {r.refinalized_at ? ` ${t('option.correction.reopeningRefinalized', { date: formatDate(r.refinalized_at) })}` : ''}
                            </p>
                          ))}
                        </details>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex flex-wrap justify-end gap-2">
                        {ov && canGenerateCompany(ov, canManage) && (
                          <Button
                            size="sm"
                            variant={ov.generated ? 'outline' : 'primary'}
                            disabled={busy}
                            onClick={() => setGenerating({ row: ov, name: rowName(row) })}
                            data-testid={`company-generate-${row.key}`}
                          >
                            {generateLabel}
                          </Button>
                        )}
                        {section && actions.map((action) => (
                          <Button key={action} size="sm" variant={action === 'reopen' || action === 'reopen_finalized' ? 'outline' : 'primary'} disabled={busy} onClick={() => act(section, action)} data-testid={`section-${action}-${section.id}`}>
                            {label[action]}
                          </Button>
                        ))}
                        {section && canReconcile(section, canApprove) && (
                          <Button size="sm" variant="primary" disabled={busy} onClick={() => setReconciling(section)} data-testid={`section-reconcile-${section.id}`}>
                            {t('action.correction.reconcile')}
                          </Button>
                        )}
                        {section && payments.map((action) => (
                          <Button
                            key={action}
                            size="sm"
                            variant={action === 'approve_paid' ? 'primary' : 'outline'}
                            disabled={busy}
                            onClick={() => setPaying({ section, mode: action === 'approve_paid' ? 'full' : 'partial' })}
                            data-testid={`section-${action}-${section.id}`}
                          >
                            {payLabel[action]}
                          </Button>
                        ))}
                        {section && (
                          <Button size="sm" variant="ghost" aria-pressed={selected} onClick={() => onSelect(selected ? null : section.id)}>
                            {t('option.payroll.sectionShowLines')}
                          </Button>
                        )}
                      </div>
                      {canApprove && paidReopenBlocked && (
                        <p className="mt-1 max-w-[18rem] text-right text-xs text-ink-muted" data-testid={`section-reopen-hint-${section?.id}`}>{t('warning.payroll.reopenPaidSuperAdmin')}</p>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {generating && <GenerateCompanyDialog period={period} monthLabel={monthLabel} name={generating.name} row={generating.row} onClose={() => setGenerating(null)} />}

      {reviewing && (
        <SectionActionDialog
          period={period}
          company={reviewing.client_company_id}
          title={t('confirm.payroll.sectionReviewTitle', { name: nameOf(reviewing) })}
          description={t('confirm.payroll.sectionReviewBody', { name: nameOf(reviewing), month: monthLabel })}
          confirmLabel={reviewLabel}
          isLoading={busy}
          onConfirm={() => mutation.mutate({ section: reviewing, action: 'review' })}
          onCancel={() => setReviewing(null)}
        />
      )}

      {finalizing && (
        <SectionActionDialog
          period={period}
          company={finalizing.client_company_id}
          title={t('confirm.payroll.sectionFinalizeTitle', { name: nameOf(finalizing) })}
          description={t('confirm.payroll.sectionFinalizeBody', { name: nameOf(finalizing), month: monthLabel })}
          confirmLabel={finalizeConfirmLabel}
          tone="danger"
          isLoading={busy}
          onConfirm={() => mutation.mutate({ section: finalizing, action: 'finalize' })}
          onCancel={() => setFinalizing(null)}
        />
      )}

      {reopening && (
        <ReopenSectionDialog
          name={nameOf(reopening)}
          monthLabel={monthLabel}
          isLoading={busy}
          onClose={() => setReopening(null)}
          onConfirm={(reason) => mutation.mutate({ section: reopening, action: 'reopen', reason })}
        />
      )}

      {paying && <CompanyPaymentDialog section={paying.section} mode={paying.mode} monthLabel={monthLabel} onClose={() => setPaying(null)} />}
      {reopeningFinalized && <ReopenFinalizedDialog section={reopeningFinalized} monthLabel={monthLabel} onClose={() => setReopeningFinalized(null)} />}
      {reconciling && <ReconcileDialog section={reconciling} onClose={() => setReconciling(null)} />}
    </section>
  );
}

/** Reopening one reviewed company needs a reason of at least ten characters (the server checks it again). */
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
