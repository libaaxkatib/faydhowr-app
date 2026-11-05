import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { useI18n } from '@/i18n/useI18n';
import { WARNING_CONDITION_LABEL, groupWarnings, warningPerson } from '@/utils/generationWarnings';
import { companySegment } from '@/utils/payrollOverview';

/**
 * The Temporary Replacement warnings of ONE company for a month, read from the server when the dialog that shows them opens. They are warnings only:
 * nothing here blocks, disables or asks for a confirmation - the dialog's buttons stay available whatever this says (even while it loads or fails).
 */
export function GenerationWarningsPanel({ period, company }: { period: string; company: number | null }) {
  const { t } = useI18n();
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
  if (data.warnings.length === 0) return <p className="mt-3 text-xs text-ink-muted" data-testid="generation-warnings-none">{t('warning.payroll.gen.none')}</p>;

  return <GenerationWarningsList warnings={data.warnings} />;
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
