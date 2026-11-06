import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';

import { useAuth } from '@/features/auth/useAuth';
import { LoadingState } from '@/components/ui/LoadingState';
import { PermissionsUnavailable } from '@/features/auth/PermissionsUnavailable';
import { useI18n } from '@/i18n/useI18n';

export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { status, retrySession } = useAuth();
  const location = useLocation();
  const { t } = useI18n();

  if (status === 'checking') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-muted">
        <LoadingState label={t('option.shell.checkingSession')} />
      </div>
    );
  }

  // The server or network did not answer: the sign-in is still stored, so offer a retry instead of the sign-in page.
  if (status === 'unavailable') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-muted">
        <PermissionsUnavailable onRetry={retrySession} />
      </div>
    );
  }

  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  return <>{children}</>;
}
