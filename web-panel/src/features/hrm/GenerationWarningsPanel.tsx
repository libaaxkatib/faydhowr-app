import { useContext } from 'react';
import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { SourcesShownContext } from '@/features/hrm/sourcesShownContext';
import { useI18n } from '@/i18n/useI18n';
import { WARNING_CONDITION_LABEL, groupWarnings, warningPerson } from '@/utils/generationWarnings';
import { companySegment } from '@/utils/payrollOverview';
import { sourceChangeView, visibleSourceChanges, type SourceValueView } from '@/utils/sourceChanges';
import type { SourceChange } from '@/types/payroll';

/**
 * The Temporary Replacement warnings of ONE company for a month, read from the server when the dialog that shows them opens. They are warnings only:
 * nothing here blocks, disables or asks for a confirmation - the dialog's buttons stay available whatever this says (even while it loads or fails).
 */
export function GenerationWarningsPanel({ period, company, context = 'action' }: { period: string; company: number | null; context?: 'generate' | 'action' }) {
  const { t } = useI18n();
  const hideSources = useContext(SourcesShownContext);
  const { data, isLoading, isError } = useQuery({
    queryKey: ['payroll-warnings', period, companySegment(company)],
    queryFn: () => hrApi.payrollRuns.companyWarnings(period, company),
    // Always the current picture: a warning list that was true a minute ago may not be true now.
    staleTime: 0,
    gcTime: 0,
  });

  if (isLoading) return <p className="mt-3 text-xs text-ink-muted" data-testid="generation-warnings-loading">{t('warning.payroll.gen.checking')}</p>;
  if (isError) return <p className="mt-3 text-xs text-ink-muted" data-testid="generation-warnings-error">{t('warning.payroll.gen.loadFailed')}</p>;
  if (!data) return null;
  // Both guards can be active at once: each one has its own notice and both are shown. They are separate from the Temporary Replacement warnings below,
  // which never block.
  const notice = (
    <>
      {data.deduction_inputs_changed === true ? <DeductionInputsNotice context={context} /> : null}
      {data.source_inputs_changed === true && !hideSources ? <SourceInputsNotice context={context} changes={data.source_changes} total={data.source_changes_total} /> : null}
    </>
  );
  if (data.warnings.length === 0) {
    return (
      <>
        {notice}
        <p className="mt-3 text-xs text-ink-muted" data-testid="generation-warnings-none">{t('warning.payroll.gen.none')}</p>
      </>
    );
  }

  return (
    <>
      {notice}
      <GenerationWarningsList warnings={data.warnings} />
    </>
  );
}

/**
 * A penalty or an advance of this company was registered, corrected, voided or waived after its draft was generated. Unlike the Temporary Replacement warnings this
 * one STOPS Review and Finalize (the server answers 409 PAYROLL_INPUTS_CHANGED) until the company is generated again, so before Review / Finalize it is a clear
 * blocking notice that points at Generate; inside the Generate dialog itself it only says that generating brings the changes in.
 */
export function DeductionInputsNotice({ context }: { context: 'generate' | 'action' }) {
  const { t } = useI18n();

  if (context === 'generate') {
    return <p className="mt-3 rounded-sm bg-warning-soft px-3 py-2 text-sm text-ink" data-testid="deduction-inputs-changed">{t('warning.deduction.inputsChangedGenerate')}</p>;
  }

  return (
    <div className="mt-3 rounded-sm border border-danger/40 bg-danger-soft px-3 py-3" role="alert" data-testid="deduction-inputs-changed">
      <p className="text-sm font-semibold text-danger">{t('warning.deduction.inputsChangedTitle')}</p>
      <p className="mt-1 text-xs text-ink">{t('warning.deduction.inputsChangedBlocking')}</p>
      <p className="mt-1 text-xs font-medium text-ink">{t('warning.deduction.inputsChangedPointer')}</p>
    </div>
  );
}

/**
 * The assignments, salary rows, Temporary covers or absences of this company changed after its draft was generated. Like the penalties / advances notice this
 * one STOPS Review and Finalize (the server answers 409 PAYROLL_SOURCES_CHANGED) until the company is generated again: before Review / Finalize it is a
 * clear blocking notice that names the changes and points at the existing Generate action; inside the Generate dialog it only says that generating brings
 * the changes in (and still lists them). It is not a Temporary Replacement warning: those never block.
 */
