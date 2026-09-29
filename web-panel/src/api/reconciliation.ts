import { apiRequest, apiRequestWithMeta } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type {
  CreateDataIssuePayload,
  DataIssue,
  DataIssueSummary,
  ListDataIssuesParams,
  ResolveDataIssuePayload,
  UpdateDataIssuePayload,
} from '@/types/reconciliation';

export const reconciliationApi = {
  dataIssues: {
    async list(params: ListDataIssuesParams) {
      const { data, meta } = await apiRequestWithMeta<DataIssue[]>('admin/reconciliation/data-issues', {
        query: params as Record<string, string | number | undefined>,
      });
      return { data, meta: meta as PageMeta };
    },
    get: (id: number) => apiRequest<DataIssue>(`admin/reconciliation/data-issues/${id}`),
    create: (payload: CreateDataIssuePayload) =>
      apiRequest<DataIssue>('admin/reconciliation/data-issues', { method: 'POST', body: payload }),
    update: (id: number, payload: UpdateDataIssuePayload) =>
      apiRequest<DataIssue>(`admin/reconciliation/data-issues/${id}`, { method: 'PUT', body: payload }),
    resolve: (id: number, payload: ResolveDataIssuePayload) =>
      apiRequest<DataIssue>(`admin/reconciliation/data-issues/${id}/resolve`, { method: 'PATCH', body: payload }),
    summary: () => apiRequest<DataIssueSummary>('admin/reconciliation/data-issues/summary'),
  },
};
