import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  Customer,
  CreateCustomerPayload,
  ListCustomersParams,
  RestoreCustomerStatus,
  UpdatableCustomerStatus,
  UpdateCustomerPayload,
} from '@/types/customer';

export const customersApi = {
  async list(params: ListCustomersParams) {
    const { data, meta } = await apiRequestWithMeta<Customer[]>('admin/customers', {
      query: params as Record<string, string | number | undefined>,
    });
    return { data, meta: meta as PageMeta };
  },

  get: (id: number) => apiRequest<Customer>(`admin/customers/${id}`),

  create: (payload: CreateCustomerPayload) =>
    apiRequest<Customer>('admin/customers', { method: 'POST', body: payload }),

  update: (id: number, payload: UpdateCustomerPayload) =>
    apiRequest<Customer>(`admin/customers/${id}`, { method: 'PUT', body: payload }),

  updateStatus: (id: number, status: UpdatableCustomerStatus) =>
    apiRequest<Customer>(`admin/customers/${id}/status`, { method: 'PATCH', body: { status } }),

  remove: (id: number) => apiRequest<null>(`admin/customers/${id}`, { method: 'DELETE' }),

  restore: (id: number, status: RestoreCustomerStatus) =>
    apiRequest<Customer>(`admin/customers/${id}/restore`, { method: 'POST', body: { status } }),
};
