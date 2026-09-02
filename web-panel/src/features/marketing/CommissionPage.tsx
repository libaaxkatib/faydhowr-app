import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatCurrency, formatDate } from '@/utils/formatters';
import type { CommissionRateType } from '@/types/marketing';

/**
 * Foundation only, per docs/HRM_MARKETING_SRS.md §20-21/§39 — the
 * calculation formula is not yet approved. This screen configures rates and
 * shows logged commission entries; it never computes or displays a
 * fabricated total, and every record's amount/status honestly shows
 * "Pending calculation" until a future, explicitly-approved step fills
 * them in.
 */
export function CommissionPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isRateOpen, setIsRateOpen] = useState(false);
  const [rateType, setRateType] = useState<CommissionRateType>('percentage');
  const [rateValue, setRateValue] = useState('');
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));

  const { data: rates, isLoading: ratesLoading, error: ratesError, refetch: refetchRates } = useQuery({
    queryKey: ['commission-rates'],
    queryFn: marketingApi.commission.rates,
  });

  const { data: records, isLoading: recordsLoading, error: recordsError, refetch: refetchRecords } = useQuery({
    queryKey: ['commission-records'],
    queryFn: () => marketingApi.commission.records({ per_page: 20 }),
  });

  const createRateMutation = useMutation({
    mutationFn: () =>
      marketingApi.commission.createRate({
        rate_type: rateType,
        rate_value: Number(rateValue),
        effective_from: effectiveFrom,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['commission-rates'] });
      show('Commission rate configured.');
      setIsRateOpen(false);
      setRateValue('');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Commission"
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Commission' }]}
        actions={
          <PermissionGate module="marketing">
            <Button onClick={() => setIsRateOpen(true)}>
              <Icon name="plus" size={15} />
              Configure Rate
            </Button>
          </PermissionGate>
        }
      />

      <div className="mb-4 rounded-md border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning">
        <strong>Calculation formula not yet approved.</strong> Rates below record what's configured; commission entries show
        "Pending calculation" until management approves how amounts are computed. No totals are estimated here.
      </div>

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">Configured Rates</h3>
        </CardHeader>
        {ratesLoading && <LoadingState label="Loading rates…" />}
        {ratesError && <ErrorState error={ratesError} onRetry={refetchRates} />}
        {rates && rates.length === 0 && <EmptyState icon="credit-card" title="No rates configured yet" />}
        {rates && rates.length > 0 && (
          <div className="divide-y divide-border">
            {rates.map((rate) => (
              <div key={rate.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">
                    <span className="mr-2 inline-flex items-center rounded-full bg-secondary-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-secondary">
                      Rate
                    </span>
                    {rate.rate_type === 'percentage' ? `${rate.rate_value}%` : formatCurrency(Number(rate.rate_value))} — {rate.admin_name ?? 'Default (all employees)'}
                  </p>
                  <p className="text-xs text-ink-muted">Effective from {formatDate(rate.effective_from)}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card>
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">Commission Entries</h3>
        </CardHeader>
        {recordsLoading && <LoadingState label="Loading commission entries…" />}
        {recordsError && <ErrorState error={recordsError} onRetry={refetchRecords} />}
        {records && records.data.length === 0 && <EmptyState icon="credit-card" title="No commission entries logged yet" />}
        {records && records.data.length > 0 && (
          <div className="divide-y divide-border">
            {records.data.map((entry) => (
              <div key={entry.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">
                    {entry.admin_name} — {entry.record_number} ({entry.type})
                  </p>
                  <p className="text-xs text-ink-muted">{formatDate(entry.reference_date)}</p>
                </div>
                <div className="text-right">
                  <p className="mb-1 text-xs text-ink-faint">
                    <span className="mr-1 font-semibold uppercase tracking-wide">Amount:</span>
                    {entry.amount ? formatCurrency(Number(entry.amount)) : <span className="italic">Pending calculation</span>}
                  </p>
                  <StatusBadge
                    status={entry.status}
                    label={entry.status === 'pending_calculation' ? 'Pending calculation' : entry.status}
                    tone={entry.status === 'pending_calculation' ? 'neutral' : 'success'}
                  />
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal
        isOpen={isRateOpen}
        onClose={() => setIsRateOpen(false)}
        title="Configure Commission Rate"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsRateOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={createRateMutation.isPending} onClick={() => createRateMutation.mutate()}>Save</Button>
          </>
        }
      >
        <FormField label="Rate type" htmlFor="rate-type" required>
          <Select
            id="rate-type"
            value={rateType}
            onChange={(e) => setRateType(e.target.value as CommissionRateType)}
            options={[
              { value: 'percentage', label: 'Percentage' },
              { value: 'fixed', label: 'Fixed amount' },
            ]}
          />
        </FormField>
        <FormField label="Rate value" htmlFor="rate-value" required hint="What rate is configured — not a calculation formula.">
          <input id="rate-value" type="number" step="0.01" className={inputClasses} value={rateValue} onChange={(e) => setRateValue(e.target.value)} />
        </FormField>
        <FormField label="Effective from" htmlFor="effective-from" required>
          <input id="effective-from" type="date" className={inputClasses} value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
