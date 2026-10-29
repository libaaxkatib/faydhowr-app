import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { customersApi } from '@/api/customers';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { CustomerFormDialog } from '@/features/customers/CustomerFormDialog';
import { useI18n } from '@/i18n/useI18n';
import { formatCurrency, formatDate, formatDateTime, initialsOf } from '@/utils/formatters';
import type { UpdatableCustomerStatus } from '@/types/customer';

export function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const customerId = Number(id);
  const origin = useLocation().state as { fromPath?: string } | null;
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [confirmAction, setConfirmAction] = useState<'delete' | 'restore' | null>(null);

  const { data: customer, isLoading, error, refetch } = useQuery({
    queryKey: ['customer', customerId],
    queryFn: () => customersApi.get(customerId),
    enabled: Number.isFinite(customerId),
  });

  const statusMutation = useMutation({
    mutationFn: (status: UpdatableCustomerStatus) => customersApi.updateStatus(customerId, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', customerId] });
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      show(t('notify.customers.statusUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.customers.statusUpdateFailed'), 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => customersApi.remove(customerId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      show(t('notify.customers.deleted'));
      navigate('/mobile-app/customers');
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.customers.deleteFailed'), 'error'),
    onSettled: () => setConfirmAction(null),
  });

  const restoreMutation = useMutation({
    mutationFn: () => customersApi.restore(customerId, 'ACTIVE'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', customerId] });
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      show(t('notify.customers.restored'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.customers.restoreFailed'), 'error'),
    onSettled: () => setConfirmAction(null),
  });

  if (isLoading) return <LoadingState label={t('option.customers.loading')} />;
  if (error || !customer) return <ErrorState error={error} onRetry={refetch} />;

  const isDeleted = customer.status === 'DELETED';

  return (
    <div>
      <PageHeader
        title={customer.full_name}
        breadcrumb={[
          { label: t('nav.customers.mobileApp'), to: '/dashboard' },
          { label: t('nav.customers.customers'), to: origin?.fromPath?.startsWith('/mobile-app/customers') ? origin.fromPath : '/mobile-app/customers' },
          { label: customer.customer_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            {!isDeleted && (
              <PermissionGate module="customers" permission="customers.update">
                <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                  <Icon name="pencil" size={14} />
                  {t('action.edit')}
                </Button>
              </PermissionGate>
            )}
            {isDeleted ? (
              <PermissionGate module="customers" permission="customers.restore">
                <Button variant="secondary" size="sm" onClick={() => setConfirmAction('restore')}>
                  {t('action.customers.restore')}
                </Button>
              </PermissionGate>
            ) : (
              <PermissionGate module="customers" permission="customers.delete">
                <Button variant="danger" size="sm" onClick={() => setConfirmAction('delete')}>
                  <Icon name="trash" size={14} />
                  {t('action.customers.delete')}
                </Button>
              </PermissionGate>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.customers.profile')}</h3>
            <StatusBadge status={customer.status} label={tValue('option.customers.status', customer.status)} />
          </CardHeader>
          <CardBody>
            <div className="mb-5 flex items-center gap-4">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">
                {initialsOf(customer.full_name)}
              </div>
              <div>
                <p className="font-display text-base font-bold text-ink">{customer.full_name}</p>
                <p className="text-sm text-ink-muted">{customer.customer_number}</p>
              </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              <Field label={t('option.label.phone')} value={customer.phone ?? '—'} />
              <Field label={t('option.shell.email')} value={customer.email ?? '—'} />
              <Field label={t('option.label.gender')} value={customer.gender ? (tValue('option.gender', customer.gender) ?? customer.gender) : '—'} />
              <Field label={t('option.customers.dateOfBirth')} value={formatDate(customer.date_of_birth)} />
              <Field label={t('option.customers.preferredLanguage')} value={customer.preferred_language?.toUpperCase() ?? '—'} />
              <Field label={t('option.customers.classification')} value={customer.classification ? (tValue('status.customers.classification', customer.classification) ?? customer.classification) : '—'} />
              <Field label={t('option.customers.registered')} value={formatDateTime(customer.registered_at)} />
              <Field label={t('option.customers.lastLogin')} value={formatDateTime(customer.last_login_at)} />
            </dl>

            {!isDeleted && (
              <PermissionGate module="customers" permission="customers.update">
              <div className="mt-6 flex items-center gap-2 border-t border-border pt-5">
                <span className="text-xs font-medium text-ink-muted">{t('option.customers.changeStatus')}</span>
                {(['ACTIVE', 'INACTIVE', 'BLOCKED'] as const).map((option) => (
                  <Button
                    key={option}
                    variant={customer.status === option ? 'primary' : 'outline'}
                    size="sm"
                    disabled={customer.status === option}
                    isLoading={statusMutation.isPending && statusMutation.variables === option}
                    onClick={() => statusMutation.mutate(option)}
                  >
                    {t(`option.customers.status.${option}`)}
                  </Button>
                ))}
              </div>
              </PermissionGate>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.customers.summary')}</h3>
          </CardHeader>
          <CardBody className="space-y-3">
            <SummaryRow label={t('nav.shell.bookings')} value={customer.summary?.bookings} />
            <SummaryRow label={t('nav.shell.quotations')} value={customer.summary?.quotations} />
            <SummaryRow label={t('nav.shell.orders')} value={customer.summary?.orders} />
            <SummaryRow label={t('nav.shell.payments')} value={customer.summary?.payments} />
            <div className="flex items-center justify-between border-t border-border pt-3">
              <span className="text-sm text-ink-muted">{t('option.customers.totalSpent')}</span>
              <span className="font-display text-sm font-bold text-ink">
                {customer.summary ? formatCurrency(customer.summary.total_spent) : '—'}
              </span>
            </div>
          </CardBody>
        </Card>
      </div>

      <CustomerFormDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} mode="edit" customer={customer} />

      <ConfirmDialog
        isOpen={confirmAction === 'delete'}
        title={t('confirm.customers.deleteTitle')}
        description={t('confirm.customers.deleteBody', { name: customer.full_name })}
        confirmLabel={t('action.customers.delete')}
        tone="danger"
        isLoading={deleteMutation.isPending}
        onConfirm={() => deleteMutation.mutate()}
        onCancel={() => setConfirmAction(null)}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'restore'}
        title={t('confirm.customers.restoreTitle')}
        description={t('confirm.customers.restoreBody', { name: customer.full_name })}
        confirmLabel={t('action.customers.restore')}
        isLoading={restoreMutation.isPending}
        onConfirm={() => restoreMutation.mutate()}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-ink-faint">{label}</dt>
      <dd className="text-ink">{value}</dd>
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: number | undefined }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-sm text-ink-muted">{label}</span>
      <span className="text-sm font-semibold text-ink">{value ?? '—'}</span>
    </div>
  );
}
