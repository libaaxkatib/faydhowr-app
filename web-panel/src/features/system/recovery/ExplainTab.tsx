import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { recoveryApi } from '@/api/recovery';
import { Card } from '@/components/ui/Card';
import { inputClasses } from '@/components/ui/FormField';
import { useI18n } from '@/i18n/useI18n';
import type { RecoveryErrorEntry } from '@/types/recovery';

/** Plain-language explanations of the blocked-action errors, with the existing workflow that resolves each one. Read-only. */
export function ExplainTab() {
  const { t } = useI18n();
  const [text, setText] = useState('');
  const [asked, setAsked] = useState('');
  const result = useQuery({ queryKey: ['recovery', 'errors', asked], queryFn: () => recoveryApi.errors(asked) });
  const entries = result.data?.entries ?? [];
  const match = result.data?.match ?? null;

  return (
    <Card className="p-4">
      <form
        className="mb-4 flex flex-wrap gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          setAsked(text.trim());
        }}
      >
        <input aria-label={t('option.recovery.explainPlaceholder')} placeholder={t('option.recovery.explainPlaceholder')} className={`${inputClasses} max-w-xl`} value={text} onChange={(event) => setText(event.target.value)} />
        <button type="submit" className="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-ink">{t('action.recovery.explain')}</button>
      </form>

      {asked !== '' && match === null && !result.isLoading && <p className="mb-4 text-sm text-warning">{t('warning.recovery.noMatch')}</p>}
      {match !== null && <Explanation entry={match} highlighted />}

      <div className="mt-4 space-y-3">
        {entries.filter((entry) => entry.code !== match?.code).map((entry) => <Explanation key={entry.code} entry={entry} />)}
      </div>
    </Card>
  );
}

function Explanation({ entry, highlighted = false }: { entry: RecoveryErrorEntry; highlighted?: boolean }) {
  const { t } = useI18n();

  return (
    <section className={`rounded-sm border p-3 text-sm ${highlighted ? 'border-primary bg-primary/5' : 'border-border'}`}>
      <h3 className="font-semibold text-ink">{entry.title} <span className="font-normal text-ink-faint">({entry.code})</span></h3>
      <p className="mt-1"><span className="font-medium">{t('option.recovery.why')}:</span> {entry.why}</p>
      <p className="mt-1"><span className="font-medium">{t('option.recovery.whatToDo')}:</span> {entry.what_to_do}</p>
      <p className="mt-2 flex flex-wrap gap-3 text-xs">
        {entry.link && <Link to={entry.link} className="font-semibold text-primary underline">{t('option.recovery.openScreen')}</Link>}
        {entry.recovery_workflow && entry.recovery_workflow !== 'draft_payroll_preview' && <span className="text-ink-muted">{t('option.recovery.useRecovery')}</span>}
        {entry.needs_super_admin && <span className="text-ink-muted">{t('warning.recovery.requiresSuperAdmin')}</span>}
      </p>
    </section>
  );
}
