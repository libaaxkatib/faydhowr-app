import { apiRequest } from '@/api/client';
import type { DashboardData, DashboardQueryParams } from '@/types/dashboard';

export const dashboardApi = {
  get: (params?: DashboardQueryParams) =>
    apiRequest<DashboardData>('admin/dashboard', {
      query: params as Record<string, string | undefined>,
    }),
};
