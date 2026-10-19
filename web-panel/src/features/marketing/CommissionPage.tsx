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
import { useAuth } from '@/features/auth/useAuth';
import { formatCurrency, formatDate } from '@/utils/formatters';
import type { CommissionRateType, CommissionRecord } from '@/types/marketing';

/**
 * Manual commission recording, per docs/HRM_MARKETING_SRS.md §20-21/§39 — the
 * calculation formula is not approved, so nothing is computed here. A Marketing
 * Manager (or the Super Admin) types the agreed amount against an entry; until
 * then it honestly shows "Pending calculation". Team members see only their
 * own entries, and only the manager can record or change an amount.
 */
export function CommissionPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { admin, isSuperAdmin } = useAuth();
  const canRecordAmounts = isSuperAdmin || admin?.role === 'marketing_manager';
  const [recording, setRecording] = useState<CommissionRecord | null>(null);
  const [amount, setAmount] = useState('');
  const [amountNotes, setAmountNotes] = useState('');
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

  const recordAmountMutation = useMutation({
    mutationFn: () => marketingApi.commission.recordAmount(recording!.id, { amount: Number(amount), notes: amountNotes.trim() === '' ? null : amountNotes.trim() }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['commission-records'] });
      show('Commission amount recorded.');
      setRecording(null);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const openRecord = (entry: CommissionRecord) => {
    setRecording(entry);
    setAmount(entry.amount ?? '');
    setAmountNotes(entry.notes ?? '');
  };

  return (
    <div>
      <PageHeader
        title="Commission"
        breadcrumb={[{ label: 'Marketing', to: '/marketing' }, { label: 'Commission' }]}
        actions={
          canRecordAmounts ? (
            <PermissionGate module="marketing">
              <Button onClick={() => setIsRateOpen(true)}>
                <Icon name="plus" size={15} />
                Configure Rate
              </Button>
            </PermissionGate>
          ) : undefined
        }
      />

      <div className="mb-4 rounded-md border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning">
        <strong>Commission is recorded manually.</strong> The calculation formula is not approved, so nothing is computed or
        estimated here: an entry shows "Pending calculation" until the Marketing Manager types the agreed amount.
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
                    label={entry.status === 'pending_calculation' ? 'Pending calculation' : entry.status === 'recorded' ? 'Recorded' : entry.status}
                    tone={entry.status === 'pending_calculation' ? 'neutral' : 'success'}
                  />
                  {canRecordAmounts && (entry.status === 'pending_calculation' || entry.status === 'recorded') && (
                    <div className="mt-1.5">
                      <Button variant="outline" size="sm" onClick={() => openRecord(entry)}>
                        {entry.amount ? 'Change amount' : 'Record amount'}
                      </Button>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal
        isOpen={recording !== null}
        onClose={() => setRecording(null)}
        title="Record Commission Amount"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setRecording(null)}>Cancel</Button>
            <Button size="sm" isLoading={recordAmountMutation.isPending} disabled={amount.trim() === '' || Number.isNaN(Number(amount)) || Number(amount) < 0} onClick={() => recordAmountMutation.mutate()}>Save</Button>
          </>
        }
      >
        <FormField label="Agreed amount" htmlFor="commission-amount" required hint="Typed by a person. No formula or rate is applied.">
          <input id="commission-amount" type="number" min="0" step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label="Note" htmlFor="commission-note">
          <input id="commission-note" type="text" className={inputClasses} value={amountNotes} onChange={(e) => setAmountNotes(e.target.value)} />
        </FormField>
      </Modal>

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
