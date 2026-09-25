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
import { formatDate, formatDurationSince } from '@/utils/formatters';
import type { WorkforceRequest, WorkforceRequestStatus } from '@/types/employee';

const STATUS_TONE: Record<WorkforceRequestStatus, 'success' | 'warning' | 'danger' | 'neutral'> = {
  open: 'warning',
  partially_filled: 'warning',
  fulfilled: 'success',
  cancelled: 'danger',
};

const STATUS_LABEL: Record<WorkforceRequestStatus, string> = {
  open: 'Open',
  partially_filled: 'Partially Filled',
  fulfilled: 'Fulfilled',
  cancelled: 'Cancelled',
};

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 2: the demand side of staffing - a
 * client company (via a work location) asking for N workers with optional
 * criteria. Candidate recommendations and "HR confirmation" are advisory
 * (filter + sort by waiting_since, not a numeric score); confirming a match
 * never moves the employee off Waiting - that's Phase 3.
 */
export function WorkforceRequestsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [workLocationId, setWorkLocationId] = useState('');
  const [employeeCategoryId, setEmployeeCategoryId] = useState('');
  const [positionId, setPositionId] = useState('');
  const [genderRequirement, setGenderRequirement] = useState('');
  const [quantityNeeded, setQuantityNeeded] = useState('1');
  const [requestedDate, setRequestedDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');
  const [candidatesFor, setCandidatesFor] = useState<WorkforceRequest | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['workforce-requests'], queryFn: hrApi.workforceRequests.list });
  const { data: workLocations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });
  const { data: categories } = useQuery({ queryKey: ['employee-categories'], queryFn: hrApi.employeeCategories.list });
  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });

  const { data: candidates, isLoading: candidatesLoading } = useQuery({
    queryKey: ['workforce-requests', candidatesFor?.id, 'candidates'],
    queryFn: () => hrApi.workforceRequests.candidates(candidatesFor!.id),
    enabled: candidatesFor !== null,
  });

  const createMutation = useMutation({
    mutationFn: () =>
      hrApi.workforceRequests.create({
        work_location_id: Number(workLocationId),
        employee_category_id: employeeCategoryId ? Number(employeeCategoryId) : undefined,
        position_id: positionId ? Number(positionId) : undefined,
        gender_requirement: (genderRequirement || undefined) as 'male' | 'female' | undefined,
        quantity_needed: Number(quantityNeeded) || 1,
        requested_date: requestedDate,
        notes: notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['workforce-requests'] });
      show('Workforce request created.');
      setIsCreateOpen(false);
      setWorkLocationId('');
      setEmployeeCategoryId('');
      setPositionId('');
      setGenderRequirement('');
      setQuantityNeeded('1');
      setNotes('');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const cancelMutation = useMutation({
    mutationFn: (id: number) => hrApi.workforceRequests.cancel(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['workforce-requests'] });
      show('Workforce request cancelled.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not cancel request.', 'error'),
  });

  const confirmMutation = useMutation({
    mutationFn: ({ requestId, employeeId }: { requestId: number; employeeId: number }) =>
      hrApi.workforceRequests.confirm(requestId, { employee_id: employeeId }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['workforce-requests'] });
      queryClient.invalidateQueries({ queryKey: ['workforce-requests', updated.id, 'candidates'] });
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      setCandidatesFor(updated);
      show('Match confirmed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not confirm match.', 'error'),
  });

  return (
    <div>
      <PageHeader
        title="Workforce Requests"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Workforce Requests' }]}
        actions={
          <PermissionGate module="hr">
            <Button onClick={() => setIsCreateOpen(true)}>
              <Icon name="plus" size={15} />
              New Workforce Request
            </Button>
          </PermissionGate>
        }
      />

      {isLoading && <LoadingState label="Loading workforce requests…" />}
      {error && <ErrorState error={error} onRetry={refetch} />}
      {data && data.length === 0 && <EmptyState icon="briefcase" title="No workforce requests yet" />}

      {data && data.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {data.map((request) => (
            <Card key={request.id} className="p-5">
              <div className="mb-3 flex items-start justify-between">
                <div>
                  <h3 className="font-display text-base font-bold text-ink">{request.work_location_name ?? 'Unknown location'}</h3>
                  <p className="text-xs text-ink-muted">
                    {request.client_company_name && `${request.client_company_name} · `}
                    {formatDate(request.requested_date)}
                  </p>
                </div>
                <StatusBadge status={request.status} label={STATUS_LABEL[request.status]} tone={STATUS_TONE[request.status]} />
              </div>

              <div className="mb-3 flex flex-wrap gap-1.5 text-xs text-ink-muted">
                <span className="rounded-full bg-surface-alt px-2.5 py-1">{request.employee_category_name ?? 'Any category'}</span>
                <span className="rounded-full bg-surface-alt px-2.5 py-1">{request.position_name ?? 'Any position'}</span>
                <span className="rounded-full bg-surface-alt px-2.5 py-1 capitalize">{request.gender_requirement ?? 'Any gender'}</span>
                <span className="rounded-full bg-surface-alt px-2.5 py-1">
                  {request.matched_count} / {request.quantity_needed} matched
                </span>
              </div>

              {request.notes && <p className="mb-3 text-xs italic text-ink-faint">{request.notes}</p>}

              <div className="flex items-center gap-2 border-t border-border pt-3">
                <Button size="sm" variant="outline" onClick={() => setCandidatesFor(request)}>
                  View Candidates
                </Button>
                {(request.status === 'open' || request.status === 'partially_filled') && (
                  <PermissionGate module="hr">
                    <Button size="sm" variant="ghost" onClick={() => cancelMutation.mutate(request.id)} isLoading={cancelMutation.isPending}>
                      Cancel Request
                    </Button>
                  </PermissionGate>
                )}
              </div>

              {request.matches.length > 0 && (
                <div className="mt-3 space-y-1.5 border-t border-border pt-3">
                  <p className="mb-1.5 text-xs font-medium text-ink-faint">Confirmed matches</p>
                  {request.matches.map((match) => (
                    <button
                      key={match.id}
                      type="button"
                      onClick={() => navigate(`/hr/employees/${match.employee_id}`)}
                      className="flex w-full items-center justify-between rounded-sm border border-border px-2.5 py-1.5 text-left text-sm hover:bg-surface-alt"
                    >
                      <span className="text-ink">{match.employee_name}</span>
                      <span className="text-xs text-ink-faint">{formatDate(match.created_at)}</span>
                    </button>
                  ))}
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        title="New Workforce Request"
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsCreateOpen(false)}>Cancel</Button>
            <Button size="sm" isLoading={createMutation.isPending} disabled={!workLocationId} onClick={() => createMutation.mutate()}>
              Create
            </Button>
          </>
        }
      >
        <FormField label="Work location" htmlFor="wr-location" required>
          <Select
            id="wr-location"
            value={workLocationId}
            onChange={(e) => setWorkLocationId(e.target.value)}
            placeholder="Select a location…"
            options={(workLocations ?? []).map((l) => ({ value: String(l.id), label: l.client_company_name ? `${l.client_company_name} · ${l.name}` : l.name }))}
          />
        </FormField>
        <FormField label="Category (optional)" htmlFor="wr-category">
          <Select
            id="wr-category"
            value={employeeCategoryId}
            onChange={(e) => setEmployeeCategoryId(e.target.value)}
            placeholder="Any category"
            options={(categories ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
          />
        </FormField>
        <FormField label="Position (optional)" htmlFor="wr-position">
          <Select
            id="wr-position"
            value={positionId}
            onChange={(e) => setPositionId(e.target.value)}
            placeholder="Any position"
            options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
          />
        </FormField>
        <FormField label="Gender requirement (optional)" htmlFor="wr-gender">
          <Select
            id="wr-gender"
            value={genderRequirement}
            onChange={(e) => setGenderRequirement(e.target.value)}
            placeholder="Any gender"
            options={[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }]}
          />
        </FormField>
        <FormField label="Quantity needed" htmlFor="wr-quantity" required>
          <input id="wr-quantity" type="number" min={1} className={inputClasses} value={quantityNeeded} onChange={(e) => setQuantityNeeded(e.target.value)} />
        </FormField>
        <FormField label="Requested date" htmlFor="wr-date" required>
          <input id="wr-date" type="date" className={inputClasses} value={requestedDate} onChange={(e) => setRequestedDate(e.target.value)} />
        </FormField>
        <FormField label="Notes" htmlFor="wr-notes">
          <textarea id="wr-notes" className={inputClasses} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </Modal>

      <Modal
        isOpen={candidatesFor !== null}
        onClose={() => setCandidatesFor(null)}
        title={candidatesFor ? `Candidates for ${candidatesFor.work_location_name}` : 'Candidates'}
        size="lg"
      >
        {candidatesLoading && <LoadingState label="Loading candidates…" />}
        {candidates && candidates.length === 0 && <EmptyState icon="users" title="No one is currently waiting" />}
        {candidates && candidates.length > 0 && candidatesFor && (
          <div className="space-y-1.5">
            {candidates.map((candidate) => (
              <div key={candidate.id} className="flex items-center justify-between rounded-sm border border-border px-3 py-2 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium text-ink">{candidate.full_name}</p>
                  <p className="truncate text-xs text-ink-muted">
                    {candidate.employee_category_name ?? '—'} · {candidate.location ?? 'No location on file'} · Waiting {formatDurationSince(candidate.waiting_since)}
                  </p>
                  <div className="mt-1 flex gap-1">
                    <StatusBadge status="gender" label={candidate.gender ?? '—'} tone={candidate.gender_match ? 'success' : 'neutral'} />
                    <StatusBadge status="category" label={candidate.employee_category_name ?? 'Any'} tone={candidate.category_match ? 'success' : 'neutral'} />
                    <StatusBadge status="position" label={candidate.position_name ?? 'Any'} tone={candidate.position_match ? 'success' : 'neutral'} />
                  </div>
                </div>
                <PermissionGate module="hr">
                  <Button
                    size="sm"
                    className="h-8 shrink-0 px-3 text-xs"
                    isLoading={confirmMutation.isPending}
                    disabled={candidatesFor.status === 'fulfilled' || candidatesFor.status === 'cancelled'}
                    onClick={() => confirmMutation.mutate({ requestId: candidatesFor.id, employeeId: candidate.id })}
                  >
                    Confirm
                  </Button>
                </PermissionGate>
              </div>
            ))}
          </div>
        )}
      </Modal>
    </div>
  );
}
