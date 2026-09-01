import { useState, type FormEvent } from 'react';
import { Navigate, useLocation } from 'react-router-dom';

import { useAuth } from '@/features/auth/useAuth';
import { Logo } from '@/components/Logo';
import { Button } from '@/components/ui/Button';
import { ApiClientError } from '@/api/client';

export function LoginPage() {
  const { login, isLoggingIn, status } = useAuth();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  if (status === 'authenticated') {
    const redirectTo = (location.state as { from?: Location })?.from?.pathname ?? '/dashboard';
    return <Navigate to={redirectTo} replace />;
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await login({ email, password });
    } catch (err) {
      if (err instanceof ApiClientError) {
        if (err.isValidation && err.fieldErrors) {
          setError(Object.values(err.fieldErrors).flat()[0] ?? err.message);
        } else {
          setError(err.message);
        }
      } else {
        setError('Something went wrong. Please try again.');
      }
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-muted px-4">
      <div className="w-full max-w-[400px]">
        <div className="mb-8 flex flex-col items-center gap-3">
          <Logo className="h-10 w-auto" />
          <p className="text-sm text-ink-muted">Sign in to the Web Panel</p>
        </div>

        <form
          onSubmit={handleSubmit}
          className="rounded-lg border border-border bg-surface p-8 shadow-card"
        >
          <div className="mb-4">
            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-ink">
              Email
            </label>
            <input
              id="email"
              type="email"
              required
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className="h-11 w-full rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20"
              placeholder="admin@fayadhowr.com"
            />
          </div>

          <div className="mb-5">
            <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-ink">
              Password
            </label>
            <input
              id="password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="h-11 w-full rounded-sm border border-border bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20"
              placeholder="••••••••"
            />
          </div>

          {error && (
            <div className="mb-4 rounded-sm border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
              {error}
            </div>
          )}

          <Button type="submit" className="w-full" isLoading={isLoggingIn}>
            Sign in
          </Button>
        </form>

        <p className="mt-6 text-center text-xs text-ink-faint">
          Fayadhowr Web Panel — internal use only
        </p>
      </div>
    </div>
  );
}
