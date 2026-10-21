import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { friendlyErrorMessage } from '@/utils/hrHelpers';

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
 * Shows whether one employee may work for more than one company at the same time. Everyone who can view HR sees the state; the Super Admin
 * and the HR Manager get the switch (the server says so through can_change and refuses anyone else). Every change asks for a reason, which is
 * kept in the audit history together with who changed it and when - the state is never changed silently.
 */
export function MultiCompanyPolicyCard() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data } = useQuery({ queryKey: ['hr-policies'], queryFn: () => apiRequest<HrPoliciesResponse>('admin/hr/policies') });
  const policy = data?.policies.find((p) => p.key === 'multi_company_assignments');

  const mutation = useMutation({
    mutationFn: (enabled: boolean) => apiRequest(`admin/hr/policies/multi_company_assignments`, { method: 'PUT', body: { enabled, reason: reason.trim() } }),
    onSuccess: (_result, enabled) => {
      queryClient.invalidateQueries({ queryKey: ['hr-policies'] });
      queryClient.invalidateQueries({ queryKey: ['company-employee-candidates'] });
      show(enabled ? 'Multiple-company assignments are now ON.' : 'Multiple-company assignments are now OFF.');
      setOpen(false);
      setReason('');
    },
    onError: (e) => setError(friendlyErrorMessage(e, 'Could not change the policy. Nothing was changed.')),
  });

  if (!policy) return null;

  const target = !policy.enabled;
  const reasonOk = reason.trim().length >= 5;

  return (
    <Card className="mb-4">
      <CardBody className="flex flex-wrap items-center justify-between gap-3">
        <div className="max-w-2xl">
          <p className="flex items-center gap-2 text-sm font-semibold text-ink">
            {policy.label}
            <StatusBadge status={policy.enabled ? 'active' : 'inactive'} label={policy.enabled ? 'On' : 'Off'} tone={policy.enabled ? 'warning' : 'neutral'} />
          </p>
          <p className="text-xs text-ink-muted">{policy.description}</p>
          {policy.changed_by && <p className="mt-1 text-xs text-ink-faint">Last changed by {policy.changed_by}{policy.reason ? ` — ${policy.reason}` : ''}</p>}
          {policy.enabled && (data?.employees_with_concurrent_assignments ?? 0) > 0 && (
            <p className="mt-1 text-xs text-ink-muted">{data?.employees_with_concurrent_assignments} employee(s) currently hold more than one open assignment.</p>
          )}
        </div>
        {policy.can_change === true && (
          <Button variant="outline" size="sm" onClick={() => { setError(null); setOpen(true); }}>
            {policy.enabled ? 'Turn off' : 'Turn on'}
          </Button>
        )}
      </CardBody>

      <Modal
        isOpen={open}
        onClose={() => setOpen(false)}
        title={target ? 'Turn on multiple-company assignments?' : 'Turn off multiple-company assignments?'}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setOpen(false)} disabled={mutation.isPending}>Cancel</Button>
            <Button size="sm" isLoading={mutation.isPending} disabled={!reasonOk} onClick={() => mutation.mutate(target)}>
              {target ? 'Turn on' : 'Turn off'}
            </Button>
          </>
        }
      >
        {error && <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">{error}</div>}
        <p className="mb-4 text-sm text-ink-muted">
          {target
            ? 'Employees will be able to hold assignments at more than one company at the same time. Each assignment keeps its own company, position, dates, salary and currency; payroll makes one line per assignment.'
            : 'No new overlapping assignment at a different company will be allowed. Assignments that already exist are not changed.'}
        </p>
        <FormField label="Reason (recorded in the audit history)" htmlFor="policy-reason" required hint="At least 5 characters.">
          <input id="policy-reason" className={inputClasses} value={reason} onChange={(event) => setReason(event.target.value)} />
        </FormField>
      </Modal>
    </Card>
  );
}
