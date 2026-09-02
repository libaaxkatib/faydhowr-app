import { useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { EmployeeFormDialog } from '@/features/hrm/EmployeeFormDialog';
import { formatDate, formatDateTime, initialsOf } from '@/utils/formatters';
import type { EmployeeStatus } from '@/types/employee';

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
