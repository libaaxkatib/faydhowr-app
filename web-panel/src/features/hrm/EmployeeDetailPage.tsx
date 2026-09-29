import { useRef, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
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
import type { AttendanceStatus, Employee, EmployeeSeparationReason, EmployeeStatus, LeaveType, PerformanceRating, PracticalDecision, SalaryFrequency, WorkAssignment } from '@/types/employee';

const SEPARATION_REASON_OPTIONS: { value: EmployeeSeparationReason; label: string }[] = [
  { value: 'resigned', label: 'Resigned' },
  { value: 'terminated', label: 'Terminated' },
  { value: 'contract_ended', label: 'Contract Ended' },
  { value: 'other', label: 'Other' },
];

const LEAVE_TYPE_OPTIONS: { value: LeaveType; label: string }[] = [
  { value: 'annual', label: 'Annual' },
  { value: 'sick', label: 'Sick' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'other', label: 'Other' },
];

const PERFORMANCE_RATING_OPTIONS: { value: PerformanceRating; label: string }[] = [
  { value: 'excellent', label: 'Excellent' },
  { value: 'good', label: 'Good' },
  { value: 'needs_improvement', label: 'Needs Improvement' },
  { value: 'poor', label: 'Poor' },
];

/**
 * Manual "Advance to {next}" progression. Waiting goes straight to Active -
 * the authoritative HR workflow does not route a Waiting employee through a
 * separate Approved status (Approved here refers to the Practical Assessment
 * decision, which already moves status straight to Waiting - see
 * RecordPracticalDecisionAction - not a distinct post-Waiting stage).
 */
const STATUS_FLOW: EmployeeStatus[] = ['applicant', 'recruitment', 'practical', 'waiting', 'active'];

const ATTENDANCE_STATUS_TONE: Record<AttendanceStatus, 'success' | 'warning' | 'danger'> = {
  present: 'success',
  late: 'warning',
  absent: 'danger',
};

const ATTENDANCE_STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: 'Present',
  late: 'Late',
  absent: 'Absent',
};

const PIPELINE_STAGE_LABELS: Record<string, string> = {
  damiin_needed: 'Damiin Needed',
  contract_pending: 'Contract Pending',
  uniform_pending: 'Uniform Pending',
  need_training: 'Need Training',
  need_practical: 'Need Practical',
  practical_repeat: 'Practical Repeat',
  rejected: 'Rejected',
};

/**
 * Presentation-only (per approved Phase 1 Decision 1) - never writes back to
 * `employee.profile_complete`, never fabricates a value for a missing field.
 * Deliberately excludes phone/application_date from being treated as a data
 * error (both are legitimately null for many migrated employees) while still
 * listing them here, since "on record" vs. "missing" is still worth showing -
 * their absence just never blocks anything elsewhere in the app.
 */
const PROFILE_COMPLETENESS_FIELDS: { label: string; isPresent: (e: Employee) => boolean }[] = [
  { label: 'Full Name', isPresent: (e) => Boolean(e.full_name) },
  { label: 'Phone', isPresent: (e) => Boolean(e.phone) },
  { label: 'Gender', isPresent: (e) => Boolean(e.gender) },
  { label: 'Age', isPresent: (e) => e.age !== null },
  { label: 'Marital Status', isPresent: (e) => Boolean(e.marital_status) },
  { label: 'Location', isPresent: (e) => Boolean(e.location) },
  { label: 'Employee Category', isPresent: (e) => Boolean(e.employee_category_id) },
  { label: 'Department', isPresent: (e) => Boolean(e.department_id) },
  { label: 'Reference Name', isPresent: (e) => Boolean(e.reference_name) },
  { label: 'Application Date', isPresent: (e) => Boolean(e.application_date) },
  { label: 'Joining Date', isPresent: (e) => Boolean(e.joining_date) },
  { label: 'Experience', isPresent: (e) => Boolean(e.experience) },
];

const DECISION_OPTIONS: { value: PracticalDecision; label: string; tone: 'success' | 'danger' | 'warning' }[] = [
  { value: 'approved', label: 'Approved', tone: 'success' },
  { value: 'ku_celis_practical', label: 'Ku Celis Practical', tone: 'warning' },
  { value: 'rejected', label: 'Rejected', tone: 'danger' },
];

