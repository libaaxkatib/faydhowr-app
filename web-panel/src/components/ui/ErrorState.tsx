import { Icon } from '@/components/ui/Icon';
import { Button } from '@/components/ui/Button';
import { ApiClientError } from '@/api/client';

interface ErrorStateProps {
  error: unknown;
  onRetry?: () => void;
}

export function ErrorState({ error, onRetry }: ErrorStateProps) {
  // A 403 means the request reached the server and was correctly blocked by
  // a permission check - retrying it will never succeed, so this renders a
  // distinct "no access" message instead of the generic retriable error.
  if (error instanceof ApiClientError && error.isForbidden) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
        <div className="mb-1 flex h-11 w-11 items-center justify-center rounded-full bg-warning-soft text-warning">
          <Icon name="shield" size={20} />
        </div>
        <p className="text-sm font-semibold text-ink">You don't have access to this</p>
        <p className="max-w-sm text-sm text-ink-muted">
          Your account doesn't have permission to view this page. Ask an administrator if you believe this is a mistake.
        </p>
      </div>
    );
  }

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
