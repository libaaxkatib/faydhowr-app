import { apiRequest } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { AuditLogEntry, ListAuditLogsParams } from '@/types/auditLog';

export const auditLogsApi = {
  /** `GET /admin/audit-logs` nests pagination under `data.pagination`, matching AdminController's list shape. */
  async list(params: ListAuditLogsParams = {}) {
    const { items, pagination } = await apiRequest<{ items: AuditLogEntry[]; pagination: PageMeta }>('admin/audit-logs', {
      query: params as Record<string, string | number | undefined>,
    });
    return { data: items, meta: pagination };
  },
};
