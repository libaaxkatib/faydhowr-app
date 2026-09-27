import { apiRequest } from '@/api/client';
import type { Backup, Branch, SettingCategory, SettingsAuditLogEntry, SettingsCategoryData } from '@/types/settings';

export const settingsApi = {
  /** GET returns fully-qualified dotted keys under `settings` (e.g. "company.name") — PUT expects the same shape back. */
  get: (category: SettingCategory) => apiRequest<SettingsCategoryData>(`admin/settings/${category}`),

  update: (category: SettingCategory, values: Record<string, unknown>) =>
    apiRequest<SettingsCategoryData>(`admin/settings/${category}`, { method: 'PUT', body: values }),

  restoreDefaults: (category: SettingCategory) =>
    apiRequest<SettingsCategoryData>(`admin/settings/${category}/restore-defaults`, { method: 'POST' }),

  uploadCompanyLogo: (file: File) =>
    apiRequest<{ 'company.logo': string }>('admin/settings/company/logo', {
      method: 'POST',
      body: (() => {
        const form = new FormData();
        form.append('logo', file);
        return form;
      })(),
      isFormData: true,
    }),

  sendSmtpTest: (toEmail: string) =>
    apiRequest<{ to: string }>('admin/settings/smtp/test', { method: 'POST', body: { to_email: toEmail } }),

  /**
   * Settings-scoped audit trail (distinct from the general /admin/audit-logs
   * used by the standalone Audit Log page). Filter keys are `category`,
   * `changed_by`, `from`, `to`, `limit` — not the same names AuditLogController
   * uses (`admin_id`/`date_from`/`date_to`/`per_page`); this is a different
   * endpoint with its own request class, not a naming inconsistency.
   */
  auditLogs: (filters?: { category?: string; changed_by?: number; from?: string; to?: string; limit?: number }) =>
    apiRequest<SettingsAuditLogEntry[]>('admin/settings/audit-logs', { query: filters }),

  branches: {
    list: () => apiRequest<Branch[]>('admin/branches'),
    activate: (id: number) => apiRequest<Branch>(`admin/branches/${id}/activate`, { method: 'PATCH' }),
    makeDefault: (id: number) => apiRequest<Branch>(`admin/branches/${id}/default`, { method: 'PATCH' }),
  },

  backups: {
    list: () => apiRequest<Backup[]>('admin/backups'),
    create: () => apiRequest<Backup>('admin/backups', { method: 'POST' }),
    /** Streams a binary file (not JSON) — apiRequest returns the raw Response for non-JSON bodies; trigger a browser save from it. */
    async download(id: string, fileName: string) {
      const response = await apiRequest<Response>(`admin/backups/${encodeURIComponent(id)}/download`);
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      link.click();
      URL.revokeObjectURL(url);
    },
    /** Backend requires the literal confirmation string "RESTORE" — enforced server-side, not just a frontend prompt. */
    restore: (id: string) =>
      apiRequest<null>(`admin/backups/${encodeURIComponent(id)}/restore`, {
        method: 'POST',
        body: { confirmation: 'RESTORE' },
      }),
  },
};
