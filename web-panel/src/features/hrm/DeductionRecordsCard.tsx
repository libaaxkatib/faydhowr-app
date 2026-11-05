import { useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { CorrectDeductionDialog, VoidDeductionDialog, WaiveDeductionDialog } from '@/features/hrm/ManageDeductionDialogs';
import { RecordDeductionDialog } from '@/features/hrm/RecordDeductionDialog';
import { useI18n } from '@/i18n/useI18n';
import { centsText, deductionRowView, type DeductionKind, type DeductionRecord } from '@/utils/deductions';
import { formatDate } from '@/utils/formatters';
import type { CompanyChoice } from '@/utils/inputCompany';

type Action = { type: 'void' | 'correct' | 'waive'; record: DeductionRecord };

/**
 * The penalties or the one-month advances of one employee, newest as the server sent them. Every row shows its amount, what finalized payrolls already
 * deducted, what is still owed and what was waived. A voided row stays in the history, greyed, with its reason and its replacement; it offers no action.
 * Void and Correct are for an HR Manager or a Super Admin on a row nothing has deducted yet; "Waive balance" is for a Super Admin on a row that is partly
 * deducted. Every action sends the version of the row the screen showed and says why in words; the server decides and its refusal is shown plainly.
 */
export function DeductionRecordsCard({
  kind,
  employeeId,
  records,
  choices,
  isArchived = false,
  recordLabel,
  recordTitle,
  renderCompany,
}: {
  kind: DeductionKind;
  employeeId: number;
  records: readonly DeductionRecord[];
  choices: CompanyChoice[];
  isArchived?: boolean;
  /** The owner-approved button text ("Record Penalty" / "Record Advance") and the title of the dialog it opens, given by the page that owns those messages. */
  recordLabel: string;
  recordTitle: string;
  /** Which company the record is charged to (the page's own note, also used for leave and absences). */
  renderCompany: (record: DeductionRecord) => ReactNode;
}) {
  const { t } = useI18n();
  const { show } = useToast();
  const { admin } = useAuth();
  const queryClient = useQueryClient();
  const [isRecording, setIsRecording] = useState(false);
  const [action, setAction] = useState<Action | null>(null);
  const isPenalty = kind === 'penalty';

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['employee', employeeId] });
    void queryClient.invalidateQueries({ queryKey: ['employees'] });
    void queryClient.invalidateQueries({ queryKey: ['payroll-summary', employeeId] });
    // A changed penalty / advance makes an already generated draft stale: the warnings are read again the next time a dialog opens.
    void queryClient.invalidateQueries({ queryKey: ['payroll-warnings'] });
    void queryClient.invalidateQueries({ queryKey: ['deduction-balances'] });
  };
  const done = (messageKey: 'notify.deduction.voided' | 'notify.deduction.corrected' | 'notify.deduction.waived') => () => {
    refresh();
    show(t(messageKey));
    setAction(null);
  };

  return (
    <Card>
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">{t(isPenalty ? 'option.employeeDetail.penaltyHistory' : 'option.employeeDetail.advanceHistory')}</h3>
        <PermissionGate module="hr" hideWhen={isArchived}>
          <Button size="sm" variant="outline" onClick={() => setIsRecording(true)} data-testid={`record-${kind}`}>
            <Icon name="plus" size={14} />
            {recordLabel}
          </Button>
        </PermissionGate>
      </CardHeader>
      <CardBody className="space-y-3">
        {records.length > 0 ? (
          records.map((record) => {
            const view = deductionRowView(record, records, admin?.role);
            const date = 'penalty_date' in record ? record.penalty_date : record.advance_date;

            return (
              <div key={record.id} className={`border-b border-border pb-3 last:border-0 last:pb-0 ${view.voided ? 'opacity-60' : ''}`} data-testid={`${kind}-row-${record.id}`} data-status={view.voided ? 'voided' : 'active'}>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs text-ink-faint">{formatDate(date)}</span>
                  <span className={`text-sm text-ink ${view.voided ? 'line-through' : ''}`}>{view.amountText}</span>
                </div>
                {view.voided && (
                  <div className="mt-1">
                    <StatusBadge status="voided" tone="neutral" label={t('status.deduction.voided')} />
                  </div>
                )}
                <p className="mt-1 text-xs text-ink-muted">{record.reason}</p>
                {renderCompany(record)}
                <p className="mt-0.5 text-[11px] text-ink-faint">{t('option.employeeDetail.payrollPeriodValue', { period: record.payroll_period })}</p>

                {view.voided && record.void_reason && <p className="mt-0.5 text-[11px] text-ink-muted">{t('option.deduction.voidedReason', { reason: record.void_reason })}</p>}
                {view.replacesId !== null && <p className="mt-0.5 text-[11px] text-ink-muted">{t('option.deduction.replacesNote', { id: view.replacesId })}</p>}
                {view.replacedById !== null && <p className="mt-0.5 text-[11px] text-ink-muted">{t('option.deduction.replacedByNote', { id: view.replacedById })}</p>}

                {!view.voided && view.owedCents !== null && (
                  <dl className="mt-1.5 grid grid-cols-3 gap-2 text-[11px]" data-testid={`${kind}-figures-${record.id}`}>
                    <div>
                      <dt className="text-ink-faint">{t('option.deduction.deductedSoFar')}</dt>
                      <dd className="text-ink">{centsText(view.deductedCents)}</dd>
                    </div>
                    <div>
                      <dt className="text-ink-faint">{t('option.deduction.stillOwed')}</dt>
                      <dd className="font-semibold text-ink">{centsText(view.owedCents)}</dd>
                    </div>
                    <div>
                      <dt className="text-ink-faint">{t('option.deduction.waivedAmount')}</dt>
                      <dd className="text-ink">{centsText(view.waivedCents)}</dd>
                    </div>
                  </dl>
                )}
                {!view.voided && view.waivedCents > 0 && record.waive_reason && <p className="mt-0.5 text-[11px] text-ink-muted">{t('option.deduction.waivedReason', { reason: record.waive_reason })}</p>}
                {!view.voided && view.applied && <p className="mt-1 text-[11px] text-warning" data-testid={`${kind}-applied-${record.id}`}>{t('warning.deduction.alreadyApplied')}</p>}

                {(view.canVoid || view.canCorrect || view.canWaive) && (
                  <PermissionGate module="hr" hideWhen={isArchived}>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {view.canCorrect && <Button size="sm" variant="outline" onClick={() => setAction({ type: 'correct', record })} data-testid={`${kind}-correct-${record.id}`}>{t('action.deduction.correct')}</Button>}
                      {view.canVoid && <Button size="sm" variant="outline" onClick={() => setAction({ type: 'void', record })} data-testid={`${kind}-void-${record.id}`}>{t('action.void')}</Button>}
                      {view.canWaive && <Button size="sm" variant="outline" onClick={() => setAction({ type: 'waive', record })} data-testid={`${kind}-waive-${record.id}`}>{t('action.deduction.waive')}</Button>}
                    </div>
                  </PermissionGate>
                )}
              </div>
            );
          })
        ) : (
          <EmptyState icon={isPenalty ? 'x' : 'credit-card'} title={t(isPenalty ? 'option.employeeDetail.emptyNoPenalties' : 'option.employeeDetail.emptyNoAdvances')} />
        )}
      </CardBody>

      {isRecording && (
        <RecordDeductionDialog
          kind={kind}
          employeeId={employeeId}
          choices={choices}
          title={recordTitle}
          onClose={() => setIsRecording(false)}
          onRecorded={() => {
            refresh();
            show(t(isPenalty ? 'notify.employeeDetail.penaltyRecorded' : 'notify.employeeDetail.advanceRecorded'));
            setIsRecording(false);
          }}
        />
      )}
      {action?.type === 'void' && <VoidDeductionDialog kind={kind} employeeId={employeeId} record={action.record} onClose={() => setAction(null)} onDone={done('notify.deduction.voided')} onRefresh={refresh} />}
      {action?.type === 'correct' && (
        <CorrectDeductionDialog kind={kind} employeeId={employeeId} record={action.record} choices={choices} onClose={() => setAction(null)} onDone={done('notify.deduction.corrected')} onRefresh={refresh} />
      )}
      {action?.type === 'waive' && <WaiveDeductionDialog kind={kind} employeeId={employeeId} record={action.record} onClose={() => setAction(null)} onDone={done('notify.deduction.waived')} onRefresh={refresh} />}
    </Card>
  );
}
