import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useI18n } from '@/i18n/useI18n';
import { localizeServerMessage } from '@/i18n/serverMessages';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { multiCompanyPolicyCardVisible } from '@/utils/policyCardVisibility';

interface HrPolicy {
  key: string;
  label: string;
  description: string;
  enabled: boolean;
  changed_by: string | null;
  changed_at: string | null;
  reason: string | null;
  /** The server says whether this person's role may switch the policy; it re-checks on every change. */
  can_change?: boolean;
}

interface HrPoliciesResponse {
  policies: HrPolicy[];
  employees_with_concurrent_assignments: number;
}

/**
 * Shows whether one employee may work for more than one company at the same time. Roles that may view HR see the state, except the HR Manager, who
 * is not shown this card at all; only the Super Admin gets the switch (the server says so through can_change and refuses anyone else). Every change asks for a reason, which is
 * kept in the audit history together with who changed it and when - the state is never changed silently.
 */
export function MultiCompanyPolicyCard() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, language } = useI18n();
  const { admin } = useAuth();
  // Not shown to the HR Manager (owner decision 2026-10-29); the setting itself is unchanged and stays with the Super Admin.
  const visible = multiCompanyPolicyCardVisible(admin?.role);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data } = useQuery({ queryKey: ['hr-policies'], queryFn: () => apiRequest<HrPoliciesResponse>('admin/hr/policies'), enabled: visible });
  const policy = data?.policies.find((p) => p.key === 'multi_company_assignments');

  const mutation = useMutation({
    mutationFn: (enabled: boolean) => apiRequest(`admin/hr/policies/multi_company_assignments`, { method: 'PUT', body: { enabled, reason: reason.trim() } }),
    onSuccess: (_result, enabled) => {
      queryClient.invalidateQueries({ queryKey: ['hr-policies'] });
      queryClient.invalidateQueries({ queryKey: ['company-employee-candidates'] });
      show(enabled ? t('notify.company.policyOn') : t('notify.company.policyOff'));
      setOpen(false);
      setReason('');
    },
    onError: (e) => setError(friendlyErrorMessage(e, t('notify.company.couldNotChangePolicy'))),
  });

  if (!visible || !policy) return null;

  const target = !policy.enabled;
  const reasonOk = reason.trim().length >= 5;

  return (
    <Card className="mb-4">
      <CardBody className="flex flex-wrap items-center justify-between gap-3">
        <div className="max-w-2xl">
          <p className="flex items-center gap-2 text-sm font-semibold text-ink">
            {localizeServerMessage(language, policy.label)}
            <StatusBadge status={policy.enabled ? 'active' : 'inactive'} label={policy.enabled ? t('status.company.on') : t('status.company.off')} tone={policy.enabled ? 'warning' : 'neutral'} />
          </p>
          <p className="text-xs text-ink-muted">{localizeServerMessage(language, policy.description)}</p>
          {policy.changed_by && <p className="mt-1 text-xs text-ink-faint">{t('option.company.lastChangedBy', { name: policy.changed_by })}{policy.reason ? ` — ${policy.reason}` : ''}</p>}
          {policy.enabled && (data?.employees_with_concurrent_assignments ?? 0) > 0 && (
            <p className="mt-1 text-xs text-ink-muted">{t('option.company.concurrentEmployees', { count: data?.employees_with_concurrent_assignments ?? 0 })}</p>
          )}
        </div>
        {policy.can_change === true && (
          <Button variant="outline" size="sm" onClick={() => { setError(null); setOpen(true); }}>
            {policy.enabled ? t('action.company.turnOff') : t('action.company.turnOn')}
          </Button>
        )}
      </CardBody>

      <Modal
        isOpen={open}
        onClose={() => setOpen(false)}
        title={target ? t('confirm.company.policyTurnOnTitle') : t('confirm.company.policyTurnOffTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setOpen(false)} disabled={mutation.isPending}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={mutation.isPending} disabled={!reasonOk} onClick={() => mutation.mutate(target)}>
              {target ? t('action.company.turnOn') : t('action.company.turnOff')}
            </Button>
          </>
        }
      >
        {error && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{error}</div>}
        <p className="mb-4 text-sm text-ink-muted">
          {target
            ? t('confirm.company.policyTurnOnBody')
            : t('confirm.company.policyTurnOffBody')}
        </p>
        <FormField label={t('confirm.company.policyReasonLabel')} htmlFor="policy-reason" required hint={t('confirm.company.policyReasonHint')}>
          <input id="policy-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
        </FormField>
      </Modal>
    </Card>
  );
}
