import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/useToast';
import { conflictHeading, conflictMessage } from '@/utils/coverConflict';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import {
  canEndCoverage,
  centsToAmount,
  defaultLastDay,
  describeEndChange,
  isIsoDate,
  validChangeReason,
  validPeriod,
} from '@/utils/replacementChange';
import type { ReplacementChangePreview, ReplacementHistoryEntry, TemporaryReplacement } from '@/types/employee';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { EmployeePicker } from './ReplacementEmployeePicker';

/**
 * What a change would do, as the server worked it out without saving anything: the days before and after, the cost of each month (for people who may see
 * payroll), the months that are already approved or finalized, whether the approval has to be given again, and whether the change would be refused.
 */
function ChangePreviewPanel({ preview, loading }: { preview: ReplacementChangePreview | undefined; loading: boolean }) {
  const { t } = useI18n();

  if (!preview) return loading ? <p className="mb-3 text-xs text-ink-faint">{t('warning.pipeline.previewLoading')}</p> : null;

  const showMoney = preview.sees_money && preview.cost_before_cents !== undefined;

  return (
    <div className="mb-4 space-y-2 rounded-sm bg-surface-alt px-3 py-2.5 text-xs text-ink-muted" data-testid="change-preview">
      <p className="font-medium text-ink">{t('option.pipeline.daysCounted', { before: preview.days_before, after: preview.days_after })}</p>
      {showMoney && (
        <dl className="grid grid-cols-3 gap-2">
          <div>
            <dt>{t('option.pipeline.costBefore')}</dt>
            <dd className="font-semibold text-ink">{centsToAmount(preview.cost_before_cents ?? 0)}</dd>
          </div>
          <div>
            <dt>{t('option.pipeline.costAfter')}</dt>
            <dd className="font-semibold text-ink">{centsToAmount(preview.cost_after_cents ?? 0)}</dd>
          </div>
          <div>
            <dt>{t('option.pipeline.costChange')}</dt>
            <dd className="font-semibold text-ink">{centsToAmount(preview.cost_delta_cents ?? 0)}</dd>
          </div>
        </dl>
      )}
      {showMoney && preview.months.length > 0 && (
        <table className="w-full text-left">
          <caption className="sr-only">{t('option.pipeline.costByMonth')}</caption>
          <thead>
            <tr>
              <th className="pr-2 font-medium">{t('option.pipeline.monthColumn')}</th>
              <th className="pr-2 font-medium">{t('option.pipeline.daysColumn')}</th>
              <th className="pr-2 font-medium">{t('option.pipeline.costBefore')}</th>
              <th className="font-medium">{t('option.pipeline.costAfter')}</th>
            </tr>
          </thead>
          <tbody>
            {preview.months.map((month) => (
              <tr key={month.period}>
                <td className="pr-2 text-ink">
                  {month.period}
                  {month.locked && <span className="ml-1 text-danger">({t('option.pipeline.monthClosed')})</span>}
                </td>
                <td className="pr-2">{month.days_before} → {month.days_after}</td>
                <td className="pr-2">{month.cost_before_cents == null ? '—' : centsToAmount(month.cost_before_cents)}</td>
                <td>{month.cost_after_cents == null ? '—' : centsToAmount(month.cost_after_cents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {preview.approval_will_be_cleared && <p className="text-warning">{t('warning.pipeline.approvalWillBeCleared')}</p>}
      {preview.changes_locked_period && (
        <p className="text-danger" role="alert">
          {t('warning.pipeline.lockedMonths', { months: preview.locked_periods_changed.join(', ') })}
          {preview.requires_super_admin && ` ${t('warning.pipeline.superAdminOnly')}`}
        </p>
      )}
    </div>
  );
}

function ConflictBox({ preview }: { preview: ReplacementChangePreview | undefined }) {
  const { t } = useI18n();
  const conflict = preview?.conflict ?? null;
  const error = preview?.errors?.[0] ?? null;

  if (!conflict && !error) return null;

  return (
    <div role="alert" data-testid="cover-conflict" className="mb-4 rounded-sm bg-danger-soft px-3 py-2 text-xs text-ink">
      {conflict ? (
        <>
          <p className="font-semibold text-danger">{conflictHeading(conflict.code)}</p>
          <p className="mt-1">{conflictMessage(conflict)}</p>
          <p className="mt-1 text-ink-muted">{t('warning.pipeline.conflictNothingChanged')}</p>
        </>
      ) : (
        <p className="font-semibold text-danger">{error?.message}</p>
      )}
    </div>
  );
}

/** Reason box shown only when the change reaches approved or finalized payroll (Super Admin, written reason). */
function ClosedPayrollReason({ preview, value, onChange }: { preview: ReplacementChangePreview | undefined; value: string; onChange: (value: string) => void }) {
  const { t } = useI18n();

  if (!preview?.requires_reason_for_closed_payroll || preview.requires_super_admin) return null;

  return (
    <FormField label={t('option.pipeline.closedPayrollReason')} htmlFor="tr-closed-reason" required hint={t('validation.pipeline.closedPayrollReasonHint')}>
      <textarea id="tr-closed-reason" rows={2} maxLength={1000} className={`${inputClasses} h-auto py-2`} value={value} onChange={(e) => onChange(e.target.value)} />
    </FormField>
  );
}

// ------------------------------------------------------------------------------------------------------------------ End Coverage

/**
 * End Coverage. The last day is never filled in with today's date: it starts from the planned end date (or empty when there is none) and the person types the
 * day the cover really stopped. A day later than the recorded end lengthens the cover, so the old and new dates, the added days and the new cost are shown
 * and the person has to confirm before it can be saved. A change that reaches approved or finalized payroll is a Super Admin correction with a written reason.
 */
export function EndReplacementModal({ replacement, onClose, onEnded }: { replacement: TemporaryReplacement; onClose: () => void; onEnded: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const [lastDay, setLastDay] = useState(defaultLastDay(replacement.end_date));
  const [confirmed, setConfirmed] = useState(false);
  const [reason, setReason] = useState('');

  const change = describeEndChange(replacement.start_date, replacement.end_date, lastDay);
  const valid = change.kind !== 'missing' && change.kind !== 'invalid';

  const { data: preview, isFetching } = useQuery({
    queryKey: ['replacement-change-preview', 'end', replacement.id, lastDay],
    queryFn: () => hrApi.temporaryReplacements.changePreview(replacement.id, { end_date: lastDay }),
    enabled: valid && isIsoDate(lastDay),
  });

  const blocked = preview?.conflict != null || (preview?.errors?.length ?? 0) > 0;
  const needsSuperAdmin = preview?.requires_super_admin === true;
  const closedReasonOk = !preview?.requires_reason_for_closed_payroll || needsSuperAdmin || validChangeReason(reason);

  const endMutation = useMutation({
    mutationFn: () =>
      hrApi.temporaryReplacements.end(replacement.id, {
        end_date: lastDay,
        confirm_extension: change.kind === 'extension' && confirmed ? true : undefined,
        reason: preview?.requires_reason_for_closed_payroll ? reason.trim() : undefined,
        version: replacement.version,
      }),
    onSuccess: () => {
      show(t('notify.pipeline.coverageEndedOn', { day: formatDate(lastDay) }));
      onEnded();
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotEndCoverage')), 'error'),
  });

  const plannedEnd = replacement.end_date;
  const days = Math.abs(change.daysDelta);
  const today = preview?.today ?? new Date().toISOString().slice(0, 10);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.endCoverage')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            variant={change.kind === 'extension' ? 'danger' : 'primary'}
            size="sm"
            isLoading={endMutation.isPending}
            disabled={!canEndCoverage(change, confirmed, blocked, needsSuperAdmin, '') || !closedReasonOk || isFetching}
            onClick={() => endMutation.mutate()}
          >
            {t('action.endCoverage')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{replacement.replacement_employee_name}</p>
      <dl className="mb-4 grid grid-cols-3 gap-2 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
        <div>
          <dt>{t('option.pipeline.startDate')}</dt>
          <dd className="font-semibold text-ink">{formatDate(replacement.start_date)}</dd>
        </div>
        <div>
          <dt>{t('option.pipeline.plannedEnd')}</dt>
          <dd className="font-semibold text-ink">{plannedEnd ? formatDate(plannedEnd) : t('option.pipeline.noPlannedEnd')}</dd>
        </div>
        <div>
          <dt>{t('option.pipeline.todayIs')}</dt>
          <dd className="font-semibold text-ink">{formatDate(today)}</dd>
        </div>
      </dl>

      <FormField label={t('option.pipeline.lastDayOfCover')} htmlFor="tr-end-date" required hint={t('warning.pipeline.lastDayHint')}>
        <input
          id="tr-end-date"
          type="date"
          min={replacement.start_date}
          className={inputClasses}
          value={lastDay}
          onChange={(e) => {
            setLastDay(e.target.value);
            setConfirmed(false);
          }}
        />
      </FormField>

      {change.kind === 'same' && <p className="mb-3 text-xs text-ink-muted">{t('warning.pipeline.endSameAsPlan')}</p>}
      {change.kind === 'shorter' && plannedEnd && <p className="mb-3 text-xs text-ink-muted">{t('warning.pipeline.endEarlier', { days, planned: formatDate(plannedEnd) })}</p>}
      {change.kind === 'first' && <p className="mb-3 text-xs text-ink-muted">{t('warning.pipeline.endFirst', { day: formatDate(lastDay) })}</p>}
      {valid && lastDay !== today && <p className="mb-3 text-xs text-ink-faint">{t('warning.pipeline.lastDayNotToday', { today: formatDate(today), day: formatDate(lastDay) })}</p>}

      {change.kind === 'extension' && plannedEnd && (
        <div role="alert" data-testid="extension-warning" className="mb-4 rounded-sm border border-danger px-3 py-2 text-xs text-ink">
          <p className="font-semibold text-danger">{t('confirm.pipeline.extensionTitle')}</p>
          <p className="mt-1">{t('warning.pipeline.extensionBody', { oldEnd: formatDate(plannedEnd), newEnd: formatDate(lastDay), days })}</p>
          <dl className="mt-2 grid grid-cols-3 gap-2 text-ink-muted">
            <div>
              <dt>{t('option.pipeline.oldEnd')}</dt>
              <dd className="font-semibold text-ink">{formatDate(plannedEnd)}</dd>
            </div>
            <div>
              <dt>{t('option.pipeline.newEnd')}</dt>
              <dd className="font-semibold text-ink">{formatDate(lastDay)}</dd>
            </div>
            <div>
              <dt>{t('option.pipeline.addedDays')}</dt>
              <dd className="font-semibold text-ink">{days}</dd>
            </div>
          </dl>
          <label className="mt-3 flex items-start gap-2 text-ink">
            <input type="checkbox" className="mt-0.5" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
            <span>{t('confirm.pipeline.confirmExtension')}</span>
          </label>
        </div>
      )}

      <ConflictBox preview={preview} />
      {valid && <ChangePreviewPanel preview={preview} loading={isFetching} />}
      <ClosedPayrollReason preview={preview} value={reason} onChange={setReason} />
    </Modal>
  );
}

// ------------------------------------------------------------------------------------------------------------------ Edit

/**
 * Correction of a saved cover: the dates, the Temporary employee, the reason and the notes. The covered employee, the assignment and the payments are not
 * on this form and cannot be sent. A written reason is required and is kept with who made the change and the before and after values.
 */
export function EditReplacementModal({ replacement, onClose, onSaved }: { replacement: TemporaryReplacement; onClose: () => void; onSaved: () => void }) {
  const { t } = useI18n();
  const { show } = useToast();
  const [start, setStart] = useState(replacement.start_date);
  const [end, setEnd] = useState(replacement.end_date ?? '');
  const [employeeId, setEmployeeId] = useState(String(replacement.replacement_employee_id));
  const [reason, setReason] = useState(replacement.reason ?? '');
  const [notes, setNotes] = useState(replacement.notes ?? '');
  const [whyChanged, setWhyChanged] = useState('');
  const [closedReason, setClosedReason] = useState('');

  const ended = replacement.status === 'ended';
  const periodOk = validPeriod(start, end) && (!ended || end !== '');
  const employeeChanged = employeeId !== String(replacement.replacement_employee_id);
  const datesChanged = start !== replacement.start_date || end !== (replacement.end_date ?? '');
  const changed = datesChanged || employeeChanged || reason !== (replacement.reason ?? '') || notes !== (replacement.notes ?? '');

  const { data: preview, isFetching } = useQuery({
    queryKey: ['replacement-change-preview', 'edit', replacement.id, start, end, employeeId],
    queryFn: () =>
      hrApi.temporaryReplacements.changePreview(replacement.id, {
        start_date: start,
        end_date: end === '' ? null : end,
        replacement_employee_id: employeeChanged ? Number(employeeId) : undefined,
      }),
    enabled: periodOk,
  });

  const blocked = preview?.conflict != null || (preview?.errors?.length ?? 0) > 0 || (employeeChanged && preview?.employee_locked_by_records === true);
  const needsSuperAdmin = preview?.requires_super_admin === true;
  const closedReasonOk = !preview?.requires_reason_for_closed_payroll || needsSuperAdmin || validChangeReason(closedReason);

  const saveMutation = useMutation({
    mutationFn: () =>
      hrApi.temporaryReplacements.update(replacement.id, {
        start_date: start,
        end_date: end === '' ? null : end,
        replacement_employee_id: employeeChanged ? Number(employeeId) : undefined,
        reason: reason.trim() === '' ? null : reason.trim(),
        notes: notes.trim() === '' ? null : notes.trim(),
        change_reason: preview?.requires_reason_for_closed_payroll && validChangeReason(closedReason) ? closedReason.trim() : whyChanged.trim(),
        version: replacement.version,
      }),
    onSuccess: () => {
      show(t('notify.pipeline.replacementCorrected'));
      onSaved();
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.pipeline.couldNotCorrect')), 'error'),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.pipeline.editTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            size="sm"
            isLoading={saveMutation.isPending}
            disabled={!changed || !periodOk || !validChangeReason(whyChanged) || blocked || needsSuperAdmin || !closedReasonOk || isFetching}
            onClick={() => saveMutation.mutate()}
          >
            {t('action.pipeline.saveCorrection')}
          </Button>
        </>
      }
    >
      <p className="mb-4 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">{t('warning.pipeline.editNotice')}</p>
      <p className="mb-3 text-sm text-ink">
        {t('option.pipeline.coveringName', { name: replacement.replaced_employee_name ?? t('option.pipeline.unknown') })}
      </p>

      <div className="grid grid-cols-2 gap-3">
        <FormField label={t('option.pipeline.startDate')} htmlFor="tr-edit-start" required>
          <input id="tr-edit-start" type="date" className={inputClasses} value={start} onChange={(e) => setStart(e.target.value)} />
        </FormField>
        <FormField label={ended ? t('option.pipeline.lastDayOfCover') : t('option.pipeline.plannedEndOptional')} htmlFor="tr-edit-end" required={ended}>
          <input id="tr-edit-end" type="date" min={start} className={inputClasses} value={end} onChange={(e) => setEnd(e.target.value)} />
        </FormField>
      </div>

      <FormField label={t('option.pipeline.temporaryDoingCover')} htmlFor="tr-edit-replacement" required>
        <EmployeePicker
          id="tr-edit-replacement"
          employmentType="temporary"
          value={employeeId}
          onChange={setEmployeeId}
          placeholder={t('option.pipeline.selectTemporary')}
          current={{ value: String(replacement.replacement_employee_id), label: replacement.replacement_employee_name ?? '' }}
        />
      </FormField>
      {employeeChanged && preview?.employee_locked_by_records && <p className="mb-3 text-xs text-danger" role="alert">{t('warning.pipeline.employeeLocked')}</p>}

      <FormField label={t('option.pipeline.reason')} htmlFor="tr-edit-reason">
        <textarea id="tr-edit-reason" rows={2} className={`${inputClasses} h-auto py-2`} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.pipeline.notes')} htmlFor="tr-edit-notes">
        <textarea id="tr-edit-notes" rows={2} className={`${inputClasses} h-auto py-2`} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>

      <ConflictBox preview={preview} />
      {periodOk && datesChanged && <ChangePreviewPanel preview={preview} loading={isFetching} />}
      <ClosedPayrollReason preview={preview} value={closedReason} onChange={setClosedReason} />

      <FormField label={t('option.pipeline.whyCorrecting')} htmlFor="tr-edit-why" required hint={t('validation.pipeline.minTenCharacters')}>
        <textarea id="tr-edit-why" rows={2} maxLength={1000} className={`${inputClasses} h-auto py-2`} value={whyChanged} onChange={(e) => setWhyChanged(e.target.value)} />
      </FormField>
    </Modal>
  );
}

// ------------------------------------------------------------------------------------------------------------------ History

type HistoryKind = 'created' | 'corrected' | 'ended' | 'closed' | 'approved' | 'other';

const EVENT_KIND: Record<string, HistoryKind> = {
  temporary_replacement_corrected: 'corrected',
  temporary_replacement_ended: 'ended',
  temporary_replacement_closed_expired: 'closed',
};

const EVENT_LABEL: Record<HistoryKind, MessageKey> = {
  created: 'option.pipeline.historyEvent.created',
  corrected: 'option.pipeline.historyEvent.corrected',
  ended: 'option.pipeline.historyEvent.ended',
  closed: 'option.pipeline.historyEvent.closed',
  approved: 'option.pipeline.historyEvent.approved',
  other: 'option.pipeline.historyEvent.other',
};

/** Who changed what, when and why, newest first. Money is shown by the server only to people who may see payroll. */
export function ReplacementHistoryModal({ replacement, onClose }: { replacement: TemporaryReplacement; onClose: () => void }) {
  const { t } = useI18n();
  const { data, isLoading, error } = useQuery({
    queryKey: ['temporary-replacement-history', replacement.id],
    queryFn: () => hrApi.temporaryReplacements.history(replacement.id),
    staleTime: 0,
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.pipeline.historyTitle', { name: replacement.replacement_employee_name ?? '' })}
      size="md"
      footer={<Button variant="outline" size="sm" onClick={onClose}>{t('action.close')}</Button>}
    >
      {isLoading && <p className="text-xs text-ink-faint">{t('warning.pipeline.previewLoading')}</p>}
      {error && <p className="text-xs text-danger">{friendlyErrorMessage(error, t('notify.pipeline.couldNotLoadDays'))}</p>}
      {data && data.length === 0 && <p className="text-sm italic text-ink-faint">{t('option.pipeline.historyEmpty')}</p>}
      <ol className="space-y-3">
        {(data ?? []).map((entry) => (
          <HistoryRow key={entry.id} entry={entry} />
        ))}
      </ol>
    </Modal>
  );
}

function fieldLine(label: string, before: unknown, after: unknown): string | null {
  const a = before === null || before === undefined || before === '' ? '—' : String(before);
  const b = after === null || after === undefined || after === '' ? '—' : String(after);

  return a === b ? null : `${label}: ${a} → ${b}`;
}

function HistoryRow({ entry }: { entry: ReplacementHistoryEntry }) {
  const { t } = useI18n();
  const details = entry.details as Record<string, unknown> & { event?: string; reason?: string; before?: Record<string, unknown>; after?: Record<string, unknown>; approval_cleared?: boolean };
  const kind: HistoryKind = EVENT_KIND[details.event ?? ''] ?? (entry.action === 'create' ? 'created' : entry.action === 'approve' ? 'approved' : 'other');
  const before = details.before ?? {};
  const after = details.after ?? {};

  const lines = [
    fieldLine(t('option.pipeline.startDate'), before.start_date, after.start_date),
    fieldLine(t('option.pipeline.endDate'), before.end_date, after.end_date),
    fieldLine(t('option.pipeline.temporaryDoingCover'), before.replacement_employee, after.replacement_employee),
    fieldLine(t('option.pipeline.reason'), before.reason, after.reason),
    fieldLine(t('option.pipeline.notes'), before.notes, after.notes),
  ].filter((line): line is string => line !== null);

  return (
    <li className="rounded-sm border border-border px-3 py-2 text-sm">
      <p className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-medium text-ink">{t(EVENT_LABEL[kind])}</span>
        <span className="text-xs text-ink-faint">
          {entry.at ? formatDate(entry.at) : ''}
          {entry.by ? ` · ${t('option.pipeline.historyBy', { name: entry.by })}` : ''}
        </span>
      </p>
      {lines.length > 0 && (
        <ul className="mt-1 space-y-0.5 text-xs text-ink-muted">
          {lines.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      )}
      {details.reason && <p className="mt-1 text-xs italic text-ink-muted">{t('option.pipeline.historyReason', { reason: details.reason })}</p>}
      {details.approval_cleared && <p className="mt-1 text-xs text-warning">{t('option.pipeline.historyApprovalRemoved')}</p>}
    </li>
  );
}
