import { apiRequest } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { Admin } from '@/types/admin';
import type { CreateAdminPayload, ListAdminsParams, Permission, UpdateAdminPayload } from '@/types/system';

export interface AdminDetail extends Admin {
  effective_permissions: Permission[];
}

export const adminsApi = {
  /** `GET /admin/admins` nests pagination under `data.pagination`, not the envelope's top-level `meta`. */
  async list(params: ListAdminsParams = {}) {
    const { items, pagination } = await apiRequest<{ items: Admin[]; pagination: PageMeta }>('admin/admins', {
      query: params as Record<string, string | number | undefined>,
    });
    return { data: items, meta: pagination };
  },

  get: (id: number) => apiRequest<AdminDetail>(`admin/admins/${id}`),

  create: (payload: CreateAdminPayload) => apiRequest<Admin>('admin/admins', { method: 'POST', body: payload }),

  update: (id: number, payload: UpdateAdminPayload) =>
    apiRequest<Admin>(`admin/admins/${id}`, { method: 'PUT', body: payload }),
};