export function EmployeeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const employeeId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  // Issue #10: return to wherever the HR user actually opened this record from
  // (Need Training, Damiin, Waiting, etc.) instead of always the generic
  // Employees list. Falls back to the generic list for a direct/deep link.
  const origin = location.state as { fromPath?: string; fromLabel?: string } | null;
  const fromPath = origin?.fromPath ?? '/hr/employees';
  const fromLabel = origin?.fromLabel ?? 'Employees';
  const queryClient = useQueryClient();
  const { show } = useToast();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [isEditOpen, setIsEditOpen] = useState(false);
  const [decisionResult, setDecisionResult] = useState<PracticalDecision>('approved');
  const [assessmentNotes, setAssessmentNotes] = useState('');
  const [isAssignOpen, setIsAssignOpen] = useState(false);
  const [endingAssignment, setEndingAssignment] = useState<WorkAssignment | null>(null);
  const [isSeparateOpen, setIsSeparateOpen] = useState(false);
  const [isRehireOpen, setIsRehireOpen] = useState(false);
  const [isLeaveOpen, setIsLeaveOpen] = useState(false);
  const [isReviewOpen, setIsReviewOpen] = useState(false);
  const [isPaymentOpen, setIsPaymentOpen] = useState(false);
  const [isPenaltyOpen, setIsPenaltyOpen] = useState(false);
  const [isAdvanceOpen, setIsAdvanceOpen] = useState(false);
  const pictureInputRef = useRef<HTMLInputElement>(null);

  const [guarantorForm, setGuarantorForm] = useState({ guarantor_name: '', guarantor_phone: '', relationship: '' });
  const [signedDate, setSignedDate] = useState(new Date().toISOString().slice(0, 10));
  const [documentCategoryId, setDocumentCategoryId] = useState('');
  const [practicalBatchId, setPracticalBatchId] = useState('');

  const { data: employee, isLoading, error, refetch } = useQuery({
    queryKey: ['employee', employeeId],
    queryFn: () => hrApi.employees.get(employeeId),
    enabled: Number.isFinite(employeeId),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['employee', employeeId] });
    queryClient.invalidateQueries({ queryKey: ['employees'] });
    queryClient.invalidateQueries({ queryKey: ['payroll-summary', employeeId] });
  };

  const statusMutation = useMutation({
    mutationFn: (status: EmployeeStatus) => hrApi.employees.updateStatus(employeeId, status),
    onSuccess: () => {
      invalidate();
      show('Status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update status.', 'error'),
  });

  const separateMutation = useMutation({
    mutationFn: (payload: { reason: EmployeeSeparationReason; separation_date: string; rehire_eligible: boolean; notes: string | null }) =>
      hrApi.employees.separate(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Employee separated.');
      setIsSeparateOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not separate employee.', 'error'),
  });

  const rehireMutation = useMutation({
    mutationFn: (note: string) => hrApi.employees.rehire(employeeId, note || undefined),
    onSuccess: () => {
      invalidate();
      show('Employee rehired.');
      setIsRehireOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not rehire employee.', 'error'),
  });

  const supervisorMutation = useMutation({
    mutationFn: (isSupervisor: boolean) => hrApi.employees.toggleSupervisor(employeeId, isSupervisor),
    onSuccess: (_data, isSupervisor) => {
      invalidate();
      show(isSupervisor ? 'Added to the Supervisor Pool.' : 'Removed from the Supervisor Pool.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update supervisor status.', 'error'),
  });

  const leaveMutation = useMutation({
    mutationFn: (payload: { leave_type: LeaveType; start_date: string; end_date: string; notes: string | null }) =>
      hrApi.employees.addLeave(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Leave recorded.');
      setIsLeaveOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record leave.', 'error'),
  });

  const reviewMutation = useMutation({
    mutationFn: (payload: { review_date: string; rating: PerformanceRating; notes: string | null }) =>
      hrApi.employees.addPerformanceReview(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Performance review recorded.');
      setIsReviewOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record performance review.', 'error'),
  });

  const paymentMutation = useMutation({
    mutationFn: (payload: { payment_date: string; amount: number; currency: string; notes: string | null }) =>
      hrApi.employees.addPayment(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Payment recorded.');
      setIsPaymentOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record payment.', 'error'),
  });

  const penaltyMutation = useMutation({
    mutationFn: (payload: { penalty_date: string; reason: string; deduction_amount: number; currency: string; payroll_period: string; notes: string | null }) =>
      hrApi.employees.addPenalty(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Penalty recorded.');
      setIsPenaltyOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record penalty.', 'error'),
  });

  const advanceMutation = useMutation({
    mutationFn: (payload: { advance_date: string; amount: number; currency: string; payroll_period: string; reason: string; notes: string | null }) =>
      hrApi.employees.addAdvance(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show('Salary advance recorded.');
      setIsAdvanceOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record salary advance.', 'error'),
  });

  const { data: documentCategories } = useQuery({ queryKey: ['employee-document-categories'], queryFn: hrApi.documentCategories.list });
  const { data: practicalBatches } = useQuery({ queryKey: ['practical-batches'], queryFn: hrApi.practicalBatches.list });

  const guarantorSaveMutation = useMutation({
    mutationFn: () => hrApi.employees.guarantor.save(employeeId, guarantorForm),
    onSuccess: () => {
      invalidate();
      show('Guarantor information saved.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not save guarantor information.', 'error'),
  });

  const guarantorVerifyMutation = useMutation({
    mutationFn: () => hrApi.employees.guarantor.verify(employeeId),
    onSuccess: () => {
      invalidate();
      show('Guarantor verified — advanced to Contract Pending.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not verify guarantor.', 'error'),
  });

  const contractCreateMutation = useMutation({
    mutationFn: () => hrApi.employees.contracts.create(employeeId, {}),
    onSuccess: () => {
      invalidate();
      show('Contract issued.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not issue contract.', 'error'),
  });

  const contractSignMutation = useMutation({
    mutationFn: (contractId: number) => hrApi.employees.contracts.sign(employeeId, contractId, { signed_date: signedDate }),
    onSuccess: () => {
      invalidate();
      show('Contract marked signed — advanced to Uniform Pending.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not mark contract signed.', 'error'),
  });

  const uniformUpdateMutation = useMutation({
    mutationFn: (status: string) => hrApi.employees.uniform.update(employeeId, { status }),
    onSuccess: () => {
      invalidate();
      show('Uniform status updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not update uniform status.', 'error'),
  });

  const uniformConfirmMutation = useMutation({
    mutationFn: () => hrApi.employees.uniform.confirm(employeeId),
    onSuccess: () => {
      invalidate();
      show('Uniform confirmed — advanced to Need Training.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not confirm uniform.', 'error'),
  });

  const decisionMutation = useMutation({
    mutationFn: () =>
      hrApi.employees.addPracticalAssessment(employeeId, {
        assessment_date: new Date().toISOString().slice(0, 10),
        result: decisionResult,
        practical_batch_id: practicalBatchId ? Number(practicalBatchId) : null,
        notes: assessmentNotes || null,
      }),
    onSuccess: () => {
      invalidate();
      setAssessmentNotes('');
      show('Practical decision recorded.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not record decision.', 'error'),
  });

  const uploadMutation = useMutation({
    mutationFn: (file: File) =>
      hrApi.employees.uploadDocument(employeeId, file, {
        employee_document_category_id: documentCategoryId ? Number(documentCategoryId) : null,
      }),
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
      show('Document removed.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not remove document.', 'error'),
  });

  const verifyDocumentMutation = useMutation({
    mutationFn: (documentId: number) => hrApi.employees.verifyDocument(employeeId, documentId),
    onSuccess: () => {
      invalidate();
      show('Document verified.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not verify document.', 'error'),
  });

  const profilePictureMutation = useMutation({
    mutationFn: (file: File) => hrApi.employees.uploadProfilePicture(employeeId, file),
    onSuccess: () => {
      invalidate();
      show('Profile picture updated.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not upload profile picture.', 'error'),
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

  const statusFlowIndex = STATUS_FLOW.indexOf(employee.status);
  const nextStatus = statusFlowIndex === -1 ? undefined : STATUS_FLOW[statusFlowIndex + 1];
  const missingProfileFields = PROFILE_COMPLETENESS_FIELDS.filter((field) => !field.isPresent(employee)).map((field) => field.label);

  return (
    <div>
      <PageHeader
        title={employee.full_name}
        breadcrumb={[
          { label: 'Human Resources', to: '/hr' },
          { label: fromLabel, to: fromPath },
          { label: employee.employee_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => navigate(fromPath)}>
              <Icon name="chevron-left" size={14} />
              Back to {fromLabel}
            </Button>
            <PermissionGate module="hr">
              <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                <Icon name="pencil" size={14} />
                Edit
              </Button>
            </PermissionGate>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Profile</h3>
            <div className="flex items-center gap-2">
              {employee.pipeline_stage && employee.status === 'applicant' && (
                <StatusBadge status={employee.pipeline_stage} label={PIPELINE_STAGE_LABELS[employee.pipeline_stage]} tone={employee.pipeline_stage === 'rejected' ? 'danger' : 'warning'} />
              )}
              {employee.is_supervisor && <StatusBadge status="supervisor" label="Supervisor Pool" tone="info" />}
              {employee.guarantor_needed && !employee.damiin_completed && <StatusBadge status="damiin-needed" label="Damiin Needed" tone="danger" />}
              {employee.profile_complete ? (
                <StatusBadge status="profile-complete" label="Profile Complete" tone="success" />
              ) : (
                <StatusBadge status="profile-incomplete" label="Profile Incomplete" tone="warning" />
              )}
              <StatusBadge status={employee.status} />
            </div>
          </CardHeader>
          <CardBody>
            <div className="mb-5 flex items-center gap-4">
              <div className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">
                {initialsOf(employee.full_name)}
                <PermissionGate module="hr">
                  <button
                    type="button"
                    onClick={() => pictureInputRef.current?.click()}
                    title={employee.profile_picture_document_id ? 'Replace profile picture' : 'Upload profile picture'}
                    className="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full border border-border bg-surface text-ink-muted hover:text-primary"
                  >
                    <Icon name="image" size={12} />
                  </button>
                  <input
                    ref={pictureInputRef}
                    type="file"
                    accept="image/*"
                    hidden
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) profilePictureMutation.mutate(file);
                      e.target.value = '';
                    }}
                  />
                </PermissionGate>
              </div>
              <div>
                <p className="font-display text-base font-bold text-ink">{employee.full_name}</p>
                <p className="text-sm text-ink-muted">{employee.employee_number}</p>
                {employee.profile_picture_document_id && <p className="text-xs text-ink-faint">Profile picture on file</p>}
              </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              <Field label="Phone" value={employee.phone ?? '—'} />
              <Field label="Alternate phone (employee's own)" value={employee.alternate_phone ?? '—'} />
              <Field label="Location" value={employee.location ?? '—'} />
              <Field label="Gender" value={employee.gender ? employee.gender[0].toUpperCase() + employee.gender.slice(1) : 'Not recorded'} />
              <Field label="Age" value={employee.age?.toString() ?? '—'} />
              <Field label="Marital status" value={employee.marital_status ?? '—'} />
              <Field label="Lives with" value={employee.lives_with ?? '—'} />
              <Field label="Reference / guarantor" value={employee.reference_name ?? '—'} />
              <Field label="Secondary/emergency contact name" value={employee.secondary_contact_name ?? '—'} />
              <Field label="Secondary/emergency contact phone" value={employee.secondary_contact_phone ?? '—'} />
              <Field label="Category" value={employee.employee_category_name ?? '—'} />
              <Field label="Category specialization" value={employee.category_specialization ?? '—'} />
              <Field label="Department" value={employee.department_name ?? '—'} />
              <Field label="Position" value={employee.position_name ?? '—'} />
              <Field label="Application date" value={formatDate(employee.application_date)} />
              <Field label="Source" value={employee.source ?? '—'} />
              <Field
                label="Current Salary"
                value={employee.current_salary ? `${employee.current_salary.amount} ${employee.current_salary.currency} / ${employee.current_salary.frequency}` : '—'}
              />
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

            <div className="mt-4 border-t border-border pt-4">
              <div className="mb-1.5 flex items-center gap-2">
                <p className="text-xs font-medium text-ink-faint">Profile Completeness</p>
                {employee.profile_complete ? (
                  <StatusBadge status="complete" tone="success" label="Complete" />
                ) : (
                  <StatusBadge status="incomplete" tone="warning" label="Incomplete" />
                )}
              </div>
              {missingProfileFields.length > 0 ? (
                <p className="text-sm text-ink-muted">Missing: {missingProfileFields.join(', ')}</p>
              ) : (
                <p className="text-sm text-ink-muted">
                  All core profile fields are on record.
                  {!employee.profile_complete && ' Not yet marked complete.'}
                </p>
              )}
            </div>

            <PermissionGate module="hr">
              <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-border pt-5">
                {employee.status !== 'applicant' && nextStatus && (
                  <Button size="sm" isLoading={statusMutation.isPending} onClick={() => statusMutation.mutate(nextStatus)}>
                    Advance to {nextStatus[0].toUpperCase() + nextStatus.slice(1)}
                  </Button>
                )}
                {employee.status === 'active' && (
                  <Button
                    variant="outline"
                    size="sm"
                    isLoading={supervisorMutation.isPending}
                    onClick={() => supervisorMutation.mutate(!employee.is_supervisor)}
                  >
                    {employee.is_supervisor ? 'Remove from Supervisor Pool' : 'Add to Supervisor Pool'}
                  </Button>
                )}
                {employee.status === 'inactive' && (
                  <Button size="sm" onClick={() => setIsRehireOpen(true)}>
                    Rehire
                  </Button>
                )}
                {employee.status !== 'inactive' && (
                  <Button variant="danger" size="sm" onClick={() => setIsSeparateOpen(true)}>
                    Mark Inactive
                  </Button>
                )}
              </div>
              {employee.status === 'applicant' && (
                <p className="mt-3 border-t border-border pt-3 text-xs text-ink-faint">
                  Progression through Damiin → Contract → Uniform → Training → Practical happens via the pipeline cards below, not a manual status change.
                </p>
              )}
            </PermissionGate>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Work Assignments</h3>
              {employee.status === 'active' && (
                <PermissionGate module="hr">
                  <Button size="sm" variant="outline" onClick={() => setIsAssignOpen(true)}>
                    <Icon name="plus" size={14} />
                    Assign
                  </Button>
                </PermissionGate>
              )}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.status !== 'active' && (
                <p className="rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
                  Work assignments are available only for Active employees.
                </p>
              )}
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

          {employee.separations && employee.separations.length > 0 && (
            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">Separation History</h3>
              </CardHeader>
              <CardBody className="space-y-3">
                {employee.separations.map((separation) => (
                  <div key={separation.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center gap-2">
                      <StatusBadge status={separation.reason} label={separation.reason_label} tone="danger" />
                      <span className="text-xs text-ink-faint">{formatDate(separation.separation_date)}</span>
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      {separation.rehire_eligible ? 'Rehire eligible' : 'Not marked rehire eligible'}
                    </p>
                    {separation.notes && <p className="mt-1 text-xs text-ink-muted">{separation.notes}</p>}
                    <p className="mt-1 text-xs text-ink-faint">by {separation.separated_by ?? '—'}</p>
                  </div>
                ))}
              </CardBody>
            </Card>
          )}
        </div>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Attendance History</h3>
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.attendances && employee.attendances.length > 0 ? (
              employee.attendances.map((attendance) => (
                <div key={attendance.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <StatusBadge
                      status={attendance.status}
                      label={ATTENDANCE_STATUS_LABEL[attendance.status]}
                      tone={ATTENDANCE_STATUS_TONE[attendance.status]}
                    />
                    <span className="text-xs text-ink-faint">{formatDate(attendance.date)}</span>
                  </div>
                  {attendance.notes && <p className="mt-1 text-xs text-ink-muted">{attendance.notes}</p>}
                </div>
              ))
            ) : (
              <EmptyState icon="calendar" title="No attendance recorded" />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Leave History</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsLeaveOpen(true)}>
                  <Icon name="plus" size={14} />
                  Record Leave
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.leaves && employee.leaves.length > 0 ? (
              employee.leaves.map((leave) => (
                <div key={leave.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <StatusBadge status={leave.leave_type} label={leave.leave_type_label} tone="warning" />
                    <span className="text-xs text-ink-faint">
                      {formatDate(leave.start_date)} → {formatDate(leave.end_date)}
                    </span>
                  </div>
                  {leave.notes && <p className="mt-1 text-xs text-ink-muted">{leave.notes}</p>}
                </div>
              ))
            ) : (
              <EmptyState icon="calendar" title="No leave recorded" />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Performance Reviews</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsReviewOpen(true)}>
                  <Icon name="plus" size={14} />
                  Add Review
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.performance_reviews && employee.performance_reviews.length > 0 ? (
              employee.performance_reviews.map((review) => (
                <div key={review.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <StatusBadge
                      status={review.rating}
                      label={review.rating_label}
                      tone={review.rating === 'excellent' || review.rating === 'good' ? 'success' : review.rating === 'poor' ? 'danger' : 'warning'}
                    />
                    <span className="text-xs text-ink-faint">{formatDate(review.review_date)}</span>
                  </div>
                  {review.notes && <p className="mt-1 text-xs text-ink-muted">{review.notes}</p>}
                </div>
              ))
            ) : (
              <EmptyState icon="star" title="No reviews yet" />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Payments</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsPaymentOpen(true)}>
                  <Icon name="plus" size={14} />
                  Record Payment
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.payments && employee.payments.length > 0 ? (
              employee.payments.map((payment) => (
                <div key={payment.id} className="flex items-center justify-between border-b border-border pb-3 last:border-0 last:pb-0">
                  <span className="text-xs text-ink-faint">{formatDate(payment.payment_date)}</span>
                  <span className="text-sm text-ink">{payment.amount} {payment.currency}</span>
                </div>
              ))
            ) : (
              <EmptyState icon="credit-card" title="No payments recorded" />
            )}
          </CardBody>
        </Card>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Penalty / Salary Deduction History</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsPenaltyOpen(true)}>
                  <Icon name="plus" size={14} />
                  Record Penalty
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.penalties && employee.penalties.length > 0 ? (
              employee.penalties.map((penalty) => (
                <div key={penalty.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center justify-between">
                    <span className="text-xs text-ink-faint">{formatDate(penalty.penalty_date)}</span>
                    <span className="text-sm text-ink">{penalty.deduction_amount} {penalty.currency}</span>
                  </div>
                  <p className="mt-1 text-xs text-ink-muted">{penalty.reason}</p>
                  <p className="mt-0.5 text-[11px] text-ink-faint">Payroll period {penalty.payroll_period}</p>
                </div>
              ))
            ) : (
              <EmptyState icon="x" title="No penalties recorded" />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Salary Advance History</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr">
                <Button size="sm" variant="outline" onClick={() => setIsAdvanceOpen(true)}>
                  <Icon name="plus" size={14} />
                  Record Advance
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.advances && employee.advances.length > 0 ? (
              employee.advances.map((advance) => (
                <div key={advance.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center justify-between">
                    <span className="text-xs text-ink-faint">{formatDate(advance.advance_date)}</span>
                    <span className="text-sm text-ink">{advance.amount} {advance.currency}</span>
                  </div>
                  <p className="mt-1 text-xs text-ink-muted">{advance.reason}</p>
                  <p className="mt-0.5 text-[11px] text-ink-faint">Payroll period {advance.payroll_period}</p>
                </div>
              ))
            ) : (
              <EmptyState icon="credit-card" title="No advances recorded" />
            )}
          </CardBody>
        </Card>

        <PayrollSummaryCard employeeId={employeeId} employee={employee} />
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Damiin / Guarantor</h3>
              <div className="flex items-center gap-2">
                {employee.guarantor_needed && !employee.damiin_completed && <StatusBadge status="damiin-needed" label="Needed" tone="danger" />}
                {employee.guarantor?.verified_at && <StatusBadge status="verified" tone="success" />}
              </div>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.guarantor ? (
                <dl className="space-y-2 text-sm">
                  <Field label="Name" value={employee.guarantor.guarantor_name ?? '—'} />
                  <Field label="Phone" value={employee.guarantor.guarantor_phone ?? '—'} />
                  <Field label="Relationship" value={employee.guarantor.relationship ?? '—'} />
                  <Field label="Verified" value={employee.guarantor.verified_at ? formatDateTime(employee.guarantor.verified_at) : 'Not yet'} />
                </dl>
              ) : (
                <EmptyState icon="users" title="No guarantor information recorded." />
              )}
              <PermissionGate module="hr">
                {!employee.guarantor?.verified_at && (employee.guarantor_needed || employee.pipeline_stage === 'damiin_needed') && (
                  <div className="space-y-2 border-t border-border pt-3">
                    <input placeholder="Guarantor name" className={inputClasses} value={guarantorForm.guarantor_name} onChange={(e) => setGuarantorForm({ ...guarantorForm, guarantor_name: e.target.value })} />
                    <input placeholder="Guarantor phone" className={inputClasses} value={guarantorForm.guarantor_phone} onChange={(e) => setGuarantorForm({ ...guarantorForm, guarantor_phone: e.target.value })} />
                    <input placeholder="Relationship" className={inputClasses} value={guarantorForm.relationship} onChange={(e) => setGuarantorForm({ ...guarantorForm, relationship: e.target.value })} />
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" isLoading={guarantorSaveMutation.isPending} disabled={!guarantorForm.guarantor_name || !guarantorForm.guarantor_phone} onClick={() => guarantorSaveMutation.mutate()}>
                        Save
                      </Button>
                      {employee.guarantor && (
                        <Button size="sm" isLoading={guarantorVerifyMutation.isPending} onClick={() => guarantorVerifyMutation.mutate()}>
                          Verify
                        </Button>
                      )}
                    </div>
                  </div>
                )}
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Contract / Agreement</h3>
              {employee.current_contract && <StatusBadge status={employee.current_contract.status} />}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.current_contract ? (
                <dl className="space-y-2 text-sm">
                  <Field label="Type" value={employee.current_contract.contract_type} />
                  <Field label="Signed date" value={employee.current_contract.signed_date ? formatDate(employee.current_contract.signed_date) : 'Not signed'} />
                </dl>
              ) : (
                <EmptyState icon="file-text" title="No contract issued yet" />
              )}
              <PermissionGate module="hr">
                {!employee.current_contract && employee.pipeline_stage === 'contract_pending' && (
                  <Button size="sm" isLoading={contractCreateMutation.isPending} onClick={() => contractCreateMutation.mutate()}>
                    Issue Contract
                  </Button>
                )}
                {employee.current_contract && employee.current_contract.status === 'issued' && (
                  <div className="space-y-2 border-t border-border pt-3">
                    <input type="date" className={inputClasses} value={signedDate} onChange={(e) => setSignedDate(e.target.value)} />
                    <Button size="sm" isLoading={contractSignMutation.isPending} onClick={() => contractSignMutation.mutate(employee.current_contract!.id)}>
                      Mark Signed
                    </Button>
                  </div>
                )}
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Uniform</h3>
              {employee.uniform && <StatusBadge status={employee.uniform.status} tone={employee.uniform.status === 'confirmed' ? 'success' : 'warning'} />}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.uniform ? (
                <dl className="space-y-2 text-sm">
                  <Field label="Confirmed" value={employee.uniform.confirmed_at ? formatDateTime(employee.uniform.confirmed_at) : 'Not yet'} />
                </dl>
              ) : (
                <EmptyState icon="box" title="No uniform record yet" />
              )}
              <PermissionGate module="hr">
                {employee.pipeline_stage === 'uniform_pending' && (
                  <div className="flex flex-wrap gap-2 border-t border-border pt-3">
                    <Button size="sm" variant="outline" isLoading={uniformUpdateMutation.isPending} onClick={() => uniformUpdateMutation.mutate('purchased')}>
                      Mark Purchased
                    </Button>
                    <Button size="sm" variant="outline" isLoading={uniformUpdateMutation.isPending} onClick={() => uniformUpdateMutation.mutate('received')}>
                      Mark Received
                    </Button>
                    <Button size="sm" isLoading={uniformConfirmMutation.isPending} onClick={() => uniformConfirmMutation.mutate()}>
                      Confirm Uniform
                    </Button>
                  </div>
                )}
              </PermissionGate>
            </CardBody>
          </Card>
      </div>

      {employee.historical_completions && employee.historical_completions.length > 0 && (
        <div className="mt-4">
          <Card className="border-secondary/30 bg-secondary-soft/40">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">Historical HR Completion</h3>
              <span className="rounded-full bg-secondary-soft px-2.5 py-1 text-xs font-semibold text-secondary">Historical — not live workflow</span>
            </CardHeader>
            <CardBody>
              <p className="mb-3 text-xs text-ink-muted">
                Recorded from Excel HR migration evidence, not a current operational queue. This never affects live status, pipeline
                stage, or the active Damiin/Contract/Uniform/Training/Practical/Waiting workflow.
              </p>
              <ul className="space-y-1.5 text-sm">
                {employee.historical_completions.map((completion) => (
                  <li key={completion.id} className="flex items-center gap-2 text-ink">
                    <Icon name="check" size={14} className="text-secondary" />
                    <span className="font-medium capitalize">{completion.stage}</span>
                    <span className="text-ink-muted">— Completed Historically</span>
                  </li>
                ))}
              </ul>
              <p className="mt-3 text-xs text-ink-faint">Source: {employee.historical_completions[0]?.source_reference ?? 'Excel Migration'}</p>
            </CardBody>
          </Card>
        </div>
      )}

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Practical Assessments</h3>
          </CardHeader>
          <CardBody>
            {employee.practical_assessments && employee.practical_assessments.length > 0 ? (
              <div className="mb-4 space-y-3">
                {[...employee.practical_assessments].reverse().map((a) => (
                  <div key={a.id} className="flex items-start justify-between border-b border-border pb-3 last:border-0">
                    <div>
                      <div className="flex items-center gap-2">
                        {a.attempt_number && <span className="text-xs font-semibold text-ink-faint">Attempt #{a.attempt_number}</span>}
                        <StatusBadge
                          status={a.result}
                          tone={a.result === 'approved' || a.result === 'pass' ? 'success' : a.result === 'rejected' || a.result === 'fail' ? 'danger' : 'warning'}
                        />
                      </div>
                      {a.notes && <p className="mt-1 text-xs text-ink-muted">{a.notes}</p>}
                    </div>
                    <span className="text-xs text-ink-faint">{formatDate(a.assessment_date)}</span>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="check" title="No practical decisions recorded" />
            )}
            <PermissionGate module="hr">
              {(employee.pipeline_stage === 'need_practical' || employee.pipeline_stage === 'practical_repeat') && (
                <div className="space-y-2 border-t border-border pt-4">
                  <Select
                    value={practicalBatchId}
                    onChange={(e) => setPracticalBatchId(e.target.value)}
                    placeholder="Practical batch (optional)"
                    options={(practicalBatches ?? []).map((b) => ({ value: String(b.id), label: `${b.batch_number} — ${formatDate(b.batch_date)}` }))}
                  />
                  <input
                    type="text"
                    placeholder="Notes (optional)"
                    value={assessmentNotes}
                    onChange={(e) => setAssessmentNotes(e.target.value)}
                    className={inputClasses}
                  />
                  <div className="flex flex-wrap gap-2">
                    {DECISION_OPTIONS.map((option) => (
                      <Button
                        key={option.value}
                        size="sm"
                        variant={decisionResult === option.value ? 'primary' : 'outline'}
                        isLoading={decisionMutation.isPending && decisionResult === option.value}
                        onClick={() => {
                          setDecisionResult(option.value);
                          decisionMutation.mutate();
                        }}
                      >
                        {option.label}
                      </Button>
                    ))}
                  </div>
                </div>
              )}
            </PermissionGate>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">Training History</h3>
          </CardHeader>
          <CardBody>
            {employee.training_history && employee.training_history.length > 0 ? (
              <div className="space-y-3">
                {employee.training_history.map((t) => (
                  <div key={t.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-ink">{t.batch_number ?? `Batch #${t.training_batch_id}`}</span>
                      <StatusBadge
                        status={t.result}
                        label={t.result[0].toUpperCase() + t.result.slice(1)}
                        tone={t.result === 'completed' ? 'success' : t.result === 'absent' ? 'danger' : 'warning'}
                      />
                    </div>
                    <p className="mt-1 text-xs text-ink-faint">
                      {formatDate(t.batch_date)}
                      {t.start_time && t.end_time ? ` · ${t.start_time}–${t.end_time}` : ''}
                      {t.team_or_group ? ` · ${t.team_or_group}` : ''}
                    </p>
                    {(t.trainer_name || t.location) && (
                      <p className="text-xs text-ink-faint">
                        {t.trainer_name ? `Trainer: ${t.trainer_name}` : ''}
                        {t.trainer_name && t.location ? ' · ' : ''}
                        {t.location ? `Location: ${t.location}` : ''}
                      </p>
                    )}
                    {t.notes && <p className="mt-1 text-xs text-ink-muted">{t.notes}</p>}
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="list" title="No training recorded" />
            )}
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
            <PermissionGate module="hr">
              <Select
                value={documentCategoryId}
                onChange={(e) => setDocumentCategoryId(e.target.value)}
                placeholder="Category for next upload…"
                options={(documentCategories ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
                className="mb-3"
              />
            </PermissionGate>
            {employee.documents && employee.documents.length > 0 ? (
              <div className="space-y-2">
                {employee.documents.map((doc) => (
                  <div key={doc.id} className={`flex items-center justify-between rounded-sm border px-3 py-2 ${doc.is_current ? 'border-border' : 'border-border/50 opacity-60'}`}>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 text-sm text-ink">
                        <Icon name="file-text" size={15} className="text-ink-faint" />
                        <span className="truncate">{doc.file_name}</span>
                        {!doc.is_current && <span className="text-[10px] uppercase text-ink-faint">Superseded</span>}
                      </div>
                      <div className="ml-6 mt-0.5 flex items-center gap-1.5 text-xs text-ink-faint">
                        {doc.category_name && <span>{doc.category_name}</span>}
                        <StatusBadge
                          status={doc.verification_status}
                          tone={doc.verification_status === 'verified' ? 'success' : doc.verification_status === 'rejected' ? 'danger' : 'neutral'}
                        />
                      </div>
                    </div>
                    <PermissionGate module="hr">
                      <div className="flex shrink-0 items-center gap-2">
                        {doc.is_current && doc.verification_status === 'pending' && (
                          <button type="button" onClick={() => verifyDocumentMutation.mutate(doc.id)} className="text-xs font-medium text-primary hover:underline">
                            Verify
                          </button>
                        )}
                        <button
                          type="button"
                          title="Remove (mistaken upload)"
                          onClick={() => deleteDocumentMutation.mutate(doc.id)}
                          className="text-ink-faint hover:text-danger"
                        >
                          <Icon name="trash" size={14} />
                        </button>
                      </div>
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

      <MarkSeparatedModal
        isOpen={isSeparateOpen}
        onClose={() => setIsSeparateOpen(false)}
        onConfirm={(payload) => separateMutation.mutate(payload)}
        isLoading={separateMutation.isPending}
      />

      <RehireModal
        isOpen={isRehireOpen}
        onClose={() => setIsRehireOpen(false)}
        onConfirm={(note) => rehireMutation.mutate(note)}
        isLoading={rehireMutation.isPending}
      />

      <RecordLeaveModal
        isOpen={isLeaveOpen}
        onClose={() => setIsLeaveOpen(false)}
        onConfirm={(payload) => leaveMutation.mutate(payload)}
        isLoading={leaveMutation.isPending}
      />

      <RecordPerformanceReviewModal
        isOpen={isReviewOpen}
        onClose={() => setIsReviewOpen(false)}
        onConfirm={(payload) => reviewMutation.mutate(payload)}
        isLoading={reviewMutation.isPending}
      />

      <RecordEmployeePaymentModal
        isOpen={isPaymentOpen}
        onClose={() => setIsPaymentOpen(false)}
        onConfirm={(payload) => paymentMutation.mutate(payload)}
        isLoading={paymentMutation.isPending}
      />

      <RecordPenaltyModal
        isOpen={isPenaltyOpen}
        onClose={() => setIsPenaltyOpen(false)}
        onConfirm={(payload) => penaltyMutation.mutate(payload)}
        isLoading={penaltyMutation.isPending}
      />

      <RecordAdvanceModal
        isOpen={isAdvanceOpen}
        onClose={() => setIsAdvanceOpen(false)}
        onConfirm={(payload) => advanceMutation.mutate(payload)}
        isLoading={advanceMutation.isPending}
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

function MarkSeparatedModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { reason: EmployeeSeparationReason; separation_date: string; rehire_eligible: boolean; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [reason, setReason] = useState<EmployeeSeparationReason>('resigned');
  const [separationDate, setSeparationDate] = useState(new Date().toISOString().slice(0, 10));
  const [rehireEligible, setRehireEligible] = useState(true);
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Mark Inactive"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            variant="danger"
            size="sm"
            isLoading={isLoading}
            onClick={() => onConfirm({ reason, separation_date: separationDate, rehire_eligible: rehireEligible, notes: notes || null })}
          >
            Mark Inactive
          </Button>
        </>
      }
    >
      <FormField label="Reason" htmlFor="sep-reason" required>
        <Select id="sep-reason" value={reason} onChange={(e) => setReason(e.target.value as EmployeeSeparationReason)} options={SEPARATION_REASON_OPTIONS} />
      </FormField>
      <FormField label="Separation date" htmlFor="sep-date" required>
        <input id="sep-date" type="date" className={inputClasses} value={separationDate} onChange={(e) => setSeparationDate(e.target.value)} />
      </FormField>
      <FormField label="Rehire eligible" htmlFor="sep-rehire">
        <label className="flex items-center gap-2 text-sm text-ink">
          <input id="sep-rehire" type="checkbox" checked={rehireEligible} onChange={(e) => setRehireEligible(e.target.checked)} />
          Eligible for rehire in the future
        </label>
      </FormField>
      <FormField label="Notes" htmlFor="sep-notes">
        <textarea id="sep-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RehireModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (note: string) => void;
  isLoading: boolean;
}) {
  const [note, setNote] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Rehire Employee"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} onClick={() => onConfirm(note)}>Rehire</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">This reactivates the employee directly to Active. No pipeline steps are repeated.</p>
      <FormField label="Note" htmlFor="rehire-note">
        <textarea id="rehire-note" rows={2} className={inputClasses + ' h-auto py-2'} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordLeaveModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { leave_type: LeaveType; start_date: string; end_date: string; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [leaveType, setLeaveType] = useState<LeaveType>('annual');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record Leave"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            onClick={() => onConfirm({ leave_type: leaveType, start_date: startDate, end_date: endDate, notes: notes || null })}
          >
            Record
          </Button>
        </>
      }
    >
      <FormField label="Leave type" htmlFor="leave-type" required>
        <Select id="leave-type" value={leaveType} onChange={(e) => setLeaveType(e.target.value as LeaveType)} options={LEAVE_TYPE_OPTIONS} />
      </FormField>
      <FormField label="Start date" htmlFor="leave-start" required>
        <input id="leave-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
      </FormField>
      <FormField label="End date" htmlFor="leave-end" required>
        <input id="leave-end" type="date" className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </FormField>
      <FormField label="Notes" htmlFor="leave-notes">
        <textarea id="leave-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordPerformanceReviewModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { review_date: string; rating: PerformanceRating; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [reviewDate, setReviewDate] = useState(new Date().toISOString().slice(0, 10));
  const [rating, setRating] = useState<PerformanceRating>('good');
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Add Performance Review"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={isLoading} onClick={() => onConfirm({ review_date: reviewDate, rating, notes: notes || null })}>
            Record
          </Button>
        </>
      }
    >
      <FormField label="Review date" htmlFor="review-date" required>
        <input id="review-date" type="date" className={inputClasses} value={reviewDate} onChange={(e) => setReviewDate(e.target.value)} />
      </FormField>
      <FormField label="Rating" htmlFor="review-rating" required>
        <Select id="review-rating" value={rating} onChange={(e) => setRating(e.target.value as PerformanceRating)} options={PERFORMANCE_RATING_OPTIONS} />
      </FormField>
      <FormField label="Notes" htmlFor="review-notes">
        <textarea id="review-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordEmployeePaymentModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { payment_date: string; amount: number; currency: string; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record Payment"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            disabled={!amount}
            onClick={() => onConfirm({ payment_date: paymentDate, amount: Number(amount), currency, notes: notes || null })}
          >
            Record
          </Button>
        </>
      }
    >
      <FormField label="Payment date" htmlFor="pay-date" required>
        <input id="pay-date" type="date" className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label="Amount" htmlFor="pay-amount" required>
          <input id="pay-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label="Currency" htmlFor="pay-currency" required>
          <input id="pay-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label="Notes" htmlFor="pay-notes">
        <textarea id="pay-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordPenaltyModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { penalty_date: string; reason: string; deduction_amount: number; currency: string; payroll_period: string; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [penaltyDate, setPenaltyDate] = useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [payrollPeriod, setPayrollPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record Penalty"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            disabled={!amount || !reason}
            onClick={() =>
              onConfirm({
                penalty_date: penaltyDate,
                reason,
                deduction_amount: Number(amount),
                currency,
                payroll_period: payrollPeriod,
                notes: notes || null,
              })
            }
          >
            Record
          </Button>
        </>
      }
    >
      <FormField label="Penalty date" htmlFor="pen-date" required>
        <input id="pen-date" type="date" className={inputClasses} value={penaltyDate} onChange={(e) => setPenaltyDate(e.target.value)} />
      </FormField>
      <FormField label="Reason" htmlFor="pen-reason" required>
        <input id="pen-reason" className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label="Deduction amount" htmlFor="pen-amount" required>
          <input id="pen-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label="Currency" htmlFor="pen-currency" required>
          <input id="pen-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label="Payroll period" htmlFor="pen-period" required>
        <input id="pen-period" type="month" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
      </FormField>
      <FormField label="Notes" htmlFor="pen-notes">
        <textarea id="pen-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function RecordAdvanceModal({
  isOpen,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (payload: { advance_date: string; amount: number; currency: string; payroll_period: string; reason: string; notes: string | null }) => void;
  isLoading: boolean;
}) {
  const [advanceDate, setAdvanceDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [payrollPeriod, setPayrollPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Record Salary Advance"
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            disabled={!amount || !reason}
            onClick={() =>
              onConfirm({
                advance_date: advanceDate,
                amount: Number(amount),
                currency,
                payroll_period: payrollPeriod,
                reason,
                notes: notes || null,
              })
            }
          >
            Record
          </Button>
        </>
      }
    >
      <FormField label="Advance date" htmlFor="adv-date" required>
        <input id="adv-date" type="date" className={inputClasses} value={advanceDate} onChange={(e) => setAdvanceDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label="Amount" htmlFor="adv-amount" required>
          <input id="adv-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label="Currency" htmlFor="adv-currency" required>
          <input id="adv-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label="Payroll period" htmlFor="adv-period" required>
        <input id="adv-period" type="month" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
      </FormField>
      <FormField label="Reason" htmlFor="adv-reason" required>
        <input id="adv-reason" className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label="Notes" htmlFor="adv-notes">
        <textarea id="adv-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5: a read-only computed breakdown, no
 * save action of its own - HR still records the actual payment via the
 * existing "Record Payment" button. Overtime is never a number here: an
 * office-location assignment shows a static pending note, a client-location
 * assignment shows no overtime line at all.
 */
function PayrollSummaryCard({ employeeId, employee }: { employeeId: number; employee: Employee }) {
  const activeAssignments = employee.active_work_assignments ?? [];
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [workAssignmentId, setWorkAssignmentId] = useState(activeAssignments[0] ? String(activeAssignments[0].id) : '');

  const { data, isLoading, error } = useQuery({
    queryKey: ['payroll-summary', employeeId, workAssignmentId, period],
    queryFn: () => hrApi.payroll.summary(employeeId, Number(workAssignmentId), period),
    enabled: workAssignmentId !== '',
  });

  return (
    <Card>
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">Payroll Summary</h3>
      </CardHeader>
      <CardBody className="space-y-3">
        {activeAssignments.length === 0 ? (
          <EmptyState icon="credit-card" title="No active work assignment" description="Payroll can only be calculated for an active assignment." />
        ) : (
          <>
            <div className="flex items-center gap-2">
              {activeAssignments.length > 1 && (
                <Select
                  value={workAssignmentId}
                  onChange={(e) => setWorkAssignmentId(e.target.value)}
                  className="h-8 flex-1 text-xs"
                  options={activeAssignments.map((a) => ({
                    value: String(a.id),
                    label: a.location_type === 'office' ? 'Fayadhowr Office' : (a.client_company_name ?? a.work_location_name),
                  }))}
                />
              )}
              <input type="month" className={`${inputClasses} h-8 text-xs`} value={period} onChange={(e) => setPeriod(e.target.value)} />
            </div>

            {isLoading && <p className="text-xs text-ink-faint">Calculating…</p>}
            {error && <p className="text-xs text-danger">Could not load payroll summary.</p>}

            {data && (
              <dl className="space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Monthly Salary</dt>
                  <dd className="text-ink">{data.monthly_salary} {data.currency}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Absence Deduction ({data.absent_days}d)</dt>
                  <dd className="text-danger">−{data.absence_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Penalty Deduction</dt>
                  <dd className="text-danger">−{data.penalty_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">Salary Advance</dt>
                  <dd className="text-danger">−{data.advance_deduction}</dd>
                </div>
                <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                  <dt className="text-ink">Net Payable</dt>
                  <dd className="text-ink">{data.net_payable} {data.currency}</dd>
                </div>
                {data.location_type === 'office' && (
                  <p className="pt-1 text-[11px] italic text-ink-faint">Overtime: pending management decision</p>
                )}
              </dl>
            )}
          </>
        )}
      </CardBody>
    </Card>
  );
}
