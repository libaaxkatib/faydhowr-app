import { useEffect, useState } from 'react';
import { returnState } from '@/utils/urlFilters';
import { overrideWarning, paymentDiffers } from '@/utils/temporaryPayment';
import { canCheckConflict, conflictFromError, conflictHeading, conflictMessage, type CoverConflict } from '@/utils/coverConflict';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { parseReplacementStatus } from '@/utils/hrDashboardLinks';
import { matchesStatusFilter } from '@/utils/replacementChange';
import { assignmentLabel, describeDailyRate, expandDateRange, friendlyErrorMessage, localMonth, localToday } from '@/utils/hrHelpers';
import type { TemporaryReplacement, TemporaryReplacementWorkday, TemporaryReplacementWorkdayStatus } from '@/types/employee';
import { Msg } from '@/i18n/Msg';
import type { MessageKey } from '@/i18n/messages';
import { EditReplacementModal, EndReplacementModal, ReplacementHistoryModal } from './ReplacementChangeDialogs';
import { EmployeePicker } from './ReplacementEmployeePicker';
import { useI18n } from '@/i18n/useI18n';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: one dedicated entry point for
 * "Temporary Replacement" - a Temporary employee covers a Permanent employee's
 * assignment. The cost is no longer typed in: it is worked out from the covered
 * employee's salary, only for days HR has verified, and (once approved) deducted
 * once from the covered employee's pay. Payments are still a manual ledger.
 */
/** The badge of a cover: its lifecycle as the server works it out (an Active cover past its end date reads "Expired — Needs Closing"). */
const LIFECYCLE_LABEL: Record<TemporaryReplacement['lifecycle'], MessageKey> = {
  scheduled: 'status.pipeline.replacement.scheduled',
  running: 'status.pipeline.replacement.active',
  expired: 'status.pipeline.replacement.expired',
  ended: 'status.pipeline.replacement.ended',
};

