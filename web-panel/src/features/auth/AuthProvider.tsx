import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { authApi } from '@/api/auth';
import { getStoredToken, registerUnauthenticatedHandler, setStoredToken } from '@/api/client';
import type { Admin, LoginPayload } from '@/types/admin';

type AuthStatus = 'checking' | 'authenticated' | 'unauthenticated';

export interface AuthContextValue {
  status: AuthStatus;
  admin: Admin | null;
  login: (payload: LoginPayload) => Promise<void>;
  loginError: string | null;
  isLoggingIn: boolean;
  logout: () => void;
  /** super_admin holds every permission implicitly — see AdminPermissionResolver on the backend. */
  isSuperAdmin: boolean;
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

  useEffect(() => {
    const token = getStoredToken();
    if (!token) {
      setStatus('unauthenticated');
      return;
    }
    authApi
      .me()
      .then((fetchedAdmin) => {
        setAdmin(fetchedAdmin);
        setStatus('authenticated');
      })
      .catch(() => {
        // apiRequest already cleared the token via the unauthenticated handler on a 401;
        // any other failure (network, 500) should still drop back to the login screen.
        setStoredToken(null);
        setStatus('unauthenticated');
      });
  }, []);

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
    }),
    [status, admin, loginMutation, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
