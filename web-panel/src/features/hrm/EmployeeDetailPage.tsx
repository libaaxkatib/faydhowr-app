import { useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { EmployeeFormDialog } from '@/features/hrm/EmployeeFormDialog';
import { formatDate, formatDateTime, initialsOf } from '@/utils/formatters';
import type { EmployeeStatus, SalaryFrequency, WorkAssignment } from '@/types/employee';

const STATUS_FLOW: EmployeeStatus[] = ['applicant', 'recruitment', 'practical', 'waiting', 'approved', 'active'];

export function EmployeeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const employeeId = Number(id);
  const queryClient = useQueryClient();
  const { show } = useToast();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [assessmentResult, setAssessmentResult] = useState<'pass' | 'fail' | 'pending'>('pending');
  const [assessmentNotes, setAssessmentNotes] = useState('');
  const [isAssignOpen, setIsAssignOpen] = useState(false);
  const [endingAssignment, setEndingAssignment] = useState<WorkAssignment | null>(null);

  const { data: employee, isLoading, error, refetch } = useQuery({
    queryKey: ['employee', employeeId],
    queryFn: () => hrApi.employees.get(employeeId),
    enabled: Number.isFinite(employeeId),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['employee', employeeId] });
    queryClient.invalidateQueries({ queryKey: ['employees'] });
  };

  const statusMutation = useMutation({
    mutationFn: (status: EmployeeStatus) => hrApi.employees.updateStatus(employeeId, status),
    onSuccess: () => {
      invalidate();
      show('Status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update status.', 'error'),
  });

  const guarantorMutation = useMutation({
    mutationFn: () => hrApi.employees.confirmGuarantor(employeeId),
    onSuccess: () => {
      invalidate();
      show('Guarantor confirmed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not confirm guarantor.', 'error'),
  });

  const assessmentMutation = useMutation({
    mutationFn: () =>
      hrApi.employees.addPracticalAssessment(employeeId, {
        assessment_date: new Date().toISOString().slice(0, 10),
        result: assessmentResult,
        notes: assessmentNotes || null,
      }),
    onSuccess: () => {
      invalidate();
      setAssessmentNotes('');
      show('Practical assessment recorded.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record assessment.', 'error'),
  });

  const uploadMutation = useMutation({
    mutationFn: (file: File) => hrApi.employees.uploadDocument(employeeId, file),
    onSuccess: () => {
      invalidate();
      show('Document uploaded.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not upload document.', 'error'),
  });

  const deleteDocumentMutation = useMutation({
    mutationFn: (documentId: number) => hrApi.employees.deleteDocument(employeeId, documentId),
    onSuccess: () => {
      invalidate();
      show('Document deleted.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete document.', 'error'),
  });

  const endAssignmentMutation = useMutation({
    mutationFn: (payload: { id: number; end_date: string; note?: string | null }) =>
      hrApi.employees.workAssignments.end(payload.id, { end_date: payload.end_date, note: payload.note }),
    onSuccess: () => {
      invalidate();
      setEndingAssignment(null);
      show('Work assignment ended.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not end work assignment.', 'error'),
  });

  if (isLoading) return <LoadingState label="Loading employee…" />;
  if (error || !employee) return <ErrorState error={error} onRetry={refetch} />;

  const nextStatus = STATUS_FLOW[STATUS_FLOW.indexOf(employee.status) + 1];

  return (
    <div>
      <PageHeader
        title={employee.full_name}
        breadcrumb={[
          { label: 'Human Resources', to: '/hr' },
          { label: 'Employees', to: '/hr/employees' },
          { label: employee.employee_number },
        ]}
        actions={
          <PermissionGate module="hr">
            <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
              <Icon name="pencil" size={14} />
              Edit
            </Button>
          </PermissionGate>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Profile</h3>
            <StatusBadge status={employee.status} />
          </CardHeader>
          <CardBody>
            <div className="mb-5 flex items-center gap-4">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">
                {initialsOf(employee.full_name)}
              </div>
              <div>
                <p className="font-display text-base font-bold text-ink">{employee.full_name}</p>
                <p className="text-sm text-ink-muted">{employee.employee_number}</p>
              </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              <Field label="Phone" value={employee.phone} />
              <Field label="Alternate contact" value={employee.alternate_phone ?? '—'} />
              <Field label="Location" value={employee.location ?? '—'} />
              <Field label="Age" value={employee.age?.toString() ?? '—'} />
              <Field label="Marital status" value={employee.marital_status ?? '—'} />
              <Field label="Lives with" value={employee.lives_with ?? '—'} />
              <Field label="Reference / guarantor" value={employee.reference_name ?? '—'} />
              <Field
                label="Guarantor confirmed"
                value={employee.guarantor_confirmed_at ? formatDateTime(employee.guarantor_confirmed_at) : 'Not yet'}
              />
              <Field label="Category" value={employee.employee_category_name ?? '—'} />
              <Field label="Department" value={employee.department_name ?? '—'} />
              <Field label="Position" value={employee.position_name ?? '—'} />
              <Field label="Application date" value={formatDate(employee.application_date)} />
              <Field label="Source" value={employee.source ?? '—'} />
            </dl>

            {employee.experience && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">Experience</p>
                <p className="text-sm text-ink">{employee.experience}</p>
              </div>
            )}
            {employee.notes && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">Notes</p>
                <p className="text-sm text-ink">{employee.notes}</p>
              </div>
            )}

            <PermissionGate module="hr">
              <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-border pt-5">
                {nextStatus && (
                  <Button size="sm" isLoading={statusMutation.isPending} onClick={() => statusMutation.mutate(nextStatus)}>
                    Advance to {nextStatus[0].toUpperCase() + nextStatus.slice(1)}
                  </Button>
                )}
                {employee.status !== 'inactive' && (
                  <Button variant="danger" size="sm" isLoading={statusMutation.isPending} onClick={() => statusMutation.mutate('inactive')}>
                    Mark Inactive
                  </Button>
                )}
                {!employee.guarantor_confirmed_at && (
                  <Button variant="outline" size="sm" isLoading={guarantorMutation.isPending} onClick={() => guarantorMutation.mutate()}>
                    Confirm Guarantor
                  </Button>
                )}
              </div>
            </PermissionGate>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Work Assignments</h3>
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsAssignOpen(true)}>
                  <Icon name="plus" size={14} />
                  Assign
                </Button>
              </PermissionGate>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.active_work_assignments && employee.active_work_assignments.length > 0 ? (
                employee.active_work_assignments.map((assignment) => (
                  <div key={assignment.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between">
                      <p className="text-sm font-medium text-ink">
                        {assignment.location_type === 'office' ? 'Fayadhowr Office' : assignment.client_company_name}
                        {assignment.location_type === 'client' && ` · ${assignment.work_location_name}`}
                      </p>
                      <StatusBadge status={assignment.status} tone="success" />
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      {assignment.position_name ?? 'No position set'} · {assignment.salary_amount} {assignment.salary_currency} / {assignment.salary_frequency}
                    </p>
                    <p className="mt-1 text-xs text-ink-faint">Since {formatDate(assignment.start_date)}</p>
                    <PermissionGate module="hr">
                      <button
                        type="button"
                        onClick={() => setEndingAssignment(assignment)}
                        className="mt-1 text-xs font-medium text-danger hover:underline"
                      >
                        End assignment
                      </button>
                    </PermissionGate>
                  </div>
                ))
              ) : (
                <EmptyState icon="briefcase" title="No active work assignment" />
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Assignment History</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.work_assignments && employee.work_assignments.filter((a) => a.status !== 'active').length > 0 ? (
                employee.work_assignments
                  .filter((a) => a.status !== 'active')
                  .map((assignment) => (
                    <div key={assignment.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                      <p className="text-sm text-ink">
                        {assignment.location_type === 'office' ? 'Fayadhowr Office' : `${assignment.client_company_name} · ${assignment.work_location_name}`}
                      </p>
                      <p className="mt-1 text-xs text-ink-faint">
                        {formatDate(assignment.start_date)} → {assignment.end_date ? formatDate(assignment.end_date) : 'Present'}
                      </p>
                    </div>
                  ))
              ) : (
                <EmptyState icon="list" title="No past assignments" />
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Status History</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.status_histories && employee.status_histories.length > 0 ? (
                employee.status_histories.map((entry) => (
                  <div key={entry.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center gap-2">
                      <StatusBadge status={entry.to_status} />
                      <span className="text-xs text-ink-faint">{formatDateTime(entry.created_at)}</span>
                    </div>
                    {entry.note && <p className="mt-1 text-xs text-ink-muted">{entry.note}</p>}
                    <p className="mt-1 text-xs text-ink-faint">by {entry.changed_by ?? '—'}</p>
                  </div>
                ))
              ) : (
                <EmptyState icon="list" title="No history yet" />
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Practical Assessments</h3>
          </CardHeader>
          <CardBody>
            {employee.practical_assessments && employee.practical_assessments.length > 0 ? (
              <div className="mb-4 space-y-3">
                {employee.practical_assessments.map((a) => (
                  <div key={a.id} className="flex items-start justify-between border-b border-border pb-3 last:border-0">
                    <div>
                      <StatusBadge status={a.result} tone={a.result === 'pass' ? 'success' : a.result === 'fail' ? 'danger' : 'warning'} />
                      <p className="mt-1 text-xs text-ink-muted">{a.notes}</p>
                    </div>
                    <span className="text-xs text-ink-faint">{formatDate(a.assessment_date)}</span>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="check" title="No assessments recorded" />
            )}
            <PermissionGate module="hr">
              <div className="flex items-center gap-2 border-t border-border pt-4">
                <select
                  value={assessmentResult}
                  onChange={(e) => setAssessmentResult(e.target.value as typeof assessmentResult)}
                  className="h-9 rounded-sm border border-border bg-surface px-2 text-sm"
                >
                  <option value="pending">Pending</option>
                  <option value="pass">Pass</option>
                  <option value="fail">Fail</option>
                </select>
                <input
                  type="text"
                  placeholder="Notes (optional)"
                  value={assessmentNotes}
                  onChange={(e) => setAssessmentNotes(e.target.value)}
                  className="h-9 flex-1 rounded-sm border border-border bg-surface px-2 text-sm"
                />
                <Button size="sm" isLoading={assessmentMutation.isPending} onClick={() => assessmentMutation.mutate()}>
                  Add
                </Button>
              </div>
            </PermissionGate>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Documents</h3>
            <PermissionGate module="hr">
              <Button size="sm" variant="outline" onClick={() => fileInputRef.current?.click()} isLoading={uploadMutation.isPending}>
                <Icon name="plus" size={14} />
                Upload
              </Button>
              <input
                ref={fileInputRef}
                type="file"
                hidden
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) uploadMutation.mutate(file);
                  e.target.value = '';
                }}
              />
            </PermissionGate>
          </CardHeader>
          <CardBody>
            {employee.documents && employee.documents.length > 0 ? (
              <div className="space-y-2">
                {employee.documents.map((doc) => (
                  <div key={doc.id} className="flex items-center justify-between rounded-sm border border-border px-3 py-2">
                    <div className="flex items-center gap-2 text-sm text-ink">
                      <Icon name="file-text" size={15} className="text-ink-faint" />
                      {doc.file_name}
                    </div>
                    <PermissionGate module="hr">
                      <button
                        type="button"
                        onClick={() => deleteDocumentMutation.mutate(doc.id)}
                        className="text-ink-faint hover:text-danger"
                      >
                        <Icon name="trash" size={14} />
                      </button>
                    </PermissionGate>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="file-text" title="No documents uploaded" />
            )}
          </CardBody>
        </Card>
      </div>

      <EmployeeFormDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} mode="edit" employee={employee} />

      <AssignWorkLocationModal
        isOpen={isAssignOpen}
        employeeId={employeeId}
        onClose={() => setIsAssignOpen(false)}
        onSaved={() => {
          invalidate();
          setIsAssignOpen(false);
        }}
      />

      {endingAssignment && (
        <EndAssignmentModal
          assignment={endingAssignment}
          onClose={() => setEndingAssignment(null)}
          onConfirm={(endDate, note) => endAssignmentMutation.mutate({ id: endingAssignment.id, end_date: endDate, note })}
          isLoading={endAssignmentMutation.isPending}
        />
      )}
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

const FREQUENCY_OPTIONS: { value: SalaryFrequency; label: string }[] = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'daily', label: 'Daily' },
];

function AssignWorkLocationModal({
  isOpen,
  employeeId,
  onClose,
  onSaved,
}: {
  isOpen: boolean;
  employeeId: number;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { show } = useToast();
  const { isSuperAdmin } = useAuth();
  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list(), enabled: isOpen });

  const [workLocationId, setWorkLocationId] = useState('');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [salaryAmount, setSalaryAmount] = useState('');
  const [salaryCurrency, setSalaryCurrency] = useState('USD');
  const [salaryFrequency, setSalaryFrequency] = useState<SalaryFrequency>('monthly');
  const [notes, setNotes] = useState('');
  const [overrideCapacity, setOverrideCapacity] = useState(false);

  const assignMutation = useMutation({
    mutationFn: () =>
      hrApi.employees.workAssignments.create(employeeId, {
        work_location_id: Number(workLocationId),
        start_date: startDate,
        salary_amount: Number(salaryAmount),
        salary_currency: salaryCurrency,
        salary_frequency: salaryFrequency,
        notes: notes || null,
        ...(isSuperAdmin && overrideCapacity ? { override_capacity: true } : {}),
      }),
    onSuccess: () => {
      show('Work assignment created.');
      onSaved();
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not create work assignment.', 'error'),
  });

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Assign Work Location"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={assignMutation.isPending} onClick={() => assignMutation.mutate()}>Save</Button>
        </>
      }
    >
      <FormField label="Work location" htmlFor="wa-location" required>
        <Select
          id="wa-location"
          value={workLocationId}
          onChange={(e) => setWorkLocationId(e.target.value)}
          placeholder="Select a location"
          options={(locations ?? []).map((l) => ({
            value: String(l.id),
            label: l.location_type === 'office' ? l.name : `${l.client_company_name} · ${l.name}`,
          }))}
        />
      </FormField>
      <FormField label="Start date" htmlFor="wa-start" required>
        <input id="wa-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-3 gap-2">
        <FormField label="Salary" htmlFor="wa-salary" required>
          <input id="wa-salary" type="number" min={0} className={inputClasses} value={salaryAmount} onChange={(e) => setSalaryAmount(e.target.value)} />
        </FormField>
        <FormField label="Currency" htmlFor="wa-currency" required>
          <input id="wa-currency" maxLength={3} className={inputClasses} value={salaryCurrency} onChange={(e) => setSalaryCurrency(e.target.value.toUpperCase())} />
        </FormField>
        <FormField label="Frequency" htmlFor="wa-frequency" required>
          <Select id="wa-frequency" value={salaryFrequency} onChange={(e) => setSalaryFrequency(e.target.value as SalaryFrequency)} options={FREQUENCY_OPTIONS} />
        </FormField>
      </div>
      <FormField label="Notes" htmlFor="wa-notes">
        <textarea id="wa-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      {isSuperAdmin && (
        <label className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={overrideCapacity} onChange={(e) => setOverrideCapacity(e.target.checked)} />
          Override location capacity if full (Super Admin only)
        </label>
      )}
    </Modal>
  );
}

function EndAssignmentModal({
  assignment,
  onClose,
  onConfirm,
  isLoading,
}: {
  assignment: WorkAssignment;
  onClose: () => void;
  onConfirm: (endDate: string, note?: string) => void;
  isLoading: boolean;
}) {
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [note, setNote] = useState('');

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="End Work Assignment"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button variant="danger" size="sm" isLoading={isLoading} onClick={() => onConfirm(endDate, note || undefined)}>End Assignment</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">
        {assignment.location_type === 'office' ? 'Fayadhowr Office' : `${assignment.client_company_name} · ${assignment.work_location_name}`}
      </p>
      <FormField label="End date" htmlFor="end-date" required>
        <input id="end-date" type="date" className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </FormField>
      <FormField label="Note" htmlFor="end-note">
        <textarea id="end-note" rows={2} className={inputClasses + ' h-auto py-2'} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}
