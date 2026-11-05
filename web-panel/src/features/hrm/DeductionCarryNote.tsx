import { StatusBadge } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n/useI18n';
import { carryNote, centsText } from '@/utils/deductions';
import type { PayrollDeductionDetail, PayrollDeductionKind } from '@/types/payroll';

/**
 * The note under a penalty or advance line of a payroll item when it was carried in from an earlier month and/or could not be deducted in full this month
 * (the net is never below $0; what is left stays owed and is taken from the next months' pay). Shows nothing for any other line. Figures are the server's.
 */
export function DeductionCarryNote({ kind, detail }: { kind: PayrollDeductionKind; detail: PayrollDeductionDetail | null | undefined }) {
  const { t } = useI18n();
  const note = carryNote(kind, detail);

  if (!note) return null;

  return (
    <div className="mt-1 space-y-1" data-testid="deduction-carry-note">
      <div className="flex flex-wrap gap-1.5">
        {note.carriedIn && <StatusBadge status="carried" tone="info" label={t('status.deduction.carriedForward')} />}
        {note.notDeductedCents > 0 && <StatusBadge status="partial" tone="warning" label={t('status.deduction.notFullyDeducted')} />}
      </div>
      {note.carriedIn && note.carriedFrom && <p className="text-xs text-ink-muted">{t('warning.deduction.carriedFrom', { period: note.carriedFrom })}</p>}
      {note.notDeductedCents > 0 && (
        <>
          <p className="text-xs text-ink-muted">
            {t('warning.deduction.lineFigures', { owed: centsText(note.owedCents), deducted: centsText(note.deductedCents), left: centsText(note.notDeductedCents) })}
          </p>
          <p className="text-xs text-warning">{t('warning.deduction.notDeductedNote', { left: centsText(note.notDeductedCents) })}</p>
        </>
      )}
      {note.previouslyDeductedCents > 0 && <p className="text-xs text-ink-muted">{t('warning.deduction.previouslyDeducted', { amount: centsText(note.previouslyDeductedCents) })}</p>}
    </div>
  );
}
