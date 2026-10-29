import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { useI18n } from '@/i18n/useI18n';

/** "Reset filters (3)": appears only while at least one search word or filter is active, and clears all of them in one step. */
export function ResetFiltersButton({ count, onReset }: { count: number; onReset: () => void }) {
  const { t } = useI18n();

  if (count <= 0) return null;

  return (
    <Button variant="outline" size="sm" onClick={onReset}>
      {t('action.common.resetFiltersCount', { count })}
    </Button>
  );
}

/**
 * How many records match, announced politely to screen readers when it changes. With a search or filter active it says "12 of 124 match your
 * search and filters"; without one it just says how many records there are.
 */
export function ResultCount({ matching, total, filtered }: { matching: number; total?: number; filtered: boolean }) {
  const { t } = useI18n();

  return (
    <p role="status" aria-live="polite" className="text-xs text-ink-muted">
      {!filtered ? t('option.common.resultsTotal', { count: matching }) : total === undefined ? t('option.common.resultsMatching', { count: matching }) : t('option.common.resultsFiltered', { count: matching, total })}
    </p>
  );
}

/** The line under a list's toolbar: how many records match (inside the list card, so it lines up with the table). */
export function ListResultBar({ matching, total, filtered }: { matching: number; total?: number; filtered: boolean }) {
  return (
    <div className="border-b border-border px-5 py-2">
      <ResultCount matching={matching} total={total} filtered={filtered} />
    </div>
  );
}

/** The button offered inside an empty result that a search or filter caused. */
export function ResetFiltersAction({ onReset }: { onReset: () => void }) {
  const { t } = useI18n();

  return (
    <Button variant="outline" size="sm" onClick={onReset}>
      {t('action.common.resetFilters')}
    </Button>
  );
}

/** The empty result of a search or filter, which is not the same as "there are no records": it says so and offers the way back. */
export function NoMatchState({ onReset }: { onReset: () => void }) {
  const { t } = useI18n();

  return (
    <EmptyState
      icon="search"
      title={t('warning.common.noMatchTitle')}
      description={t('warning.common.noMatchText')}
      action={
        <Button variant="outline" size="sm" onClick={onReset}>
          {t('action.common.resetFilters')}
        </Button>
      }
    />
  );
}