export function TemporaryReplacementsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [payingFor, setPayingFor] = useState<TemporaryReplacement | null>(null);
  const [ending, setEnding] = useState<TemporaryReplacement | null>(null);
  const [editing, setEditing] = useState<TemporaryReplacement | null>(null);
  const [historyOf, setHistoryOf] = useState<TemporaryReplacement | null>(null);
  const [closing, setClosing] = useState<TemporaryReplacement | null>(null);
  const [workdaysForId, setWorkdaysForId] = useState<number | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['temporary-replacements'], queryFn: hrApi.temporaryReplacements.list });
  const workdaysFor = data?.find((replacement) => replacement.id === workdaysForId) ?? null;
  // The HR dashboard's Active Replacements card links here with ?status=active.
  const statusFilter = parseReplacementStatus(searchParams);
  // "Active" = running or not started yet; "Expired" = still open although its end date has passed (the server works the lifecycle out in Mogadishu time).
  const visibleReplacements = data && statusFilter ? data.filter((replacement) => matchesStatusFilter(statusFilter, replacement.lifecycle)) : data;
  const statusFilterWord = statusFilter ? tValue('status.pipeline.replacement', statusFilter)?.toLowerCase() ?? statusFilter : '';

  // The days and cost shown in "Workdays & cost" are cached for 30 seconds; a successful edit, end or close changes them, so they are refetched at once.
  const refreshCoverFigures = (id: number) => {
    queryClient.invalidateQueries({ queryKey: ['replacement-workdays', id] });
    queryClient.invalidateQueries({ queryKey: ['replacement-cost', id] });
  };

  const closeMutation = useMutation({
    mutationFn: (id: number) => hrApi.temporaryReplacements.close(id),
    onSuccess: (_closed, id) => {
      refreshCoverFigures(id);
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      queryClient.invalidateQueries({ queryKey: ['hr-dashboard'] });
      show(t('notify.pipeline.replacementClosed'));
      setClosing(null);
    },
    onError: (err) => {
      setClosing(null);
      show(friendlyErrorMessage(err, t('notify.pipeline.couldNotClose')), 'error');
    },
  });

  const paymentMutation = useMutation({
    mutationFn: ({ id, paymentDate, amount, notes, overrideReason }: { id: number; paymentDate: string; amount: number; notes: string; overrideReason: string }) =>
      hrApi.temporaryReplacements.addPayment(id, { payment_date: paymentDate, amount, notes: notes || null, override_reason: overrideReason || null }),
    onSuccess: (saved) => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      // A different amount is permitted, never blocked: it is recorded as typed and the difference is said plainly.
      show(saved.payment_check?.differs ? t('notify.pipeline.paymentRecordedAsEntered', { entered: saved.payment_check.entered_amount, calculated: saved.payment_check.calculated_amount ?? '' }) : t('notify.pipeline.paymentRecorded'));
      setPayingFor(null);
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotRecordPayment')), 'error'),
  });

  return (
    <div>
      <PageHeader
        title={t('nav.temporaryReplacements')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.temporaryReplacements') }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              {t('action.newTemporaryReplacement')}
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label={t('option.pipeline.loadingTemporaryReplacements')} />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="refresh" title={t('option.pipeline.noTemporaryReplacementsYet')} />}

      {data && data.length > 0 && statusFilter && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm text-ink-muted">
          <span>
            {t('warning.pipeline.replacementsFilterNotice', {
              status: statusFilterWord,
              shown: visibleReplacements?.length ?? 0,
              total: data.length,
            })}
          </span>
          <button type="button" onClick={() => setSearchParams({}, { replace: true })} className="font-medium text-primary hover:underline">
            {t('action.pipeline.showAllReplacements')}
          </button>
        </div>
      )}

      {data && data.length > 0 && visibleReplacements && visibleReplacements.length === 0 && (
        <EmptyState icon="refresh" title={t('option.pipeline.noStatusTemporaryReplacements', { status: statusFilterWord })} />
      )}

      {visibleReplacements && visibleReplacements.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {visibleReplacements.map((replacement) => (
            <Card key={replacement.id} className="p-5">
              <div className="mb-3 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-display text-base font-bold text-ink">
                    {t('option.pipeline.coveringName', { name: replacement.replaced_employee_name ?? t('option.pipeline.unknown') })}
                  </h3>
                  <p className="text-xs text-ink-muted">
                    {[replacement.client_company_name, replacement.work_location_name].filter(Boolean).join(' · ') || t('option.workplaceNotSet')}
                  </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <StatusBadge
                    status={replacement.lifecycle}
                    label={t(LIFECYCLE_LABEL[replacement.lifecycle])}
                    tone={replacement.lifecycle === 'expired' ? 'danger' : replacement.lifecycle === 'running' ? 'warning' : replacement.lifecycle === 'scheduled' ? 'info' : 'neutral'}
                  />
                  <StatusBadge
                    status={replacement.approved ? 'approved' : 'awaiting-approval'}
                    label={replacement.approved ? t('status.pipeline.costApproved') : t('status.pipeline.costNotApproved')}
                    tone={replacement.approved ? 'success' : 'neutral'}
                  />
                </div>
              </div>

              <button
                type="button"
                onClick={() => navigate(`/hr/employees/${replacement.replacement_employee_id}`, { state: returnState(window.location.pathname, window.location.search, 'Temporary Replacements') })}
                className="mb-2 flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
              >
                <span className="text-ink">{t('option.pipeline.temporaryEmployeeName', { name: replacement.replacement_employee_name ?? '' })}</span>
              </button>

              <p className="mb-1 text-xs text-ink-faint">
                {formatDate(replacement.start_date)} → {replacement.end_date ? formatDate(replacement.end_date) : t('option.pipeline.ongoing')}
              </p>
              {replacement.planned_end_date && <p className="mb-1 text-xs text-ink-faint">{t('option.pipeline.plannedEndWas', { date: formatDate(replacement.planned_end_date) })}</p>}
              {replacement.created_by && <p className="mb-3 text-xs text-ink-faint">{t('option.pipeline.recordedBy', { name: replacement.created_by })}</p>}
              {replacement.is_expired && (
                <p role="status" className="mb-3 rounded-sm bg-danger-soft px-2.5 py-1.5 text-xs text-ink">{t('warning.pipeline.expiredNotice', { days: replacement.days_overdue })}</p>
              )}

              {replacement.reason && <p className="mb-3 text-xs italic text-ink-faint">{replacement.reason}</p>}

              <p className="mb-1.5 text-xs font-medium text-ink-faint">{t('option.pipeline.paymentsTotal', { total: replacement.total_paid })}</p>
              {replacement.payments.length > 0 ? (
                <div className="mb-3 space-y-1.5">
                  {replacement.payments.map((payment) => (
                    <div key={payment.id} className="flex items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-sm">
                      <span className="text-ink">{formatDate(payment.payment_date)}</span>
                      <span className="text-ink-muted">{payment.amount} {replacement.currency ?? 'USD'}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mb-3 text-xs italic text-ink-faint">{t('option.pipeline.noPaymentsYet')}</p>
              )}

              <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                <Button size="sm" variant="outline" onClick={() => setWorkdaysForId(replacement.id)}>
                  <Icon name="calendar" size={14} />
                  {t('action.pipeline.workdaysAndCost')}
                </Button>
                <PermissionGate module="hr">
                  <Button size="sm" variant="outline" onClick={() => setPayingFor(replacement)}>
                    {t('action.recordPayment')}
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setEditing(replacement)}>
                    {t('action.edit')}
                  </Button>
                  {replacement.is_expired && (
                    <Button size="sm" onClick={() => setClosing(replacement)}>
                      {t('action.pipeline.closeExpired')}
                    </Button>
                  )}
                  {replacement.status === 'active' && (
                    <Button size="sm" variant="ghost" onClick={() => setEnding(replacement)}>
                      {t('action.endCoverage')}
                    </Button>
                  )}
                </PermissionGate>
                <Button size="sm" variant="ghost" onClick={() => setHistoryOf(replacement)}>
                  {t('action.pipeline.history')}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <CreateReplacementModal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        onCreated={() => {
          queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
          setIsCreateOpen(false);
        }}
      />

      {workdaysFor && <WorkdaysModal replacement={workdaysFor} onClose={() => setWorkdaysForId(null)} />}

      {payingFor && (
        <RecordPaymentModal
          replacement={payingFor}
          onClose={() => setPayingFor(null)}
          onConfirm={(paymentDate, amount, notes, overrideReason) => paymentMutation.mutate({ id: payingFor.id, paymentDate, amount, notes, overrideReason })}
          isLoading={paymentMutation.isPending}
        />
      )}

      {ending && (
        <EndReplacementModal
          replacement={ending}
          onClose={() => setEnding(null)}
          onEnded={() => {
            refreshCoverFigures(ending.id);
            queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
            queryClient.invalidateQueries({ queryKey: ['hr-dashboard'] });
            setEnding(null);
          }}
        />
      )}

      {editing && (
        <EditReplacementModal
          replacement={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            refreshCoverFigures(editing.id);
            queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
            queryClient.invalidateQueries({ queryKey: ['hr-dashboard'] });
            setEditing(null);
          }}
        />
      )}

      {historyOf && <ReplacementHistoryModal replacement={historyOf} onClose={() => setHistoryOf(null)} />}

      <ConfirmDialog
        isOpen={closing !== null}
        title={t('confirm.pipeline.closeTitle')}
        description={closing ? t('confirm.pipeline.closeBody', { end: closing.end_date ? formatDate(closing.end_date) : '' }) : ''}
        confirmLabel={t('action.pipeline.closeReplacementConfirm')}
        isLoading={closeMutation.isPending}
        onConfirm={() => closing && closeMutation.mutate(closing.id)}
        onCancel={() => setClosing(null)}
      />
    </div>
  );
}

function CreateReplacementModal({ isOpen, onClose, onCreated }: { isOpen: boolean; onClose: () => void; onCreated: () => void }) {
  const { show } = useToast();
  const { t } = useI18n();

  const [coveredEmployeeId, setCoveredEmployeeId] = useState('');
  const [workAssignmentId, setWorkAssignmentId] = useState('');
  const [replacementEmployeeId, setReplacementEmployeeId] = useState('');
  const [startDate, setStartDate] = useState(localToday());
  const [endDate, setEndDate] = useState('');
  const [reason, setReason] = useState('');
  // A conflict the server returned when saving; it belongs to the exact choices made then and disappears as soon as any of them changes.
  const [refused, setRefused] = useState<{ key: string; conflict: CoverConflict } | null>(null);

  const fieldsKey = [workAssignmentId, replacementEmployeeId, startDate, endDate].join('|');
  const checkable = canCheckConflict({ workAssignmentId, replacementEmployeeId, startDate, endDate });
  // Asks the server the same question the save asks (overlap with another Temporary cover of the position or the person, or the person's own assignment).
  const { data: preview } = useQuery({
    queryKey: ['cover-conflict', fieldsKey],
    queryFn: () =>
      hrApi.temporaryReplacements.conflictCheck({
        work_assignment_id: Number(workAssignmentId),
        replacement_employee_id: Number(replacementEmployeeId),
        start_date: startDate,
        end_date: endDate || undefined,
      }),
    enabled: isOpen && checkable,
  });
  const conflict = (checkable ? preview?.conflict : null) ?? (refused?.key === fieldsKey ? refused.conflict : null);

  const { data: coveredEmployee } = useQuery({
    queryKey: ['employee', coveredEmployeeId],
    queryFn: () => hrApi.employees.get(Number(coveredEmployeeId)),
    enabled: isOpen && coveredEmployeeId !== '',
  });

  const createMutation = useMutation({
    mutationFn: () =>
      hrApi.temporaryReplacements.create({
        work_assignment_id: Number(workAssignmentId),
        replacement_employee_id: Number(replacementEmployeeId),
        start_date: startDate,
        end_date: endDate || null,
        reason: reason || undefined,
      }),
    onSuccess: () => {
      show(t('notify.pipeline.replacementRecorded'));
      setCoveredEmployeeId('');
      setWorkAssignmentId('');
      setReplacementEmployeeId('');
      setEndDate('');
      setReason('');
      onCreated();
    },
    onError: (err) => {
      const refusal = conflictFromError(err);
      if (refusal) setRefused({ key: fieldsKey, conflict: refusal });
      else show(friendlyErrorMessage(err, t('notify.pipeline.couldNotCreateReplacement')), 'error');
    },
  });

  if (!isOpen) return null;

  const assignmentOptions = (coveredEmployee?.active_work_assignments ?? []).map((a) => ({
    value: String(a.id),
    label: assignmentLabel(a),
  }));

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.newTemporaryReplacement')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            size="sm"
            isLoading={createMutation.isPending}
            disabled={!workAssignmentId || !replacementEmployeeId || !startDate || conflict !== null}
            onClick={() => createMutation.mutate()}
          >
            {t('action.create')}
          </Button>
        </>
      }
    >
      <p className="mb-4 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">{t('warning.pipeline.createReplacementNotice')}</p>
      <FormField label={t('option.pipeline.coveredEmployee')} htmlFor="tr-covered" required>
        <EmployeePicker
          id="tr-covered"
          employmentType="permanent"
          value={coveredEmployeeId}
          onChange={(value) => {
            setCoveredEmployeeId(value);
            setWorkAssignmentId('');
          }}
          excludeId={replacementEmployeeId}
          placeholder={t('option.pipeline.selectPermanent')}
        />
      </FormField>
      <FormField label={t('option.pipeline.assignmentCovered')} htmlFor="tr-assignment" required>
        <Select
          id="tr-assignment"
          value={workAssignmentId}
          onChange={(e) => setWorkAssignmentId(e.target.value)}
          placeholder={coveredEmployeeId ? t('option.pipeline.selectAssignment') : t('option.pipeline.selectEmployeeFirst')}
          options={assignmentOptions}
        />
      </FormField>
      <FormField label={t('option.pipeline.temporaryDoingCover')} htmlFor="tr-replacement" required>
        <EmployeePicker
          id="tr-replacement"
          employmentType="temporary"
          value={replacementEmployeeId}
          onChange={setReplacementEmployeeId}
          excludeId={coveredEmployeeId}
          placeholder={t('option.pipeline.selectTemporary')}
        />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label={t('option.pipeline.startDate')} htmlFor="tr-start" required>
          <input id="tr-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
        </FormField>
        <FormField label={t('option.pipeline.endDateOptional')} htmlFor="tr-end">
          <input id="tr-end" type="date" min={startDate} className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        </FormField>
      </div>
      <FormField label={t('option.pipeline.reason')} htmlFor="tr-reason">
        <textarea id="tr-reason" rows={2} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      {conflict && (
        <div role="alert" data-testid="cover-conflict" className="rounded-sm bg-danger-soft px-3 py-2 text-xs text-ink">
          <p className="font-semibold text-danger">{conflictHeading(conflict.code)}</p>
          <p className="mt-1">{conflictMessage(conflict)}</p>
          <p className="mt-1 text-ink-muted">{t('warning.pipeline.conflictNothingChanged')}</p>
        </div>
      )}
    </Modal>
  );
}

const WORKDAY_STATUS: Record<TemporaryReplacementWorkdayStatus, { label: string; tone: 'warning' | 'success' | 'neutral' }> = {
  recorded: { label: 'Recorded - not verified yet', tone: 'warning' },
  verified: { label: 'Verified', tone: 'success' },
  void: { label: 'Voided', tone: 'neutral' },
};

/**
 * The workday ledger for one replacement: which days the Temporary employee
 * really worked, which are verified, and what the month costs. Only VERIFIED
 * days are charged. The cost preview is shown only to people who may view payroll.
 */
function WorkdaysModal({ replacement, onClose }: { replacement: TemporaryReplacement; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const { hasPermission } = useEffectivePermissions();
  const canViewPayroll = hasPermission('hr.payroll.view');
  const canApprove = hasPermission('hr.payroll.approve');

  const [dates, setDates] = useState<string[]>([]);
  const [rangeFrom, setRangeFrom] = useState(localToday());
  const [rangeTo, setRangeTo] = useState('');
  const [notes, setNotes] = useState('');
  const [period, setPeriod] = useState(localMonth());
  const [voiding, setVoiding] = useState<TemporaryReplacementWorkday | null>(null);
  const [isApproveOpen, setIsApproveOpen] = useState(false);

  const today = localToday();
  const latestDate = replacement.end_date && replacement.end_date < today ? replacement.end_date : today;

  const { data: workdays, isLoading, error } = useQuery({
    queryKey: ['replacement-workdays', replacement.id],
    queryFn: () => hrApi.temporaryReplacements.workdays.list(replacement.id),
  });

  const { data: cost, isLoading: isCostLoading, error: costError } = useQuery({
    queryKey: ['replacement-cost', replacement.id, period],
    queryFn: () => hrApi.temporaryReplacements.cost(replacement.id, period),
    enabled: canViewPayroll && /^\d{4}-(0[1-9]|1[0-2])$/.test(period),
  });

  const refreshLedger = () => {
    queryClient.invalidateQueries({ queryKey: ['replacement-workdays', replacement.id] });
    queryClient.invalidateQueries({ queryKey: ['replacement-cost', replacement.id] });
  };

  const recordMutation = useMutation({
    mutationFn: (verify: boolean) =>
      hrApi.temporaryReplacements.workdays.record(replacement.id, { dates, verify, notes: notes || null }),
    onSuccess: (_result, verify) => {
      refreshLedger();
      show(verify ? t('notify.pipeline.daysRecordedVerified', { count: dates.length }) : t('notify.pipeline.daysRecorded', { count: dates.length }));
      setDates([]);
      setNotes('');
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotRecordDays')), 'error'),
  });

  const verifyMutation = useMutation({
    mutationFn: (workdayId: number) => hrApi.temporaryReplacements.workdays.verify(replacement.id, workdayId),
    onSuccess: () => {
      refreshLedger();
      show(t('notify.pipeline.dayVerified'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotVerifyDay')), 'error'),
  });

  const voidMutation = useMutation({
    mutationFn: ({ workdayId, reason }: { workdayId: number; reason: string }) =>
      hrApi.temporaryReplacements.workdays.void(replacement.id, workdayId, reason),
    onSuccess: () => {
      refreshLedger();
      setVoiding(null);
      show(t('notify.pipeline.dayVoided'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotVoidDay')), 'error'),
  });

  const approveMutation = useMutation({
    mutationFn: () => hrApi.temporaryReplacements.approve(replacement.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      refreshLedger();
      setIsApproveOpen(false);
      show(t('notify.pipeline.replacementApproved'));
    },
    onError: (err) => {
      setIsApproveOpen(false);
      show(friendlyErrorMessage(err, t('notify.pipeline.couldNotApprove')), 'error');
    },
  });

  const knownDates = new Set((workdays ?? []).filter((day) => day.status !== 'void').map((day) => day.work_date));

  function addRange() {
    const expanded = expandDateRange(rangeFrom, rangeTo || rangeFrom);
    if (expanded.length === 0) {
      show(t('validation.pipeline.invalidDayRange'), 'error');
      return;
    }
    const fresh = expanded.filter((date) => !knownDates.has(date) && !dates.includes(date));
    const skipped = expanded.length - fresh.length;
    setDates([...dates, ...fresh].sort());
    if (skipped > 0) show(t('notify.pipeline.daysSkipped', { count: skipped }));
  }

  const sortedWorkdays = [...(workdays ?? [])].sort((a, b) => b.work_date.localeCompare(a.work_date) || b.id - a.id);
  const coveredName = replacement.replaced_employee_name ?? t('option.pipeline.coveredEmployeeFallback');

  return (
    <>
      <Modal
        isOpen
        onClose={onClose}
        title={t('confirm.pipeline.workdaysTitle', { name: replacement.replacement_employee_name ?? t('option.pipeline.temporaryEmployeeFallback') })}
        size="lg"
        footer={<Button variant="outline" size="sm" onClick={onClose}>{t('action.close')}</Button>}
      >
        <p className="mb-5 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
          <Msg
            id="warning.pipeline.workdaysNotice"
            values={{ coverage: <strong>{t('warning.pipeline.coverageDaysStrong')}</strong>, name: coveredName }}
          />
        </p>

        <h3 className="mb-2 font-display text-sm font-bold text-ink">{t('option.pipeline.daysWorked')}</h3>
        {isLoading && <p className="text-xs text-ink-faint">{t('option.pipeline.loadingDays')}</p>}
        {error && <p className="text-xs text-danger">{friendlyErrorMessage(error, t('notify.pipeline.couldNotLoadDays'))}</p>}
        {workdays && sortedWorkdays.length === 0 && <p className="mb-4 text-xs italic text-ink-faint">{t('option.pipeline.noDaysYet')}</p>}
        {sortedWorkdays.length > 0 && (
          <div className="mb-5 space-y-1.5">
            {sortedWorkdays.map((day) => (
              <div key={day.id} className="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-border px-2.5 py-1.5 text-sm">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-ink">
                    {formatDate(day.work_date)}
                    <StatusBadge
                      status={day.status}
                      label={tValue('status.pipeline.workday', day.status) ?? WORKDAY_STATUS[day.status].label}
                      tone={WORKDAY_STATUS[day.status].tone}
                    />
                  </p>
                  <p className="text-xs text-ink-faint">
                    {day.status === 'void'
                      ? day.void_reason
                        ? t('status.pipeline.dayVoidedReason', { reason: day.void_reason })
                        : t('status.pipeline.dayVoided')
                      : day.status === 'verified'
                        ? day.verified_by
                          ? t('status.pipeline.dayVerifiedBy', { name: day.verified_by })
                          : t('status.pipeline.dayVerified')
                        : day.recorded_by
                          ? t('status.pipeline.dayRecordedBy', { name: day.recorded_by })
                          : t('status.pipeline.dayRecorded')}
                    {day.notes && ` · ${day.notes}`}
                  </p>
                </div>
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1">
                    {day.status === 'recorded' && (
                      <Button size="sm" variant="outline" isLoading={verifyMutation.isPending && verifyMutation.variables === day.id} onClick={() => verifyMutation.mutate(day.id)}>
                        {t('action.verifyWorkday')}
                      </Button>
                    )}
                    {day.status !== 'void' && (
                      <Button size="sm" variant="ghost" onClick={() => setVoiding(day)}>
                        {t('action.void')}
                      </Button>
                    )}
                  </div>
                </PermissionGate>
              </div>
            ))}
          </div>
        )}

        <PermissionGate module="hr">
          <div className="mb-5 rounded-sm border border-border p-3">
            <h3 className="mb-2 font-display text-sm font-bold text-ink">{t('option.pipeline.addDays')}</h3>
            <div className="flex flex-wrap items-end gap-2">
              <div>
                <label htmlFor="wd-from" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.pipeline.from')}</label>
                <input id="wd-from" type="date" min={replacement.start_date} max={latestDate} className={inputClasses} value={rangeFrom} onChange={(e) => setRangeFrom(e.target.value)} />
              </div>
              <div>
                <label htmlFor="wd-to" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.pipeline.toOptional')}</label>
                <input id="wd-to" type="date" min={rangeFrom || replacement.start_date} max={latestDate} className={inputClasses} value={rangeTo} onChange={(e) => setRangeTo(e.target.value)} />
              </div>
              <Button size="sm" variant="outline" onClick={addRange}>
                <Icon name="plus" size={14} />
                {t('action.pipeline.addToList')}
              </Button>
            </div>

            {dates.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-1.5">
                {dates.map((date) => (
                  <span key={date} className="inline-flex items-center gap-1 rounded-full bg-surface-alt px-2.5 py-1 text-xs text-ink">
                    {formatDate(date)}
                    <button
                      type="button"
                      aria-label={t('action.pipeline.removeDate', { date })}
                      onClick={() => setDates(dates.filter((d) => d !== date))}
                      className="text-ink-muted hover:text-danger"
                    >
                      <Icon name="x" size={12} />
                    </button>
                  </span>
                ))}
              </div>
            )}

            <div className="mt-3">
              <label htmlFor="wd-notes" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.pipeline.noteOptional')}</label>
              <input id="wd-notes" className={inputClasses} value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} />
            </div>
            <p className="mt-2 text-xs text-ink-faint">{t('warning.pipeline.daysCheckedTogether')}</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Button size="sm" variant="outline" disabled={dates.length === 0} isLoading={recordMutation.isPending && recordMutation.variables === false} onClick={() => recordMutation.mutate(false)}>
                {t('action.recordDays')}
              </Button>
              <Button size="sm" disabled={dates.length === 0} isLoading={recordMutation.isPending && recordMutation.variables === true} onClick={() => recordMutation.mutate(true)}>
                {t('action.recordAndVerify')}
              </Button>
            </div>
          </div>
        </PermissionGate>

        {canViewPayroll && (
          <div className="rounded-sm border border-border p-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-display text-sm font-bold text-ink">{t('option.pipeline.costForMonth')}</h3>
              <input type="month" aria-label={t('option.label.month')} className={`${inputClasses} h-8 w-40 text-xs`} value={period} onChange={(e) => setPeriod(e.target.value)} />
            </div>
            {isCostLoading && <p className="text-xs text-ink-faint">{t('option.pipeline.calculating')}</p>}
            {costError && <p className="text-xs text-danger">{friendlyErrorMessage(costError, t('notify.pipeline.couldNotWorkOutCost'))}</p>}
            {cost && (
              <dl className="space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.pipeline.daysCovered')}</dt>
                  <dd className="text-ink">{cost.covered_days ?? cost.verified_days}</dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-ink-muted">{t('option.pipeline.dailyRateLabel')}</dt>
                  <dd className="text-right text-ink">{describeDailyRate(cost.daily_rate)}</dd>
                </div>
                <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                  <dt className="text-ink">{t('option.pipeline.costForThisMonth')}</dt>
                  <dd className="text-ink">{formatCurrency(Number(cost.amount))}</dd>
                </div>
                {cost.charged_dates.length > 0 && (
                  <p className="text-xs text-ink-faint">{t('option.pipeline.chargedDays', { dates: cost.charged_dates.map((date) => formatDate(date)).join(', ') })}</p>
                )}
                {cost.ignored.unverified > 0 && (
                  <p className="text-xs text-warning">{t('warning.pipeline.unverifiedDays', { count: cost.ignored.unverified })}</p>
                )}
                {cost.ignored.outside_window_or_month > 0 && (
                  <p className="text-xs text-ink-faint">{t('warning.pipeline.outsideDays', { count: cost.ignored.outside_window_or_month })}</p>
                )}
                {cost.ignored.duplicates > 0 && (
                  <p className="text-xs text-ink-faint">{t('warning.pipeline.duplicateDays', { count: cost.ignored.duplicates })}</p>
                )}
              </dl>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
              {replacement.approved ? (
                <StatusBadge
                  status="approved"
                  label={replacement.approved_at ? t('status.pipeline.approvedOn', { date: formatDate(replacement.approved_at) }) : t('status.pipeline.approved')}
                  tone="success"
                />
              ) : (
                <>
                  <StatusBadge status="awaiting-approval" label={t('status.pipeline.notApprovedYet')} tone="neutral" />
                  {canApprove && (
                    <Button size="sm" onClick={() => setIsApproveOpen(true)}>
                      {t('action.approveCost')}
                    </Button>
                  )}
                </>
              )}
            </div>
          </div>
        )}
      </Modal>

      {voiding && (
        <VoidWorkdayModal
          workday={voiding}
          onClose={() => setVoiding(null)}
          onConfirm={(reason) => voidMutation.mutate({ workdayId: voiding.id, reason })}
          isLoading={voidMutation.isPending}
        />
      )}

      <ConfirmDialog
        isOpen={isApproveOpen}
        title={t('confirm.pipeline.approveTitle')}
        description={t('confirm.pipeline.approveBody', { name: coveredName })}
        confirmLabel={t('action.approveReplacementCost')}
        isLoading={approveMutation.isPending}
        onConfirm={() => approveMutation.mutate()}
        onCancel={() => setIsApproveOpen(false)}
      />
    </>
  );
}

function VoidWorkdayModal({
  workday,
  onClose,
  onConfirm,
  isLoading,
}: {
  workday: TemporaryReplacementWorkday;
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
      title={t('confirm.pipeline.voidTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button variant="danger" size="sm" isLoading={isLoading} disabled={reason.trim().length < 5} onClick={() => onConfirm(reason.trim())}>
            {t('action.voidDay')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.pipeline.voidBody', { date: formatDate(workday.work_date) })}</p>
      <FormField label={t('option.pipeline.reason')} htmlFor="void-reason" required hint={t('validation.pipeline.minFiveCharacters')}>
        <textarea id="void-reason" rows={2} maxLength={500} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordPaymentModal({
  replacement,
  onClose,
  onConfirm,
  isLoading,
}: {
  replacement: TemporaryReplacement;
  onClose: () => void;
  onConfirm: (paymentDate: string, amount: number, notes: string, overrideReason: string) => void;
  isLoading: boolean;
}) {
  const { t, tValue } = useI18n();
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState('');
  const [touched, setTouched] = useState(false);
  const [notes, setNotes] = useState('');
  const [overrideReason, setOverrideReason] = useState('');

  // The expected payment: every calendar day of the cover up to the payment date, minus what was already paid (docs/HR_PAYROLL_RULES.md N5, N9-N11).
  // Only payroll viewers can read it; without it the amount is simply typed, as before.
  const expected = useQuery({
    queryKey: ['temporary-replacements', 'expected-payment', replacement.id, paymentDate],
    queryFn: () => hrApi.temporaryReplacements.expectedPayment(replacement.id, paymentDate),
    retry: false,
  });
  const balance = expected.data?.balance ?? null;

  // Prefill with the calculated amount until the person types their own.
  useEffect(() => {
    if (!touched && balance !== null) setAmount(balance);
  }, [balance, touched]);

  const differs = paymentDiffers(balance, amount);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.recordPayment')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} disabled={amount === ''} onClick={() => onConfirm(paymentDate, Number(amount), notes, differs ? overrideReason.trim() : '')}>{differs ? t('action.recordAsEntered') : t('action.record')}</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{replacement.replacement_employee_name}</p>
      <FormField label={t('option.pipeline.paymentDate')} htmlFor="pay-date" required>
        <input id="pay-date" type="date" className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
      {expected.data && (
        <div className="mb-4 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted" data-testid="expected-payment">
          <p>
            <Msg
              id={expected.data.through ? 'warning.pipeline.calcSummaryThrough' : 'warning.pipeline.calcSummary'}
              values={{
                amount: <strong className="text-ink">{expected.data.balance} {expected.data.currency}</strong>,
                days: expected.data.counted_days,
                date: expected.data.through ? formatDate(expected.data.through) : '',
                paid: expected.data.paid_so_far,
              }}
            />
          </p>
          {expected.data.ended_early && (
            <p>
              {t('warning.pipeline.endedEarly', {
                planned: expected.data.planned_end_date ? formatDate(expected.data.planned_end_date) : '—',
                actual: expected.data.actual_end_date ? formatDate(expected.data.actual_end_date) : '—',
              })}
            </p>
          )}
          {expected.data.warnings.map((w) => <p key={w.code} className="mt-1">{tValue('warning.expected', w.code) ?? w.message}</p>)}
        </div>
      )}
      <FormField label={t('option.pipeline.amount')} htmlFor="pay-amount" required hint={balance === null ? undefined : t('warning.pipeline.expectedHint')}>
        <input id="pay-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => { setTouched(true); setAmount(e.target.value); }} />
      </FormField>
      {differs && balance !== null && (
        <div className="mb-4 rounded-sm border border-warning px-3 py-2 text-xs text-ink" role="alert" data-testid="override-warning">
          <p>{overrideWarning(balance, amount, expected.data?.currency)}</p>
          <FormField label={t('option.pipeline.whyDifferent')} htmlFor="pay-override">
            <input id="pay-override" className={inputClasses} value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} maxLength={500} />
          </FormField>
        </div>
      )}
      <FormField label={t('option.pipeline.notes')} htmlFor="pay-notes">
        <textarea id="pay-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

