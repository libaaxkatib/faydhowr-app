import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { authApi } from '@/api/auth';
import { getStoredToken, registerUnauthenticatedHandler, setStoredToken } from '@/api/client';
import { sessionOutcomeAfterFailure } from '@/utils/accessRules';
import type { Admin, LoginPayload } from '@/types/admin';

/** 'unavailable' = the session could not be checked because the server or network did not answer; the stored sign-in is kept and a retry is offered. */
type AuthStatus = 'checking' | 'authenticated' | 'unauthenticated' | 'unavailable';

export interface AuthContextValue {
  status: AuthStatus;
  admin: Admin | null;
  login: (payload: LoginPayload) => Promise<void>;
  loginError: string | null;
  isLoggingIn: boolean;
  logout: () => void;
  /** super_admin holds every permission implicitly — see AdminPermissionResolver on the backend. */
  isSuperAdmin: boolean;
  /** Asks the server again who is signed in (after 'unavailable'). */
  retrySession: () => void;
}

// eslint-disable-next-line react-refresh/only-export-components
export const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [admin, setAdmin] = useState<Admin | null>(null);
  const [status, setStatus] = useState<AuthStatus>('checking');

  const clearSession = useCallback(() => {
    setStoredToken(null);
    setAdmin(null);
    setStatus('unauthenticated');
    queryClient.clear();
  }, [queryClient]);

  useEffect(() => {
    registerUnauthenticatedHandler(clearSession);
    return () => registerUnauthenticatedHandler(null);
  }, [clearSession]);

  const loadSession = useCallback(() => {
    const token = getStoredToken();
    if (!token) {
      setStatus('unauthenticated');
      return;
    }
    setStatus('checking');
    authApi
      .me()
      .then((fetchedAdmin) => {
        setAdmin(fetchedAdmin);
        setStatus('authenticated');
      })
      .catch((error: unknown) => {
        // Only an answer that says "this sign-in is not valid" (401 not signed in, 403 account switched off) ends the session. A network failure or a
        // 5xx says nothing about the sign-in, so the stored token is KEPT and the person is offered a retry instead of being signed out.
        if (sessionOutcomeAfterFailure(error) === 'signed_out') {
          setStoredToken(null);
          setStatus('unauthenticated');
          return;
        }
        setStatus('unavailable');
      });
  }, []);

  useEffect(() => {
    loadSession();
  }, [loadSession]);

  const loginMutation = useMutation({
    mutationFn: authApi.login,
    onSuccess: ({ admin: loggedInAdmin, access_token }) => {
      setStoredToken(access_token);
      setAdmin(loggedInAdmin);
      setStatus('authenticated');
    },
  });

  const logout = useCallback(() => {
    authApi.logout().catch(() => {
      /* best-effort — clear the local session regardless of whether the server call succeeded */
    });
    clearSession();
  }, [clearSession]);

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      admin,
      login: async (payload) => {
        await loginMutation.mutateAsync(payload);
      },
      loginError: loginMutation.error instanceof Error ? loginMutation.error.message : null,
      isLoggingIn: loginMutation.isPending,
      logout,
      isSuperAdmin: admin?.role === 'super_admin',
      retrySession: loadSession,
    }),
    [status, admin, loginMutation, logout, loadSession],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
