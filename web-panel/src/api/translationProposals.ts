import { apiRequest } from '@/api/client';

export type TranslationProposalStatus = 'pending' | 'approved' | 'rejected' | 'withdrawn';

export interface TranslationProposal {
  id: number;
  message_key: string;
  en: string | null;
  so: string | null;
  reason: string | null;
  status: TranslationProposalStatus;
  proposed_by: { id: number | null; name: string | null; role: string | null };
  proposed_at: string | null;
  decided_by: { id: number; name: string | null } | null;
  decided_at: string | null;
  decision_note: string | null;
}

/**
 * Owner decision TRANSLATION-POLICY: the HR Manager and the Marketing Manager propose wording changes, only the Super Admin approves or rejects them.
 * A proposal never changes the wording in use; approval creates the same override the Super Admin editor writes. The server checks the role on every call.
 */
export const translationProposalsApi = {
  /** The Super Admin sees every proposal; a proposer sees their own. */
  async list(status?: TranslationProposalStatus): Promise<TranslationProposal[]> {
    const data = await apiRequest<{ proposals: TranslationProposal[] }>('admin/i18n/proposals', { query: status ? { status } : undefined });

    return data.proposals;
  },

  /** HR Manager / Marketing Manager. A blank language is left out of the proposal. */
  propose: (body: { key: string; en: string; so: string; reason: string }) =>
    apiRequest<TranslationProposal>('admin/i18n/proposals', { method: 'POST', body }),

  /** Super Admin only. */
  approve: (id: number) => apiRequest<TranslationProposal>(`admin/i18n/proposals/${id}/approve`, { method: 'POST' }),

  /** Super Admin only. */
  reject: (id: number, note: string) => apiRequest<TranslationProposal>(`admin/i18n/proposals/${id}/reject`, { method: 'POST', body: { note } }),

  /** The proposer withdraws their own pending proposal. */
  withdraw: (id: number) => apiRequest<TranslationProposal>(`admin/i18n/proposals/${id}/withdraw`, { method: 'POST' }),
};
