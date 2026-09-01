import { Icon } from '@/components/ui/Icon';
import { Button } from '@/components/ui/Button';
import { ApiClientError } from '@/api/client';

interface ErrorStateProps {
  error: unknown;
  onRetry?: () => void;
}

export function ErrorState({ error, onRetry }: ErrorStateProps) {
  const message =
    error instanceof ApiClientError
      ? error.message
      : error instanceof Error
        ? error.message
        : 'Something went wrong while loading this data.';

  return (
    <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
      <div className="mb-1 flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft text-danger">
        <Icon name="alert-triangle" size={20} />
      </div>
      <p className="text-sm font-semibold text-ink">Couldn't load this data</p>
      <p className="max-w-sm text-sm text-ink-muted">{message}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-3" onClick={onRetry}>
          <Icon name="refresh" size={14} />
          Try again
        </Button>
      )}
    </div>
  );
}
