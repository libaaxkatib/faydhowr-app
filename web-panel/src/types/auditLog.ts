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
