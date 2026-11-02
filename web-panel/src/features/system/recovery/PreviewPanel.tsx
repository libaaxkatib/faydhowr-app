import { Link } from 'react-router-dom';

import { Card } from '@/components/ui/Card';
import { inputClasses } from '@/components/ui/FormField';
import { blockerTitleKey } from '@/i18n/messages/recovery';
import { useI18n } from '@/i18n/useI18n';
import type { RecoveryBlocker, RecoveryChange, RecoveryPreview } from '@/types/recovery';
import { MIN_REASON_LENGTH } from '@/utils/recovery';

import { ImpactTable } from './ImpactTable';

/** Step 3 and 4: the verified effect of the correction (nothing is saved yet), then the reason, the confirmation and Apply. */
export function PreviewPanel({
  preview,
  reason,
  onReason,
  confirmed,
  onConfirmed,
  canApply,
  isApplying,
  onApply,
}: {
  preview: RecoveryPreview;
  reason: string;
  onReason: (value: string) => void;
  confirmed: boolean;
  onConfirmed: (value: boolean) => void;
  canApply: boolean;
  isApplying: boolean;
  onApply: () => void;
}) {
  const { t } = useI18n();
  const currency = preview.impact?.currency ?? 'USD';
  const reasonTooShort = reason.trim().length > 0 && reason.trim().length < MIN_REASON_LENGTH;

  return (
    <Card className="p-4">
      <h2 className="mb-1 font-display text-base font-semibold text-ink">{t('option.recovery.step3')}</h2>
      <p className="mb-3 text-sm text-ink-muted">{t('warning.recovery.nothingSaved')}</p>

      {preview.blockers.length > 0 && (
        <ul className="mb-4 space-y-2" aria-label={t('option.recovery.step3')}>
          {preview.blockers.map((blocker) => (
            <BlockerItem key={`${blocker.code}${blocker.message}`} blocker={blocker} />
          ))}
        </ul>
      )}

      {preview.changes.length > 0 && (
        <div className="mb-4">
          <h3 className="mb-1 text-sm font-semibold text-ink">{t('option.recovery.changes')}</h3>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {preview.changes.map((change) => (
              <li key={`${change.entity}-${change.id}`}>
                <ChangeLine change={change} />
              </li>
            ))}
          </ul>
        </div>
      )}

      {preview.warnings.map((warning) => (
        <p key={warning} className="mb-2 text-sm text-warning">{warning}</p>
      ))}

      {preview.impact !== null && Object.entries(preview.impact.periods).map(([period, comparison]) => <ImpactTable key={period} period={period} comparison={comparison} currency={currency} />)}

      {Object.keys(preview.verification.checks).length > 0 && (
        <div className="mb-4">
          <h3 className="mb-1 text-sm font-semibold text-ink">{t('option.recovery.checks')}</h3>
          <ul className="space-y-0.5 text-sm">
            {Object.entries(preview.verification.checks).map(([name, ok]) => (
              <li key={name} className={ok ? 'text-success' : 'text-danger'}>{ok ? t('option.recovery.checkPassed') : t('option.recovery.checkFailed')}: {name}</li>
            ))}
          </ul>
        </div>
      )}

      {preview.can_apply ? (
        <div className="border-t border-border pt-4">
          <h2 className="mb-2 font-display text-base font-semibold text-ink">{t('option.recovery.step4')}</h2>
          <p className="mb-2 text-xs text-ink-muted">{t('warning.recovery.tokenValid', { minutes: preview.token_valid_minutes })}</p>
          <label htmlFor="recovery-reason" className="mb-1.5 block text-sm font-medium text-ink">{t('option.recovery.reason')}</label>
          <textarea
            id="recovery-reason"
            rows={3}
            maxLength={1000}
            className={`${inputClasses} h-auto py-2`}
            value={reason}
            onChange={(event) => onReason(event.target.value)}
            aria-invalid={reasonTooShort || undefined}
            aria-describedby={reasonTooShort ? 'recovery-reason-error' : undefined}
          />
          {reasonTooShort && <p id="recovery-reason-error" role="alert" className="mt-1 text-xs text-danger">{t('validation.recovery.reasonTooShort')}</p>}
          <label className="mt-3 flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" className="mt-1" checked={confirmed} onChange={(event) => onConfirmed(event.target.checked)} />
            <span>{t('option.recovery.confirmCheckbox')}</span>
          </label>
          <button
            type="button"
            disabled={!canApply || isApplying}
            onClick={onApply}
            className="mt-4 inline-flex h-10 items-center rounded-sm bg-danger px-4 text-sm font-semibold text-white transition hover:brightness-95 disabled:cursor-not-allowed disabled:bg-danger/50"
          >
            {t('action.recovery.apply')}
          </button>
        </div>
      ) : (
        preview.blockers.some((blocker) => blocker.code.startsWith('PAYROLL_') || blocker.code === 'PAYMENT_RECORDED') && (
          <p className="border-t border-border pt-3 text-sm text-ink-muted">{t('warning.recovery.closedPayroll')}</p>
        )
      )}
    </Card>
  );
}

function BlockerItem({ blocker }: { blocker: RecoveryBlocker }) {
  const { t } = useI18n();
  const key = blockerTitleKey(blocker.code);

  return (
    <li className="rounded-sm border border-danger/40 bg-danger/5 p-3 text-sm">
      {key && <p className="font-semibold text-danger">{t(key)}</p>}
      <p className="text-ink">{blocker.message}</p>
      {blocker.link && <Link to={blocker.link} className="mt-1 inline-block text-xs font-semibold text-primary underline">{t('option.recovery.openScreen')}</Link>}
    </li>
  );
}

function ChangeLine({ change }: { change: RecoveryChange }) {
  const fields = Array.from(new Set([...Object.keys(change.before), ...Object.keys(change.after)]));

  return (
    <span>
      <span className="font-medium">{change.label}:</span>{' '}
      {fields.map((field) => `${field}: ${change.before[field] ?? '—'} → ${change.after[field] ?? '—'}`).join('; ')}
    </span>
  );
}
