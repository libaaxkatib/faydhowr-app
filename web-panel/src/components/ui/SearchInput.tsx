import { Icon } from '@/components/ui/Icon';
import { useI18n } from '@/i18n/useI18n';

interface SearchInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  className?: string;
}

/** The search box every list uses: named for screen readers, with a button that empties it (and Escape does the same) once something is typed. */
export function SearchInput({ value, onChange, placeholder, className = '' }: SearchInputProps) {
  const { t } = useI18n();
  const label = placeholder ?? t('option.shell.searchPlaceholder');

  return (
    <div className={`relative ${className}`}>
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint">
        <Icon name="search" size={16} />
      </span>
      <input
        type="text"
        value={value}
        aria-label={label}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Escape' && value !== '') {
            event.stopPropagation();
            onChange('');
          }
        }}
        placeholder={label}
        className="h-10 w-full rounded-sm border border-border bg-surface pl-9 pr-9 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20"
      />
      {value !== '' && (
        <button
          type="button"
          aria-label={t('action.common.clearSearch')}
          title={t('action.common.clearSearch')}
          onClick={() => onChange('')}
          className="absolute right-1.5 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-sm text-ink-faint hover:bg-surface-alt hover:text-ink"
        >
          <Icon name="x" size={14} />
        </button>
      )}
    </div>
  );
}
