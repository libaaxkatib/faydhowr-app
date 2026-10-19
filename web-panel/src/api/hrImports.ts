import { apiRequest, apiRequestWithMeta, downloadAuthenticatedFile } from '@/api/client';
import type { PageMeta } from '@/types/api';
import type { ImportBatch, ImportRow, ImportRowStatus, ImportType } from '@/types/hrImport';

/**
 * HR Excel imports. Every endpoint needs the dedicated hr.import permission on the backend (the Super Admin, or the
 * HR Manager role once it has been granted); this module only calls them. Nothing is written before `confirm`.
 */
export const hrImportsApi = {
  list: (page = 1) =>
    apiRequestWithMeta<ImportBatch[]>('admin/hr/imports', { query: { page, per_page: 10 } }) as Promise<{ data: ImportBatch[]; meta: PageMeta }>,

  upload: (type: ImportType, file: File) => {
    const form = new FormData();
    form.append('type', type);
    form.append('file', file);
    return apiRequest<ImportBatch>('admin/hr/imports', { method: 'POST', body: form, isFormData: true });
  },

  get: (id: number) => apiRequest<ImportBatch>(`admin/hr/imports/${id}`),

  rows: (id: number, params: { status?: ImportRowStatus | ''; page?: number }) =>
    apiRequestWithMeta<ImportRow[]>(`admin/hr/imports/${id}/rows`, {
      query: { status: params.status || undefined, page: params.page ?? 1, per_page: 25 },
    }) as Promise<{ data: ImportRow[]; meta: PageMeta }>,

  confirm: (id: number) => apiRequest<ImportBatch>(`admin/hr/imports/${id}/confirm`, { method: 'POST', body: { confirm: true } }),

  /** Imports the next bounded set of rows; call until the returned status is "completed". */
  process: (id: number) => apiRequest<ImportBatch>(`admin/hr/imports/${id}/process`, { method: 'POST' }),

  cancel: (id: number) => apiRequest<ImportBatch>(`admin/hr/imports/${id}/cancel`, { method: 'POST' }),

  downloadReport: (id: number, status?: ImportRowStatus | '') =>
    downloadAuthenticatedFile(`admin/hr/imports/${id}/report${status ? `?status=${status}` : ''}`, `import-${id}-report.csv`),
};
