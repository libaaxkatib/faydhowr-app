import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { formatDate } from '@/utils/formatters';
import type { TemporaryReplacement } from '@/types/employee';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: one dedicated entry point for
 * "Temporary Replacement" / "Multi-company replacement work" / "Daily
 * replacement payment records" - a replacement employee (from anywhere,
 * any company) covers an EXISTING active work assignment. Payments are a
 * manual ledger (confirmed Phase 3 scope decision): recording a payment IS
 * the paid event, no pending/paid state machine.
 */
export function TemporaryReplacementsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [payingFor, setPayingFor] = useState<TemporaryReplacement | null>(null);
  const [ending, setEnding] = useState<TemporaryReplacement | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['temporary-replacements'], queryFn: hrApi.temporaryReplacements.list });

  const endMutation = useMutation({
    mutationFn: ({ id, endDate }: { id: number; endDate: string }) => hrApi.temporaryReplacements.end(id, { end_date: endDate }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      show('Coverage ended.');
      setEnding(null);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not end coverage.', 'error'),
  });

  const paymentMutation = useMutation({
    mutationFn: ({ id, paymentDate, amount, notes }: { id: number; paymentDate: string; amount: number; notes: string }) =>
      hrApi.temporaryReplacements.addPayment(id, { payment_date: paymentDate, amount, notes: notes || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      show('Payment recorded.');
      setPayingFor(null);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record payment.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Temporary Replacements"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Temporary Replacements' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              New Temporary Replacement
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label="Loading temporary replacements…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="refresh" title="No temporary replacements yet" />}

      {data && data.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {data.map((replacement) => (
            <Card key={replacement.id} className="p-5">
              <div className="mb-3 flex items-start justify-between">
                <div>
                  <h3 className="font-display text-base font-bold text-ink">
                    Covering {replacement.replaced_employee_name ?? 'Unknown'}
                  </h3>
                  <p className="text-xs text-ink-muted">
                    {replacement.client_company_name && `${replacement.client_company_name} · `}
                    {replacement.work_location_name}
                  </p>
                </div>
                <StatusBadge status={replacement.status} tone={replacement.status === 'active' ? 'warning' : 'neutral'} />
              </div>

              <button
                type="button"
                onClick={() => navigate(`/hr/employees/${replacement.replacement_employee_id}`)}
                className="mb-2 flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
              >
                <span className="text-ink">Replacement: {replacement.replacement_employee_name}</span>
                <span className="text-xs text-ink-faint">
                  {replacement.daily_rate} {replacement.currency}/day
                </span>
              </button>

              <p className="mb-3 text-xs text-ink-faint">
                {formatDate(replacement.start_date)} → {replacement.end_date ? formatDate(replacement.end_date) : 'Ongoing'}
              </p>

              {replacement.reason && <p className="mb-3 text-xs italic text-ink-faint">{replacement.reason}</p>}

              <p className="mb-1.5 text-xs font-medium text-ink-faint">Payments (total {replacement.total_paid})</p>
              {replacement.payments.length > 0 ? (
                <div className="mb-3 space-y-1.5">
                  {replacement.payments.map((payment) => (
                    <div key={payment.id} className="flex items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-sm">
                      <span className="text-ink">{formatDate(payment.payment_date)}</span>
                      <span className="text-ink-muted">{payment.amount} {replacement.currency}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mb-3 text-xs italic text-ink-faint">No payments recorded yet.</p>
              )}

              <PermissionGate module="hr">
                <div className="flex items-center gap-2 border-t border-border pt-3">
                  <Button size="sm" variant="outline" onClick={() => setPayingFor(replacement)}>
                    Record Payment
                  </Button>
                  {replacement.status === 'active' && (
                    <Button size="sm" variant="ghost" onClick={() => setEnding(replacement)}>
                      End Coverage
                    </Button>
                  )}
                </div>
              </PermissionGate>
            </Card>
          ))}
        </div>
      )}

      <CreateReplacementModal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        onCreated={() => {
          queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
          setIsCreateOpen(false);
        }}
      />

      {payingFor && (
        <RecordPaymentModal
          replacement={payingFor}
          onClose={() => setPayingFor(null)}
          onConfirm={(paymentDate, amount, notes) => paymentMutation.mutate({ id: payingFor.id, paymentDate, amount, notes })}
          isLoading={paymentMutation.isPending}
        />
      )}

      {ending && (
        <EndReplacementModal
          replacement={ending}
          onClose={() => setEnding(null)}
          onConfirm={(endDate) => endMutation.mutate({ id: ending.id, endDate })}
          isLoading={endMutation.isPending}
        />
      )}
    </div>
  );
}

function CreateReplacementModal({ isOpen, onClose, onCreated }: { isOpen: boolean; onClose: () => void; onCreated: () => void }) {
  const { show } = useToast();
  const { data: activeEmployees } = useQuery({
    queryKey: ['employees', { status: 'active', for: 'temporary-replacement' }],
    queryFn: () => hrApi.employees.list({ status: 'active', per_page: 100 }),
    enabled: isOpen,
  });

  const [coveredEmployeeId, setCoveredEmployeeId] = useState('');
  const [workAssignmentId, setWorkAssignmentId] = useState('');
  const [replacementEmployeeId, setReplacementEmployeeId] = useState('');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [dailyRate, setDailyRate] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [reason, setReason] = useState('');

  const { data: coveredEmployee } = useQuery({
    queryKey: ['employee', coveredEmployeeId],
    queryFn: () => hrApi.employees.get(Number(coveredEmployeeId)),
    enabled: isOpen && coveredEmployeeId !== '',
  });

  const createMutation = useMutation({
    mutationFn: () =>
      hrApi.temporaryReplacements.create({
        work_assignment_id: Number(workAssignmentId),
        replacement_employee_id: Number(replacementEmployeeId),
        start_date: startDate,
        daily_rate: Number(dailyRate),
        currency,
        reason: reason || undefined,
      }),
    onSuccess: () => {
      show('Temporary replacement recorded.');
      setCoveredEmployeeId('');
      setWorkAssignmentId('');
      setReplacementEmployeeId('');
      setDailyRate('');
      setReason('');
      onCreated();
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not create temporary replacement.', 'error'),
  });

  if (!isOpen) return null;

  const employeeOptions = (activeEmployees?.data ?? []).map((e) => ({ value: String(e.id), label: `${e.full_name} (${e.employee_number})` }));
  const assignmentOptions = (coveredEmployee?.active_work_assignments ?? []).map((a) => ({
    value: String(a.id),
    label: a.location_type === 'office' ? 'Fayadhowr Office' : `${a.client_company_name} · ${a.work_location_name}`,
  }));

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="New Temporary Replacement"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={createMutation.isPending}
            disabled={!workAssignmentId || !replacementEmployeeId || !dailyRate}
            onClick={() => createMutation.mutate()}
          >
            Create
          </Button>
        </>
      }
    >
      <FormField label="Employee to cover" htmlFor="tr-covered" required>
        <Select
          id="tr-covered"
          value={coveredEmployeeId}
          onChange={(e) => {
            setCoveredEmployeeId(e.target.value);
            setWorkAssignmentId('');
          }}
          placeholder="Select an active employee…"
          options={employeeOptions}
        />
      </FormField>
      <FormField label="Assignment being covered" htmlFor="tr-assignment" required>
        <Select
          id="tr-assignment"
          value={workAssignmentId}
          onChange={(e) => setWorkAssignmentId(e.target.value)}
          placeholder={coveredEmployeeId ? 'Select an active assignment…' : 'Select an employee first'}
          options={assignmentOptions}
        />
      </FormField>
      <FormField label="Replacement employee" htmlFor="tr-replacement" required>
        <Select
          id="tr-replacement"
          value={replacementEmployeeId}
          onChange={(e) => setReplacementEmployeeId(e.target.value)}
          placeholder="Select an active employee…"
          options={employeeOptions.filter((o) => o.value !== coveredEmployeeId)}
        />
      </FormField>
      <FormField label="Start date" htmlFor="tr-start" required>
        <input id="tr-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label="Daily rate" htmlFor="tr-rate" required>
          <input id="tr-rate" type="number" min={0} step="0.01" className={inputClasses} value={dailyRate} onChange={(e) => setDailyRate(e.target.value)} />
        </FormField>
        <FormField label="Currency" htmlFor="tr-currency" required>
          <input id="tr-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label="Reason" htmlFor="tr-reason">
        <textarea id="tr-reason" rows={2} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordPaymentModal({
  replacement,
  onClose,
  onConfirm,
  isLoading,
}: {
  replacement: TemporaryReplacement;
  onClose: () => void;
  onConfirm: (paymentDate: string, amount: number, notes: string) => void;
  isLoading: boolean;
}) {
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState(replacement.daily_rate);
  const [notes, setNotes] = useState('');

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record Payment"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} onClick={() => onConfirm(paymentDate, Number(amount), notes)}>Record</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{replacement.replacement_employee_name}</p>
      <FormField label="Payment date" htmlFor="pay-date" required>
        <input id="pay-date" type="date" className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
      <FormField label="Amount" htmlFor="pay-amount" required>
        <input id="pay-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
      </FormField>
      <FormField label="Notes" htmlFor="pay-notes">
        <textarea id="pay-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function EndReplacementModal({
  replacement,
  onClose,
  onConfirm,
  isLoading,
}: {
  replacement: TemporaryReplacement;
  onClose: () => void;
  onConfirm: (endDate: string) => void;
  isLoading: boolean;
}) {
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="End Coverage"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button variant="danger" size="sm" isLoading={isLoading} onClick={() => onConfirm(endDate)}>End Coverage</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{replacement.replacement_employee_name}</p>
      <FormField label="End date" htmlFor="tr-end-date" required>
        <input id="tr-end-date" type="date" className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </FormField>
    </Modal>
  );
}