export function SourceInputsNotice({ context, changes, total }: { context: 'generate' | 'action'; changes?: SourceChange[] | null; total?: number | null }) {
  const { t } = useI18n();

  if (context === 'generate') {
    return (
      <div className="mt-3 rounded-sm bg-warning-soft px-3 py-2" data-testid="source-inputs-changed">
        <p className="text-sm text-ink">{t('warning.sources.generate')}</p>
        <SourceChangesList changes={changes} total={total} />
      </div>
    );
  }

  return (
    <div className="mt-3 rounded-sm border border-danger/40 bg-danger-soft px-3 py-3" role="alert" data-testid="source-inputs-changed">
      <p className="text-sm font-semibold text-danger">{t('warning.sources.title')}</p>
      <p className="mt-1 text-xs text-ink">{t('warning.sources.blocking')}</p>
      <p className="mt-1 text-xs font-medium text-ink">{t('warning.sources.pointer', { action: t('action.generateDraft') })}</p>
      <SourceChangesList changes={changes} total={total} />
    </div>
  );
}

/** What changed, one entry per change (what kind, which employee / assignment, which field went from X to Y), at most 50, with the real total when there are more. */
export function SourceChangesList({ changes, total }: { changes?: readonly SourceChange[] | null; total?: number | null }) {
  const { t } = useI18n();
  const visible = visibleSourceChanges(changes, total);
  if (visible.changes.length === 0) return null;
  const show = (value: SourceValueView) => (value.kind === 'key' ? t(value.key) : value.text);

  return (
    <div className="mt-2" data-testid="source-changes">
      <p className="text-xs font-semibold text-ink">{t('warning.sources.listTitle')}</p>
      <ul className="mt-1 space-y-1.5">
        {visible.changes.map((change, index) => {
          const view = sourceChangeView(change);

          return (
            <li key={`${change.category}-${change.id ?? ''}-${change.assignment_id ?? ''}-${change.detail ?? ''}-${index}`} className="rounded-sm bg-surface px-2.5 py-1.5 text-xs text-ink" data-testid="source-change">
              <p className="font-medium">{t(view.headingKey, view.headingValues)}</p>
              {view.fields.length > 0 && (
                <ul className="mt-0.5 text-ink-muted">
                  {view.fields.map((field) => (
                    <li key={field.field}>
                      {field.labelKey ? t(field.labelKey) : field.field}: {show(field.before)} → {show(field.after)}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ul>
      {visible.countLine && <p className="mt-1.5 text-xs text-ink-muted" data-testid="source-changes-count">{t(visible.countLine.key, visible.countLine.values)}</p>}
    </div>
  );
}

/** The warnings themselves, grouped by condition: company, person, what the server says and what remains unresolved. */
export function GenerationWarningsList({ warnings }: { warnings: Parameters<typeof groupWarnings>[0] }) {
  const { t } = useI18n();

  return (
    <div className="mt-3 rounded-sm border border-warning/40 bg-warning-soft px-3 py-3" role="region" aria-label={t('warning.payroll.gen.title')} data-testid="generation-warnings">
      <p className="text-sm font-semibold text-warning">{t('warning.payroll.gen.title')}</p>
      <p className="mb-2 text-xs text-ink-muted">{t('warning.payroll.gen.intro')}</p>
      {groupWarnings(warnings).map((group) => (
        <div key={group.condition} className="mb-2 last:mb-0" data-testid={`generation-warnings-${group.condition}`}>
          <p className="text-xs font-semibold text-ink">{t(WARNING_CONDITION_LABEL[group.condition])}</p>
          <ul className="mt-1 space-y-1.5">
            {group.warnings.map((warning, index) => {
              const person = warningPerson(warning);

              return (
                <li key={`${warning.assignment_id ?? ''}-${warning.replacement_id ?? ''}-${index}`} className="rounded-sm bg-surface px-2.5 py-1.5 text-xs text-ink">
                  <p className="font-medium">
                    {warning.company_name?.trim() || t('option.payroll.sectionNoCompany')}
                    {person && ` · ${person}`}
                  </p>
                  <p className="text-ink-muted">{warning.detail}</p>
                  <p className="text-ink">{t('warning.payroll.gen.whatRemains', { text: warning.what_remains })}</p>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}
