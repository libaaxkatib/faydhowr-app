import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { ApiClientError } from '@/api/client';
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
import { Pagination } from '@/components/ui/Pagination';
import { ResetFiltersButton } from '@/components/ui/ListFilterControls';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { invalidateMarketing } from '@/utils/marketingCache';
import { canRecordCommissionAmounts } from '@/utils/marketingAccess';
import { marketingErrorMessage } from '@/utils/marketingErrors';
import { validateRateValue } from '@/utils/marketingFormRules';
import type { CommissionRateType, CommissionRecord } from '@/types/marketing';

const PAGE_SIZE = 20;
const FILTER_KEYS = ['month', 'employee'] as const;

/**
 * Manual commission recording, per docs/HRM_MARKETING_SRS.md §20-21/§39 — the
 * calculation formula is not approved, so nothing is computed here. A Marketing
 * Manager (or the Super Admin) types the agreed amount against an entry; until
 * then it honestly shows "Pending calculation". Team members see only their
 * own entries, and only the manager can record or change an amount. Entries are
 * logged from a record's page ("Log commission"); this page lists them by month,
 * employee and page.
 */
export function CommissionPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const { admin, isSuperAdmin } = useAuth();
  const { hasPermission } = useEffectivePermissions();
  const canRecordAmounts = canRecordCommissionAmounts({ isSuperAdmin, role: admin?.role });
  const canConfigureRates = canRecordAmounts && hasPermission('marketing.manage');
  const url = useUrlFilters();
  const month = url.get('month');
  const employeeFilter = url.get('employee');
  const page = url.page;
  const [recording, setRecording] = useState<CommissionRecord | null>(null);
  const [amount, setAmount] = useState('');
  const [amountNotes, setAmountNotes] = useState('');
  const [amountErrors, setAmountErrors] = useState<Record<string, string[]>>({});
  const [isRateOpen, setIsRateOpen] = useState(false);
  const [rateType, setRateType] = useState<CommissionRateType>('percentage');
  const [rateValue, setRateValue] = useState('');
  const [rateEmployee, setRateEmployee] = useState('');
  const [rateValueError, setRateValueError] = useState<string | undefined>(undefined);
  const [rateErrors, setRateErrors] = useState<Record<string, string[]>>({});
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));

  // Only the Super Admin and the Marketing Manager see everybody's entries (the server limits everyone else to their own), so only they get the employee choices.
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list, enabled: canRecordAmounts });
  const employeeOptions = (employees ?? []).map((employee) => ({ value: String(employee.id), label: employee.full_name }));

  const { data: rates, isLoading: ratesLoading, error: ratesError, refetch: refetchRates } = useQuery({
    queryKey: ['marketing-commission-rates'],
    queryFn: marketingApi.commission.rates,
  });

  const { data: records, isLoading: recordsLoading, error: recordsError, refetch: refetchRecords } = useQuery({
    queryKey: ['marketing-commission-records', { month, employee: employeeFilter, page }],
    queryFn: () =>
      marketingApi.commission.records({
        per_page: PAGE_SIZE,
        page,
        month: month || undefined,
        admin_id: employeeFilter ? Number(employeeFilter) : undefined,
      }),
    placeholderData: (previous) => previous,
  });

  const createRateMutation = useMutation({
    mutationFn: () =>
      marketingApi.commission.createRate({
        admin_id: rateEmployee ? Number(rateEmployee) : null,
        rate_type: rateType,
        rate_value: Number(rateValue),
        effective_from: effectiveFrom,
      }),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.rateConfigured'));
      setIsRateOpen(false);
      setRateValue('');
      setRateEmployee('');
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) setRateErrors(err.fieldErrors ?? {});
      else show(marketingErrorMessage(err, t('notify.marketing.somethingWentWrong')), 'error');
    },
  });

  const recordAmountMutation = useMutation({
    mutationFn: () => marketingApi.commission.recordAmount(recording!.id, { amount: Number(amount), notes: amountNotes.trim() === '' ? null : amountNotes.trim() }),
    onSuccess: () => {
      invalidateMarketing(queryClient);
      show(t('notify.marketing.commissionRecorded'));
      setRecording(null);
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation) setAmountErrors(err.fieldErrors ?? {});
      else show(marketingErrorMessage(err, t('notify.marketing.somethingWentWrong')), 'error');
    },
  });

  const openRecord = (entry: CommissionRecord) => {
    setRecording(entry);
    setAmount(entry.amount ?? '');
    setAmountNotes(entry.notes ?? '');
    setAmountErrors({});
  };

  const openRate = () => {
    setRateValueError(undefined);
    setRateErrors({});
    setIsRateOpen(true);
  };

  const submitRate = () => {
    const problem = validateRateValue(rateValue);
    setRateValueError(problem);
    setRateErrors({});
    if (!problem) createRateMutation.mutate();
  };

  const filtered = month !== '' || employeeFilter !== '';

  return (
    <div>
      <PageHeader
        title={t('nav.marketing.commission')}
        breadcrumb={[{ label: t('nav.marketing.marketing'), to: '/marketing' }, { label: t('nav.marketing.commission') }]}
        actions={
          canConfigureRates ? (
            <Button onClick={openRate}>
              <Icon name="plus" size={15} />
              {t('action.marketing.configureRate')}
            </Button>
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
        <div className="flex flex-wrap items-end gap-3 border-b border-border px-5 py-4">
          <div>
            <label htmlFor="commission-month" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.commissionMonth')}</label>
            <input id="commission-month" type="month" value={month} onChange={(e) => url.set('month', e.target.value)} className="h-9 rounded-sm border border-border bg-surface px-2 text-sm" />
          </div>
          {canRecordAmounts && (
            <div>
              <label htmlFor="commission-employee-filter" className="mb-1 block text-xs font-medium text-ink-muted">{t('option.marketing.commissionEmployeeFilter')}</label>
              <Select
                id="commission-employee-filter"
                value={employeeFilter}
                onChange={(e) => url.set('employee', e.target.value)}
                placeholder={t('option.marketing.allEmployees')}
                options={employeeOptions}
                className="w-48"
              />
            </div>
          )}
          <ResetFiltersButton count={url.activeCount(FILTER_KEYS)} onReset={() => url.reset(FILTER_KEYS)} />
        </div>
        {recordsLoading && <LoadingState label={t('option.marketing.loadingCommissionEntries')} />}
        {recordsError && <ErrorState error={recordsError} onRetry={refetchRecords} />}
        {records && records.data.length === 0 && (
          <EmptyState icon="credit-card" title={filtered ? t('warning.common.noMatchTitle') : t('warning.marketing.noCommissionEntries')} description={filtered ? t('warning.common.noMatchText') : undefined} />
        )}
        {records && records.data.length > 0 && (
          <div className="divide-y divide-border">
            {records.data.map((entry) => (
              <div key={entry.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium text-ink">
                    {entry.admin_name} — {entry.record_number} ({entry.type === 'xarun' ? 'XARUN' : 'PROJECT'})
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
        {records && records.meta.total > 0 && <Pagination meta={records.meta} onPageChange={url.setPage} />}
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
        <FormField label={t('option.marketing.agreedAmount')} htmlFor="commission-amount" required hint={t('warning.marketing.agreedAmountHint')} error={amountErrors.amount?.[0]}>
          <input id="commission-amount" type="number" min="0" step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.marketing.note')} htmlFor="commission-note" error={amountErrors.notes?.[0]}>
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
            <Button variant="outline" size="sm" onClick={() => setIsRateOpen(false)} disabled={createRateMutation.isPending}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createRateMutation.isPending} onClick={submitRate}>{t('action.save')}</Button>
          </>
        }
      >
        <FormField label={t('option.marketing.rateEmployee')} htmlFor="rate-employee" hint={t('option.marketing.rateEmployeeHint')} error={rateErrors.admin_id?.[0]}>
          <Select id="rate-employee" value={rateEmployee} onChange={(e) => setRateEmployee(e.target.value)} placeholder={t('option.marketing.defaultAllEmployees')} options={employeeOptions} />
        </FormField>
        <FormField label={t('option.marketing.rateType')} htmlFor="rate-type" required error={rateErrors.rate_type?.[0]}>
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
        <FormField label={t('option.marketing.rateValue')} htmlFor="rate-value" required hint={t('warning.marketing.rateValueHint')} error={rateValueError ?? rateErrors.rate_value?.[0]}>
          <input id="rate-value" type="number" min="0" step="0.01" className={inputClasses} value={rateValue} onChange={(e) => setRateValue(e.target.value)} />
        </FormField>
        <FormField label={t('option.marketing.effectiveFrom')} htmlFor="effective-from" required error={rateErrors.effective_from?.[0]}>
          <input id="effective-from" type="date" className={inputClasses} value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
        </FormField>
      </Modal>
    </div>
  );
}
