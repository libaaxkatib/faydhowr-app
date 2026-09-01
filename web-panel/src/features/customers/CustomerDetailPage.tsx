import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
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
import { formatCurrency, formatDate, formatDateTime, initialsOf } from '@/utils/formatters';
import type { UpdatableCustomerStatus } from '@/types/customer';

export function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const customerId = Number(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();

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
      show('Customer status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update status.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => customersApi.remove(customerId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      show('Customer deleted.');
      navigate('/mobile-app/customers');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete customer.', 'error'),
    onSettled: () => setConfirmAction(null),
  });

  const restoreMutation = useMutation({
    mutationFn: () => customersApi.restore(customerId, 'ACTIVE'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', customerId] });
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      show('Customer restored.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not restore customer.', 'error'),
    onSettled: () => setConfirmAction(null),
  });

  if (isLoading) return <LoadingState label="Loading customer…" />;
  if (error || !customer) return <ErrorState error={error} onRetry={refetch} />;

  const isDeleted = customer.status === 'DELETED';

  return (
    <div>
      <PageHeader
        title={customer.full_name}
        breadcrumb={[
          { label: 'Mobile App', to: '/dashboard' },
          { label: 'Customers', to: '/mobile-app/customers' },
          { label: customer.customer_number },
        ]}
        actions={
          <PermissionGate module="customers">
            <div className="flex items-center gap-2">
              {!isDeleted && (
                <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                  <Icon name="pencil" size={14} />
                  Edit
                </Button>
              )}
              {isDeleted ? (
                <Button variant="secondary" size="sm" onClick={() => setConfirmAction('restore')}>
                  Restore
                </Button>
              ) : (
                <Button variant="danger" size="sm" onClick={() => setConfirmAction('delete')}>
                  <Icon name="trash" size={14} />
                  Delete
                </Button>
              )}
            </div>
          </PermissionGate>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Profile</h3>
            <StatusBadge status={customer.status} />
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
              <Field label="Phone" value={customer.phone ?? '—'} />
              <Field label="Email" value={customer.email ?? '—'} />
              <Field label="Gender" value={customer.gender ?? '—'} />
              <Field label="Date of birth" value={formatDate(customer.date_of_birth)} />
              <Field label="Preferred language" value={customer.preferred_language?.toUpperCase() ?? '—'} />
              <Field label="Classification" value={customer.classification ?? '—'} />
              <Field label="Registered" value={formatDateTime(customer.registered_at)} />
              <Field label="Last login" value={formatDateTime(customer.last_login_at)} />
            </dl>

            {!isDeleted && (
              <div className="mt-6 flex items-center gap-2 border-t border-border pt-5">
                <span className="text-xs font-medium text-ink-muted">Change status:</span>
                {(['ACTIVE', 'INACTIVE', 'BLOCKED'] as const).map((option) => (
                  <Button
                    key={option}
                    variant={customer.status === option ? 'primary' : 'outline'}
                    size="sm"
                    disabled={customer.status === option}
                    isLoading={statusMutation.isPending && statusMutation.variables === option}
                    onClick={() => statusMutation.mutate(option)}
                  >
                    {option[0] + option.slice(1).toLowerCase()}
                  </Button>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Summary</h3>
          </CardHeader>
          <CardBody className="space-y-3">
            <SummaryRow label="Bookings" value={customer.summary?.bookings} />
            <SummaryRow label="Quotations" value={customer.summary?.quotations} />
            <SummaryRow label="Orders" value={customer.summary?.orders} />
            <SummaryRow label="Payments" value={customer.summary?.payments} />
            <div className="flex items-center justify-between border-t border-border pt-3">
              <span className="text-sm text-ink-muted">Total spent</span>
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
        title="Delete customer"
        description={`This soft-deletes ${customer.full_name}. The record can be restored later.`}
        confirmLabel="Delete"
        tone="danger"
        isLoading={deleteMutation.isPending}
        onConfirm={() => deleteMutation.mutate()}
        onCancel={() => setConfirmAction(null)}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'restore'}
        title="Restore customer"
        description={`Restore ${customer.full_name} to Active status.`}
        confirmLabel="Restore"
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
