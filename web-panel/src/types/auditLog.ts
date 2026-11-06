import type { Admin } from '@/types/admin';

export interface AuditLogEntry {
  id: number;
  admin: Admin | null;
  action: string;
  entity_type: string | null;
  entity_id: number | string | null;
  description: string;
  metadata: Record<string, unknown> | null;
  created_at: string | null;
}

export interface ListAuditLogsParams {
  action?: string;
  entity_type?: string;
  admin_id?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

/** What the Audit Log screen's filters offer (GET admin/audit-logs/filters): only choices that really exist, so a filter can always match what the table shows. */
export interface AuditLogFilterOptions {
  admins: { id: number; name: string }[];
  /** `value` is the stored entity type (what the filter matches exactly); `label` is the short name the table shows. */
  entity_types: { value: string; label: string }[];
  actions: string[];
}
