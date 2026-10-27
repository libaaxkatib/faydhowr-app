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
import { useI18n } from '@/i18n/useI18n';
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
  const { t, tValue } = useI18n();
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
      show(t('notify.marketing.rateConfigured'));
      setIsRateOpen(false);
      setRateValue('');
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.somethingWentWrong'), 'error'),
  });

  const recordAmountMutation = useMutation({
    mutationFn: () => marketingApi.commission.recordAmount(recording!.id, { amount: Number(amount), notes: amountNotes.trim() === '' ? null : amountNotes.trim() }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['commission-records'] });
      show(t('notify.marketing.commissionRecorded'));
      setRecording(null);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.marketing.somethingWentWrong'), 'error'),
  });

  const openRecord = (entry: CommissionRecord) => {
    setRecording(entry);
    setAmount(entry.amount ?? '');
    setAmountNotes(entry.notes ?? '');
  };

  return (
    <div>
      <PageHeader
        title={t('nav.marketing.commission')}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.marketing.commission') }]}
        actions={
          canRecordAmounts ? (
            <PermissionGate module="marketing">
              <Button onClick={() => setIsRateOpen(true)}>
                <Icon name="plus" size={15} />
                {t('action.marketing.configureRate')}
              </Button>
            </PermissionGate>
          ) : undefined
        }
      />

      <div className="mb-4 rounded-md border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning">
        <strong>{t('warning.marketing.commissionManualLead')}</strong> {t('warning.marketing.commissionManualBody')}
      </div>

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.configuredRates')}</h3>
        </CardHeader>
        {ratesLoading && <LoadingState label={t('option.marketing.loadingRates')} />}
        {ratesError && <ErrorState error={ratesError} onRetry={refetchRates} />}
        {rates && rates.length === 0 && <EmptyState icon="credit-card" title={t('warning.marketing.noRates')} />}
        {rates && rates.length > 0 && (
          <div className="divide-y divide-border">
            {rates.map((rate) => (
              <div key={rate.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">
                    <span className="mr-2 inline-flex items-center rounded-full bg-secondary-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-secondary">
                      {t('option.marketing.rateBadge')}
                    </span>
                    {rate.rate_type === 'percentage' ? `${rate.rate_value}%` : formatCurrency(Number(rate.rate_value))} — {rate.admin_name ?? t('option.marketing.defaultAllEmployees')}
                  </p>
                  <p className="text-xs text-ink-muted">{t('option.marketing.effectiveFromDate', { date: formatDate(rate.effective_from) })}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card>
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">{t('option.marketing.commissionEntries')}</h3>
        </CardHeader>
        {recordsLoading && <LoadingState label={t('option.marketing.loadingCommissionEntries')} />}
        {recordsError && <ErrorState error={recordsError} onRetry={refetchRecords} />}
        {records && records.data.length === 0 && <EmptyState icon="credit-card" title={t('warning.marketing.noCommissionEntries')} />}
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
                    <span className="mr-1 font-semibold uppercase tracking-wide">{t('option.marketing.amountColon')}</span>
                    {entry.amount ? formatCurrency(Number(entry.amount)) : <span className="italic">{t('status.marketing.commission.pending_calculation')}</span>}
                  </p>
                  <StatusBadge
                    status={entry.status}
                    label={entry.status === 'pending_calculation' ? t('status.marketing.commission.pending_calculation') : entry.status === 'recorded' ? t('status.marketing.commission.recorded') : tValue('status.marketing.commission', entry.status) ?? entry.status}
                    tone={entry.status === 'pending_calculation' ? 'neutral' : 'success'}
                  />
                  {canRecordAmounts && (entry.status === 'pending_calculation' || entry.status === 'recorded') && (
                    <div className="mt-1.5">
                      <Button variant="outline" size="sm" onClick={() => openRecord(entry)}>
                        {entry.amount ? t('action.marketing.changeAmount') : t('action.marketing.recordAmount')}
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
        title={t('confirm.marketing.recordCommissionTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setRecording(null)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={recordAmountMutation.isPending} disabled={amount.trim() === '' || Number.isNaN(Number(amount)) || Number(amount) < 0} onClick={() => recordAmountMutation.mutate()}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.marketing.agreedAmount')} htmlFor="commission-amount" required hint={t('warning.marketing.agreedAmountHint')}>
          <input id="commission-amount" type="number" min="0" step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.marketing.note')} htmlFor="commission-note">
          <input id="commission-note" type="text" className={inputClasses} value={amountNotes} onChange={(e) => setAmountNotes(e.target.value)} />
        </FormField>
      </Modal>

      <Modal
        isOpen={isRateOpen}
        onClose={() => setIsRateOpen(false)}
        title={t('confirm.marketing.configureRateTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsRateOpen(false)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createRateMutation.isPending} onClick={() => createRateMutation.mutate()}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.marketing.rateType')} htmlFor="rate-type" required>
          <Select
            id="rate-type"
            value={rateType}
            onChange={(e) => setRateType(e.target.value as CommissionRateType)}
            options={[
              { value: 'percentage', label: t('option.marketing.rateType.percentage') },
              { value: 'fixed', label: t('option.marketing.rateType.fixed') },
            ]}
          />
        </FormField>
        <FormField label={t('option.marketing.rateValue')} htmlFor="rate-value" required hint={t('warning.marketing.rateValueHint')}>
          <input id="rate-value" type="number" step="0.01" className={inputClasses} value={rateValue} onChange={(e) => setRateValue(e.target.value)} />
        </FormField>
        <FormField label={t('option.marketing.effectiveFrom')} htmlFor="effective-from" required>
          <input id="effective-from" type="date" className={inputClasses} value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
