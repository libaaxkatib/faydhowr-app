import { apiRequest } from '@/api/client';
import { parseOverrides, type Override, type Overrides } from '@/i18n/overrides';

export const translationOverridesApi = {
  /** Every edited message, keyed by message key. Open to every signed-in admin: the panel needs the wording to render. */
  async list(): Promise<Overrides> {
    const data = await apiRequest<{ overrides: unknown }>('admin/i18n/overrides');

    return parseOverrides(data.overrides);
  },

  /** Super Admin only. A blank language keeps that language's built-in wording. */
  save: (key: string, wording: { en: string; so: string }) =>
    apiRequest<{ key: string; override: Override }>(`admin/i18n/overrides/${encodeURIComponent(key)}`, { method: 'PUT', body: wording }),

  /** Super Admin only. Returns the message to its built-in wording. */
  reset: (key: string) => apiRequest<{ key: string }>(`admin/i18n/overrides/${encodeURIComponent(key)}`, { method: 'DELETE' }),
};
