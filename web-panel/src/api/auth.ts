import { apiRequest } from '@/api/client';
import type { Admin, LoginPayload, LoginResponseData } from '@/types/admin';

export const authApi = {
  login: (payload: LoginPayload) =>
    apiRequest<LoginResponseData>('admin/auth/login', {
      method: 'POST',
      body: payload,
      skipAuth: true,
    }),

  logout: () => apiRequest<null>('admin/auth/logout', { method: 'POST' }),

  me: () => apiRequest<Admin>('admin/auth/me'),
};
