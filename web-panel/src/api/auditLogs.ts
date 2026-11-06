import { apiRequest } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { AuditLogEntry, AuditLogFilterOptions, ListAuditLogsParams } from '@/types/auditLog';

export const auditLogsApi = {
  /** `GET /admin/audit-logs` nests pagination under `data.pagination`, matching AdminController's list shape. */
  async list(params: ListAuditLogsParams = {}) {
    const { items, pagination } = await apiRequest<{ items: AuditLogEntry[]; pagination: PageMeta }>('admin/audit-logs', {
      query: params as Record<string, string | number | undefined>,
    });
    return { data: items, meta: pagination };
  },

  /**
   * `GET /admin/audit-logs/filters`: the admins, entity types and actions the filters offer. It is part of the audit-log read access, so the HR Manager
   * can use it without the Admin Users API (which is Super-Admin-only).
   */
  filters: () => apiRequest<AuditLogFilterOptions>('admin/audit-logs/filters'),
};
