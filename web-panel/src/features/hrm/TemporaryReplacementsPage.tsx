import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
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
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatCurrency, formatDate } from '@/utils/formatters';
import { parseReplacementStatus } from '@/utils/hrDashboardLinks';
import { assignmentLabel, describeDailyRate, expandDateRange, friendlyErrorMessage, localMonth, localToday } from '@/utils/hrHelpers';
import type { EmploymentType, TemporaryReplacement, TemporaryReplacementWorkday, TemporaryReplacementWorkdayStatus } from '@/types/employee';

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: one dedicated entry point for
 * "Temporary Replacement" - a Temporary employee covers a Permanent employee's
 * assignment. The cost is no longer typed in: it is worked out from the covered
 * employee's salary, only for days HR has verified, and (once approved) deducted
 * once from the covered employee's pay. Payments are still a manual ledger.
 */
export function TemporaryReplacementsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [payingFor, setPayingFor] = useState<TemporaryReplacement | null>(null);
  const [ending, setEnding] = useState<TemporaryReplacement | null>(null);
  const [workdaysForId, setWorkdaysForId] = useState<number | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['temporary-replacements'], queryFn: hrApi.temporaryReplacements.list });
  const workdaysFor = data?.find((replacement) => replacement.id === workdaysForId) ?? null;
  // The HR dashboard's Active Replacements card links here with ?status=active.
  const statusFilter = parseReplacementStatus(searchParams);
  const visibleReplacements = data && statusFilter ? data.filter((replacement) => replacement.status === statusFilter) : data;

  const endMutation = useMutation({
    mutationFn: ({ id, endDate }: { id: number; endDate: string }) => hrApi.temporaryReplacements.end(id, { end_date: endDate }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      show('Coverage ended.');
      setEnding(null);
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not end coverage.'), 'error'),
  });

  const paymentMutation = useMutation({
    mutationFn: ({ id, paymentDate, amount, notes }: { id: number; paymentDate: string; amount: number; notes: string }) =>
      hrApi.temporaryReplacements.addPayment(id, { payment_date: paymentDate, amount, notes: notes || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      show('Payment recorded.');
      setPayingFor(null);
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not record payment.'), 'error'),
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

      {data && data.length > 0 && statusFilter && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm text-ink-muted">
          <span>
            Showing {statusFilter} replacements only ({visibleReplacements?.length ?? 0} of {data.length}).
          </span>
          <button type="button" onClick={() => setSearchParams({}, { replace: true })} className="font-medium text-primary hover:underline">
            Show all replacements
          </button>
        </div>
      )}

      {data && data.length > 0 && visibleReplacements && visibleReplacements.length === 0 && (
        <EmptyState icon="refresh" title={`No ${statusFilter} temporary replacements`} />
      )}

      {visibleReplacements && visibleReplacements.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {visibleReplacements.map((replacement) => (
            <Card key={replacement.id} className="p-5">
              <div className="mb-3 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-display text-base font-bold text-ink">
                    Covering {replacement.replaced_employee_name ?? 'Unknown'}
                  </h3>
                  <p className="text-xs text-ink-muted">
                    {[replacement.client_company_name, replacement.work_location_name].filter(Boolean).join(' · ') || 'Workplace not set yet'}
                  </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <StatusBadge status={replacement.status} tone={replacement.status === 'active' ? 'warning' : 'neutral'} />
                  <StatusBadge
                    status={replacement.approved ? 'approved' : 'awaiting-approval'}
                    label={replacement.approved ? 'Cost approved' : 'Cost not approved yet'}
                    tone={replacement.approved ? 'success' : 'neutral'}
                  />
                </div>
              </div>

              <button
                type="button"
                onClick={() => navigate(`/hr/employees/${replacement.replacement_employee_id}`)}
                className="mb-2 flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
              >
                <span className="text-ink">Temporary employee: {replacement.replacement_employee_name}</span>
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
                      <span className="text-ink-muted">{payment.amount} {replacement.currency ?? 'USD'}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mb-3 text-xs italic text-ink-faint">No payments recorded yet.</p>
              )}

              <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                <Button size="sm" variant="outline" onClick={() => setWorkdaysForId(replacement.id)}>
                  <Icon name="calendar" size={14} />
                  Workdays &amp; cost
                </Button>
                <PermissionGate module="hr">
                  <Button size="sm" variant="outline" onClick={() => setPayingFor(replacement)}>
                    Record Payment
                  </Button>
                  {replacement.status === 'active' && (
                    <Button size="sm" variant="ghost" onClick={() => setEnding(replacement)}>
                      End Coverage
                    </Button>
                  )}
                </PermissionGate>
              </div>
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

      {workdaysFor && <WorkdaysModal replacement={workdaysFor} onClose={() => setWorkdaysForId(null)} />}

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

/**
 * Searchable list of Active employees of one employment type. The list endpoint
 * has no employment-type filter, so the type is filtered here after searching.
 */
function EmployeePicker({
  id,
  employmentType,
  value,
  onChange,
  excludeId,
  placeholder,
}: {
  id: string;
  employmentType: EmploymentType;
  value: string;
  onChange: (employeeId: string) => void;
  excludeId?: string;
  placeholder: string;
}) {
  const [search, setSearch] = useState('');
  const [selectedLabel, setSelectedLabel] = useState('');
  const debouncedSearch = useDebouncedValue(search, 350);

  const { data, isFetching } = useQuery({
    queryKey: ['employees', { status: 'active', for: 'replacement-picker', search: debouncedSearch }],
    queryFn: () => hrApi.employees.list({ status: 'active', search: debouncedSearch || undefined, per_page: 100 }),
  });

  const options = (data?.data ?? [])
    .filter((employee) => employee.employment_type === employmentType && String(employee.id) !== excludeId)
    .map((employee) => ({ value: String(employee.id), label: `${employee.full_name} (${employee.employee_number})` }));

  // Keep the chosen person visible even after the search box narrows the list to someone else.
  if (value !== '' && selectedLabel !== '' && !options.some((option) => option.value === value)) {
    options.unshift({ value, label: selectedLabel });
  }

  return (
    <div className="space-y-1.5">
      <input
        type="search"
        className={inputClasses}
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder="Type a name or employee number to search"
        aria-label="Search employees"
      />
      <Select
        id={id}
        value={value}
        onChange={(e) => {
          onChange(e.target.value);
          setSelectedLabel(options.find((option) => option.value === e.target.value)?.label ?? '');
        }}
        placeholder={isFetching ? 'Searching…' : options.length === 0 ? `No ${employmentType} employees found` : placeholder}
        options={options}
      />
    </div>
  );
}

function CreateReplacementModal({ isOpen, onClose, onCreated }: { isOpen: boolean; onClose: () => void; onCreated: () => void }) {
  const { show } = useToast();

  const [coveredEmployeeId, setCoveredEmployeeId] = useState('');
  const [workAssignmentId, setWorkAssignmentId] = useState('');
  const [replacementEmployeeId, setReplacementEmployeeId] = useState('');
  const [startDate, setStartDate] = useState(localToday());
  const [endDate, setEndDate] = useState('');
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
        end_date: endDate || null,
        reason: reason || undefined,
      }),
    onSuccess: () => {
      show('Temporary replacement recorded.');
      setCoveredEmployeeId('');
      setWorkAssignmentId('');
      setReplacementEmployeeId('');
      setEndDate('');
      setReason('');
      onCreated();
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not create temporary replacement.'), 'error'),
  });

  if (!isOpen) return null;

  const assignmentOptions = (coveredEmployee?.active_work_assignments ?? []).map((a) => ({
    value: String(a.id),
    label: assignmentLabel(a),
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
            disabled={!workAssignmentId || !replacementEmployeeId || !startDate}
            onClick={() => createMutation.mutate()}
          >
            Create
          </Button>
        </>
      }
    >
      <p className="mb-4 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
        A Temporary employee covers a Permanent employee&apos;s assignment. You do not enter a daily rate: the cost is worked out from the
        covered employee&apos;s salary divided by the days in the month, and only for days you verify.
      </p>
      <FormField label="Permanent employee to cover" htmlFor="tr-covered" required>
        <EmployeePicker
          id="tr-covered"
          employmentType="permanent"
          value={coveredEmployeeId}
          onChange={(value) => {
            setCoveredEmployeeId(value);
            setWorkAssignmentId('');
          }}
          excludeId={replacementEmployeeId}
          placeholder="Select an active Permanent employee…"
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
      <FormField label="Temporary employee doing the cover" htmlFor="tr-replacement" required>
        <EmployeePicker
          id="tr-replacement"
          employmentType="temporary"
          value={replacementEmployeeId}
          onChange={setReplacementEmployeeId}
          excludeId={coveredEmployeeId}
          placeholder="Select an active Temporary employee…"
        />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label="Start date" htmlFor="tr-start" required>
          <input id="tr-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
        </FormField>
        <FormField label="End date (optional)" htmlFor="tr-end">
          <input id="tr-end" type="date" min={startDate} className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        </FormField>
      </div>
      <FormField label="Reason" htmlFor="tr-reason">
        <textarea id="tr-reason" rows={2} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}

const WORKDAY_STATUS: Record<TemporaryReplacementWorkdayStatus, { label: string; tone: 'warning' | 'success' | 'neutral' }> = {
  recorded: { label: 'Recorded - not verified yet', tone: 'warning' },
  verified: { label: 'Verified', tone: 'success' },
  void: { label: 'Voided', tone: 'neutral' },
};

/**
 * The workday ledger for one replacement: which days the Temporary employee
 * really worked, which are verified, and what the month costs. Only VERIFIED
 * days are charged. The cost preview is shown only to people who may view payroll.
 */
function WorkdaysModal({ replacement, onClose }: { replacement: TemporaryReplacement; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  const canViewPayroll = hasPermission('hr.payroll.view');
  const canApprove = hasPermission('hr.payroll.approve');

  const [dates, setDates] = useState<string[]>([]);
  const [rangeFrom, setRangeFrom] = useState(localToday());
  const [rangeTo, setRangeTo] = useState('');
  const [notes, setNotes] = useState('');
  const [period, setPeriod] = useState(localMonth());
  const [voiding, setVoiding] = useState<TemporaryReplacementWorkday | null>(null);
  const [isApproveOpen, setIsApproveOpen] = useState(false);

  const today = localToday();
  const latestDate = replacement.end_date && replacement.end_date < today ? replacement.end_date : today;

  const { data: workdays, isLoading, error } = useQuery({
    queryKey: ['replacement-workdays', replacement.id],
    queryFn: () => hrApi.temporaryReplacements.workdays.list(replacement.id),
  });

  const { data: cost, isLoading: isCostLoading, error: costError } = useQuery({
    queryKey: ['replacement-cost', replacement.id, period],
    queryFn: () => hrApi.temporaryReplacements.cost(replacement.id, period),
    enabled: canViewPayroll && /^\d{4}-(0[1-9]|1[0-2])$/.test(period),
  });

  const refreshLedger = () => {
    queryClient.invalidateQueries({ queryKey: ['replacement-workdays', replacement.id] });
    queryClient.invalidateQueries({ queryKey: ['replacement-cost', replacement.id] });
  };

  const recordMutation = useMutation({
    mutationFn: (verify: boolean) =>
      hrApi.temporaryReplacements.workdays.record(replacement.id, { dates, verify, notes: notes || null }),
    onSuccess: (_result, verify) => {
      refreshLedger();
      show(verify ? `${dates.length} day(s) recorded and verified.` : `${dates.length} day(s) recorded. They are charged only after they are verified.`);
      setDates([]);
      setNotes('');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not record the days. Nothing was saved.'), 'error'),
  });

  const verifyMutation = useMutation({
    mutationFn: (workdayId: number) => hrApi.temporaryReplacements.workdays.verify(replacement.id, workdayId),
    onSuccess: () => {
      refreshLedger();
      show('Day verified.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not verify the day.'), 'error'),
  });

  const voidMutation = useMutation({
    mutationFn: ({ workdayId, reason }: { workdayId: number; reason: string }) =>
      hrApi.temporaryReplacements.workdays.void(replacement.id, workdayId, reason),
    onSuccess: () => {
      refreshLedger();
      setVoiding(null);
      show('Day voided. It will not be charged.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not void the day.'), 'error'),
  });

  const approveMutation = useMutation({
    mutationFn: () => hrApi.temporaryReplacements.approve(replacement.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['temporary-replacements'] });
      refreshLedger();
      setIsApproveOpen(false);
      show('Replacement approved. Its cost will be deducted from the covered employee in payroll.');
    },
    onError: (err) => {
      setIsApproveOpen(false);
      show(friendlyErrorMessage(err, 'Could not approve the replacement.'), 'error');
    },
  });

  const knownDates = new Set((workdays ?? []).filter((day) => day.status !== 'void').map((day) => day.work_date));

  function addRange() {
    const expanded = expandDateRange(rangeFrom, rangeTo || rangeFrom);
    if (expanded.length === 0) {
      show('Choose a valid start day and, if needed, an end day (up to 62 days).', 'error');
      return;
    }
    const fresh = expanded.filter((date) => !knownDates.has(date) && !dates.includes(date));
    const skipped = expanded.length - fresh.length;
    setDates([...dates, ...fresh].sort());
    if (skipped > 0) show(`${skipped} day(s) were skipped because they are already in the list or already recorded.`);
  }

  const sortedWorkdays = [...(workdays ?? [])].sort((a, b) => b.work_date.localeCompare(a.work_date) || b.id - a.id);
  const coveredName = replacement.replaced_employee_name ?? 'the covered employee';

  return (
    <>
      <Modal
        isOpen
        onClose={onClose}
        title={`Workdays - ${replacement.replacement_employee_name ?? 'Temporary employee'}`}
        size="lg"
        footer={<Button variant="outline" size="sm" onClick={onClose}>Close</Button>}
      >
        <p className="mb-5 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
          Record the days the Temporary employee really worked. <strong>Only verified days are charged.</strong> Once the replacement is
          approved, the month&apos;s cost is deducted once from {coveredName}&apos;s pay.
        </p>

        <h3 className="mb-2 font-display text-sm font-bold text-ink">Days worked</h3>
        {isLoading && <p className="text-xs text-ink-faint">Loading days…</p>}
        {error && <p className="text-xs text-danger">{friendlyErrorMessage(error, 'Could not load the days.')}</p>}
        {workdays && sortedWorkdays.length === 0 && <p className="mb-4 text-xs italic text-ink-faint">No days recorded yet.</p>}
        {sortedWorkdays.length > 0 && (
          <div className="mb-5 space-y-1.5">
            {sortedWorkdays.map((day) => (
              <div key={day.id} className="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-border px-2.5 py-1.5 text-sm">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-ink">
                    {formatDate(day.work_date)}
                    <StatusBadge status={day.status} label={WORKDAY_STATUS[day.status].label} tone={WORKDAY_STATUS[day.status].tone} />
                  </p>
                  <p className="text-xs text-ink-faint">
                    {day.status === 'void'
                      ? `Voided${day.void_reason ? `: ${day.void_reason}` : ''}`
                      : day.status === 'verified'
                        ? `Verified${day.verified_by ? ` by ${day.verified_by}` : ''}`
                        : `Recorded${day.recorded_by ? ` by ${day.recorded_by}` : ''}`}
                    {day.notes && ` · ${day.notes}`}
                  </p>
                </div>
                <PermissionGate module="hr">
                  <div className="flex items-center gap-1">
                    {day.status === 'recorded' && (
                      <Button size="sm" variant="outline" isLoading={verifyMutation.isPending && verifyMutation.variables === day.id} onClick={() => verifyMutation.mutate(day.id)}>
                        Verify
                      </Button>
                    )}
                    {day.status !== 'void' && (
                      <Button size="sm" variant="ghost" onClick={() => setVoiding(day)}>
                        Void
                      </Button>
                    )}
                  </div>
                </PermissionGate>
              </div>
            ))}
          </div>
        )}

        <PermissionGate module="hr">
          <div className="mb-5 rounded-sm border border-border p-3">
            <h3 className="mb-2 font-display text-sm font-bold text-ink">Add days</h3>
            <div className="flex flex-wrap items-end gap-2">
              <div>
                <label htmlFor="wd-from" className="mb-1 block text-xs font-medium text-ink-muted">From</label>
                <input id="wd-from" type="date" min={replacement.start_date} max={latestDate} className={inputClasses} value={rangeFrom} onChange={(e) => setRangeFrom(e.target.value)} />
              </div>
              <div>
                <label htmlFor="wd-to" className="mb-1 block text-xs font-medium text-ink-muted">To (leave empty for one day)</label>
                <input id="wd-to" type="date" min={rangeFrom || replacement.start_date} max={latestDate} className={inputClasses} value={rangeTo} onChange={(e) => setRangeTo(e.target.value)} />
              </div>
              <Button size="sm" variant="outline" onClick={addRange}>
                <Icon name="plus" size={14} />
                Add to list
              </Button>
            </div>

            {dates.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-1.5">
                {dates.map((date) => (
                  <span key={date} className="inline-flex items-center gap-1 rounded-full bg-surface-alt px-2.5 py-1 text-xs text-ink">
                    {formatDate(date)}
                    <button
                      type="button"
                      aria-label={`Remove ${date}`}
                      onClick={() => setDates(dates.filter((d) => d !== date))}
                      className="text-ink-muted hover:text-danger"
                    >
                      <Icon name="x" size={12} />
                    </button>
                  </span>
                ))}
              </div>
            )}

            <div className="mt-3">
              <label htmlFor="wd-notes" className="mb-1 block text-xs font-medium text-ink-muted">Note (optional)</label>
              <input id="wd-notes" className={inputClasses} value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} />
            </div>
            <p className="mt-2 text-xs text-ink-faint">
              All days in the list are checked together: if one is not allowed, none of them is saved. Days that have not happened yet cannot be recorded.
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Button size="sm" variant="outline" disabled={dates.length === 0} isLoading={recordMutation.isPending && recordMutation.variables === false} onClick={() => recordMutation.mutate(false)}>
                Record days
              </Button>
              <Button size="sm" disabled={dates.length === 0} isLoading={recordMutation.isPending && recordMutation.variables === true} onClick={() => recordMutation.mutate(true)}>
                Record and verify
              </Button>
            </div>
          </div>
        </PermissionGate>

        {canViewPayroll && (
          <div className="rounded-sm border border-border p-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-display text-sm font-bold text-ink">Cost for a month</h3>
              <input type="month" aria-label="Month" className={`${inputClasses} h-8 w-40 text-xs`} value={period} onChange={(e) => setPeriod(e.target.value)} />
            </div>
            {isCostLoading && <p className="text-xs text-ink-faint">Calculating…</p>}
            {costError && <p className="text-xs text-danger">{friendlyErrorMessage(costError, 'Could not work out the cost.')}</p>}
            {cost && (
              <dl className="space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Verified days charged</dt>
                  <dd className="text-ink">{cost.verified_days}</dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-ink-muted">Daily rate (covered salary ÷ days in the month)</dt>
                  <dd className="text-right text-ink">{describeDailyRate(cost.daily_rate)}</dd>
                </div>
                <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                  <dt className="text-ink">Cost for this month</dt>
                  <dd className="text-ink">{formatCurrency(Number(cost.amount))}</dd>
                </div>
                {cost.charged_dates.length > 0 && (
                  <p className="text-xs text-ink-faint">Charged days: {cost.charged_dates.map((date) => formatDate(date)).join(', ')}</p>
                )}
                {cost.ignored.unverified > 0 && (
                  <p className="text-xs text-warning">{cost.ignored.unverified} day(s) are not verified yet, so they are not charged.</p>
                )}
                {cost.ignored.outside_window_or_month > 0 && (
                  <p className="text-xs text-ink-faint">{cost.ignored.outside_window_or_month} day(s) fall outside this month or the cover dates and are not counted.</p>
                )}
                {cost.ignored.duplicates > 0 && (
                  <p className="text-xs text-ink-faint">{cost.ignored.duplicates} duplicate day(s) are not counted twice.</p>
                )}
              </dl>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
              {replacement.approved ? (
                <StatusBadge status="approved" label={`Approved${replacement.approved_at ? ` on ${formatDate(replacement.approved_at)}` : ''}`} tone="success" />
              ) : (
                <>
                  <StatusBadge status="awaiting-approval" label="Not approved yet - nothing is deducted until it is approved" tone="neutral" />
                  {canApprove && (
                    <Button size="sm" onClick={() => setIsApproveOpen(true)}>
                      Approve cost
                    </Button>
                  )}
                </>
              )}
            </div>
          </div>
        )}
      </Modal>

      {voiding && (
        <VoidWorkdayModal
          workday={voiding}
          onClose={() => setVoiding(null)}
          onConfirm={(reason) => voidMutation.mutate({ workdayId: voiding.id, reason })}
          isLoading={voidMutation.isPending}
        />
      )}

      <ConfirmDialog
        isOpen={isApproveOpen}
        title="Approve this replacement?"
        description={`Once approved, the cost of the verified days is deducted once from ${coveredName}'s pay in the payroll run. Only verified days are counted. Check the days above before you continue.`}
        confirmLabel="Approve"
        isLoading={approveMutation.isPending}
        onConfirm={() => approveMutation.mutate()}
        onCancel={() => setIsApproveOpen(false)}
      />
    </>
  );
}

function VoidWorkdayModal({
  workday,
  onClose,
  onConfirm,
  isLoading,
}: {
  workday: TemporaryReplacementWorkday;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  isLoading: boolean;
}) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Void this day"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button variant="danger" size="sm" isLoading={isLoading} disabled={reason.trim().length < 5} onClick={() => onConfirm(reason.trim())}>
            Void day
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{formatDate(workday.work_date)} will no longer be charged. The day stays in the list for the record.</p>
      <FormField label="Reason" htmlFor="void-reason" required hint="At least 5 characters.">
        <textarea id="void-reason" rows={2} maxLength={500} className={inputClasses + ' h-auto py-2'} value={reason} onChange={(e) => setReason(e.target.value)} />
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
  const [amount, setAmount] = useState(replacement.daily_rate ?? '');
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
          <Button size="sm" isLoading={isLoading} disabled={amount === ''} onClick={() => onConfirm(paymentDate, Number(amount), notes)}>Record</Button>
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
