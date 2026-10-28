import { useRef, useState, type ReactNode } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { useI18n } from '@/i18n/useI18n';
import { localizeServerMessage } from '@/i18n/serverMessages';
import type { MessageKey } from '@/i18n/messages';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { SuccessorPicker } from '@/features/hrm/companyEmployees/SuccessorPicker';
import { EMPTY_SUCCESSOR, missingSuccessors, needsSuccessor, toSuccessorPayload, validateSuccessor, type SuccessorDraft } from '@/utils/successor';
import { Select } from '@/components/ui/Select';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { MoveStageDialog } from '@/features/hrm/MoveStageDialog';
import { RestoreEmployeeDialog } from '@/features/hrm/RestoreEmployeeDialog';
import { StatusOverrideDialog } from '@/features/hrm/StatusOverrideDialog';
import { advanceBlockedByWorkflow, canOfferStageMove } from '@/utils/registrationWorkflow';
import { EmployeeFormDialog } from '@/features/hrm/EmployeeFormDialog';
import { formatDate, formatDateTime, initialsOf } from '@/utils/formatters';
import { additionalCompanyQuestion, assignmentLabel, friendlyErrorMessage, isWorkplaceMissing, localToday, officeLabel } from '@/utils/hrHelpers';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { submitPracticalDecision } from '@/utils/practicalDecision';
import type { AttendanceStatus, Employee, MarkEmployeeSeparatedPayload, SuccessorPayload, EmployeeDocument, EmployeeLeave, EmployeeSeparationReason, EmployeeStatus, LeaveType, PerformanceRating, PracticalDecision, SalaryFrequency, WorkAssignment } from '@/types/employee';

const SEPARATION_REASON_OPTIONS: { value: EmployeeSeparationReason; labelKey: MessageKey }[] = [
  { value: 'resigned', labelKey: 'option.separation.resigned' },
  { value: 'terminated', labelKey: 'option.separation.terminated' },
  { value: 'contract_ended', labelKey: 'option.separation.contract_ended' },
  { value: 'other', labelKey: 'option.separation.other' },
];

const LEAVE_TYPE_OPTIONS: { value: LeaveType; labelKey: MessageKey }[] = [
  { value: 'annual', labelKey: 'option.leaveType.annual' },
  { value: 'sick', labelKey: 'option.leaveType.sick' },
  { value: 'unpaid', labelKey: 'option.leaveType.unpaid' },
  { value: 'other', labelKey: 'option.leaveType.other' },
];

const PERFORMANCE_RATING_OPTIONS: { value: PerformanceRating; labelKey: MessageKey }[] = [
  { value: 'excellent', labelKey: 'option.employeeDetail.rating.excellent' },
  { value: 'good', labelKey: 'option.employeeDetail.rating.good' },
  { value: 'needs_improvement', labelKey: 'option.employeeDetail.rating.needs_improvement' },
  { value: 'poor', labelKey: 'option.employeeDetail.rating.poor' },
];

function translatedOptions<V extends string>(options: { value: V; labelKey: MessageKey }[], t: (key: MessageKey) => string): { value: V; label: string }[] {
  return options.map((option) => ({ value: option.value, label: t(option.labelKey) }));
}

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
const PROFILE_COMPLETENESS_FIELDS: { labelKey: MessageKey; isPresent: (e: Employee) => boolean }[] = [
  { labelKey: 'option.employeeDetail.completenessFullName', isPresent: (e) => Boolean(e.full_name) },
  { labelKey: 'option.label.phone', isPresent: (e) => Boolean(e.phone) },
  { labelKey: 'option.label.gender', isPresent: (e) => Boolean(e.gender) },
  { labelKey: 'option.label.age', isPresent: (e) => e.age !== null },
  { labelKey: 'option.employeeDetail.completenessMaritalStatus', isPresent: (e) => Boolean(e.marital_status) },
  { labelKey: 'option.label.location', isPresent: (e) => Boolean(e.location) },
  { labelKey: 'option.employeeDetail.completenessEmployeeCategory', isPresent: (e) => Boolean(e.employee_category_id) },
  { labelKey: 'option.label.department', isPresent: (e) => Boolean(e.department_id) },
  { labelKey: 'option.employeeDetail.completenessReferenceName', isPresent: (e) => Boolean(e.reference_name) },
  { labelKey: 'option.employeeDetail.completenessApplicationDate', isPresent: (e) => Boolean(e.application_date) },
  { labelKey: 'option.employeeDetail.completenessJoiningDate', isPresent: (e) => Boolean(e.joining_date) },
  { labelKey: 'option.employeeDetail.experience', isPresent: (e) => Boolean(e.experience) },
];

const DECISION_OPTIONS: { value: PracticalDecision; labelKey: MessageKey; tone: 'success' | 'danger' | 'warning' }[] = [
  { value: 'approved', labelKey: 'action.decisionApproved', tone: 'success' },
  { value: 'ku_celis_practical', labelKey: 'action.decisionKuCelis', tone: 'warning' },
  { value: 'rejected', labelKey: 'action.decisionRejected', tone: 'danger' },
];

/** The "Back to ..." label and breadcrumb the list pages pass in router state, when it is the name of an HR navigation item. */
const FROM_LABEL_KEYS = new Map<string, MessageKey>([
  ['Employees', 'nav.employees'],
  ['Damiin Needed', 'nav.damiinNeeded'],
  ['Contract Pending', 'nav.contractPending'],
  ['Uniform Pending', 'nav.uniformPending'],
  ['Need Training', 'nav.needTraining'],
  ['Need Practical', 'nav.needPractical'],
  ['Practical Repeat', 'nav.practicalRepeat'],
  ['Rejected', 'nav.rejected'],
  ['Rejected (Historical)', 'nav.rejectedHistorical'],
  ['Waiting', 'nav.waiting'],
  ['Waiting Analytics', 'nav.waitingAnalytics'],
  ['Workforce Requests', 'nav.workforceRequests'],
  ['Training Batches', 'nav.trainingBatches'],
  ['Practical Batches', 'nav.practicalBatches'],
  ['Temporary Replacements', 'nav.temporaryReplacements'],
  ['Supervisor Pool', 'nav.supervisorPool'],
  ['Office Staff', 'nav.officeStaff'],
]);

export function EmployeeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const employeeId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const { t, tValue } = useI18n();
  // Issue #10: return to wherever the HR user actually opened this record from
  // (Need Training, Damiin, Waiting, etc.) instead of always the generic
  // Employees list. Falls back to the generic list for a direct/deep link.
  const origin = location.state as { fromPath?: string; fromLabel?: string } | null;
  const fromPath = origin?.fromPath ?? '/hr/employees';
  const fromLabel = origin?.fromLabel ?? 'Employees';
  const fromLabelKey = FROM_LABEL_KEYS.get(fromLabel);
  const shownFromLabel = fromLabelKey ? t(fromLabelKey) : fromLabel;
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { hasPermission } = useEffectivePermissions();
  // UX only: the server enforces hr.view on the download and hr.manage on the category change.
  const canDownloadDocuments = hasPermission('hr.view');
  const canChangeDocumentCategory = hasPermission('hr.manage');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const { isSuperAdmin: canOverrideStatus, admin } = useAuth();
  const [isOverrideOpen, setIsOverrideOpen] = useState(false);
  const [isMoveStageOpen, setIsMoveStageOpen] = useState(false);
  const [isEditOpen, setIsEditOpen] = useState(false);
  const [isRestoreOpen, setIsRestoreOpen] = useState(false);
  const [assessmentNotes, setAssessmentNotes] = useState('');
  const [isAssignOpen, setIsAssignOpen] = useState(false);
  const [endingAssignment, setEndingAssignment] = useState<WorkAssignment | null>(null);
  const [settingWorkplaceFor, setSettingWorkplaceFor] = useState<WorkAssignment | null>(null);
  const [changingSalaryFor, setChangingSalaryFor] = useState<WorkAssignment | null>(null);
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
  // Documents card: the document whose category is being corrected, and the category picked for it.
  const [recategorizing, setRecategorizing] = useState<EmployeeDocument | null>(null);
  const [recategorizeTo, setRecategorizeTo] = useState('');
  const [recategorizeError, setRecategorizeError] = useState<string | null>(null);

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
      show(t('notify.employeeDetail.statusUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.statusUpdateFailed'), 'error'),
  });

  const separateMutation = useMutation({
    mutationFn: (payload: MarkEmployeeSeparatedPayload) => hrApi.employees.separate(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.separated'));
      setIsSeparateOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.separateFailed'), 'error'),
  });

  const rehireMutation = useMutation({
    mutationFn: (note: string) => hrApi.employees.rehire(employeeId, note || undefined),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.rehired'));
      setIsRehireOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.rehireFailed'), 'error'),
  });

  const supervisorMutation = useMutation({
    mutationFn: (isSupervisor: boolean) => hrApi.employees.toggleSupervisor(employeeId, isSupervisor),
    onSuccess: (_data, isSupervisor) => {
      invalidate();
      show(isSupervisor ? t('notify.employeeDetail.supervisorAdded') : t('notify.employeeDetail.supervisorRemoved'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.supervisorFailed'), 'error'),
  });

  const [rejectingLeave, setRejectingLeave] = useState<EmployeeLeave | null>(null);
  const [rejectNote, setRejectNote] = useState('');

  const approveLeaveMutation = useMutation({
    mutationFn: (leaveId: number) => hrApi.employees.approveLeave(employeeId, leaveId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.leaveApproved'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.employeeDetail.leaveApproveFailed')), 'error'),
  });

  const rejectLeaveMutation = useMutation({
    mutationFn: () => hrApi.employees.rejectLeave(employeeId, rejectingLeave!.id, rejectNote.trim()),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.leaveRejected'));
      setRejectingLeave(null);
      setRejectNote('');
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.employeeDetail.leaveRejectFailed')), 'error'),
  });

  const leaveMutation = useMutation({
    mutationFn: (payload: { leave_type: LeaveType; start_date: string; end_date: string; notes: string | null }) =>
      hrApi.employees.addLeave(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.leaveRecorded'));
      setIsLeaveOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.leaveRecordFailed'), 'error'),
  });

  const reviewMutation = useMutation({
    mutationFn: (payload: { review_date: string; rating: PerformanceRating; notes: string | null }) =>
      hrApi.employees.addPerformanceReview(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.reviewRecorded'));
      setIsReviewOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.reviewFailed'), 'error'),
  });

  const paymentMutation = useMutation({
    mutationFn: (payload: { payment_date: string; amount: number; currency: string; notes: string | null }) =>
      hrApi.employees.addPayment(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.paymentRecorded'));
      setIsPaymentOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.paymentFailed'), 'error'),
  });

  const penaltyMutation = useMutation({
    mutationFn: (payload: { penalty_date: string; reason: string; deduction_amount: number; currency: string; payroll_period: string; notes: string | null }) =>
      hrApi.employees.addPenalty(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.penaltyRecorded'));
      setIsPenaltyOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.penaltyFailed'), 'error'),
  });

  const advanceMutation = useMutation({
    mutationFn: (payload: { advance_date: string; amount: number; currency: string; payroll_period: string; reason: string; notes: string | null }) =>
      hrApi.employees.addAdvance(employeeId, payload),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.advanceRecorded'));
      setIsAdvanceOpen(false);
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.advanceFailed'), 'error'),
  });

  const { data: documentCategories } = useQuery({ queryKey: ['employee-document-categories'], queryFn: hrApi.documentCategories.list });
  const { data: practicalBatches } = useQuery({ queryKey: ['practical-batches'], queryFn: hrApi.practicalBatches.list });

  const guarantorSaveMutation = useMutation({
    mutationFn: () => hrApi.employees.guarantor.save(employeeId, guarantorForm),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.guarantorSaved'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.guarantorSaveFailed'), 'error'),
  });

  const guarantorVerifyMutation = useMutation({
    mutationFn: () => hrApi.employees.guarantor.verify(employeeId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.guarantorVerified'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.guarantorVerifyFailed'), 'error'),
  });

  const contractCreateMutation = useMutation({
    mutationFn: () => hrApi.employees.contracts.create(employeeId, {}),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.contractIssued'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.contractIssueFailed'), 'error'),
  });

  const contractSignMutation = useMutation({
    mutationFn: (contractId: number) => hrApi.employees.contracts.sign(employeeId, contractId, { signed_date: signedDate }),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.contractSigned'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.contractSignFailed'), 'error'),
  });

  const uniformUpdateMutation = useMutation({
    mutationFn: (status: string) => hrApi.employees.uniform.update(employeeId, { status }),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.uniformUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.uniformUpdateFailed'), 'error'),
  });

  const uniformConfirmMutation = useMutation({
    mutationFn: () => hrApi.employees.uniform.confirm(employeeId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.uniformConfirmed'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.uniformConfirmFailed'), 'error'),
  });

  // The clicked decision is passed in as the mutation variable, never read from component state:
  // a state setter is asynchronous, so reading it here would submit the previous (default Approved) value.
  const decisionMutation = useMutation({
    mutationFn: (result: PracticalDecision) =>
      submitPracticalDecision(
        (payload) => hrApi.employees.addPracticalAssessment(employeeId, payload),
        result,
        { assessmentDate: new Date().toISOString().slice(0, 10), practicalBatchId, notes: assessmentNotes },
      ),
    onSuccess: () => {
      invalidate();
      setAssessmentNotes('');
      show(t('notify.employeeDetail.decisionRecorded'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.decisionFailed'), 'error'),
  });

  const uploadMutation = useMutation({
    mutationFn: (file: File) =>
      hrApi.employees.uploadDocument(employeeId, file, {
        employee_document_category_id: documentCategoryId ? Number(documentCategoryId) : null,
      }),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.documentUploaded'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.documentUploadFailed'), 'error'),
  });

  const deleteDocumentMutation = useMutation({
    mutationFn: (documentId: number) => hrApi.employees.deleteDocument(employeeId, documentId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.documentRemoved'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.documentRemoveFailed'), 'error'),
  });

  const verifyDocumentMutation = useMutation({
    mutationFn: (documentId: number) => hrApi.employees.verifyDocument(employeeId, documentId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.documentVerified'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.documentVerifyFailed'), 'error'),
  });

  // Opens the stored file through the authenticated download endpoint (no public URL exists). Works for any document,
  // whatever its category, and for former employees.
  const downloadDocumentMutation = useMutation({
    mutationFn: (doc: EmployeeDocument) => hrApi.employees.downloadDocument(employeeId, doc.id, doc.file_name),
    onError: (err) => show(friendlyErrorMessage(err, t('notify.employeeDetail.documentDownloadFailed')), 'error'),
  });

  // Corrects the category of the EXISTING document: no new document and no re-upload, the file itself is not touched.
  const recategorizeMutation = useMutation({
    mutationFn: (payload: { documentId: number; categoryId: number }) =>
      hrApi.employees.updateDocumentCategory(employeeId, payload.documentId, payload.categoryId),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.categoryUpdated'));
      setRecategorizing(null);
      setRecategorizeError(null);
    },
    // A refusal ("that category already has a current document …") stays visible in the dialog.
    onError: (err) => setRecategorizeError(friendlyErrorMessage(err, t('notify.employeeDetail.categoryUpdateFailed'))),
  });

  const closeRecategorize = () => {
    setRecategorizing(null);
    setRecategorizeTo('');
    setRecategorizeError(null);
  };

  const profilePictureMutation = useMutation({
    mutationFn: (file: File) => hrApi.employees.uploadProfilePicture(employeeId, file),
    onSuccess: () => {
      invalidate();
      show(t('notify.employeeDetail.pictureUpdated'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.pictureFailed'), 'error'),
  });

  const endAssignmentMutation = useMutation({
    mutationFn: (payload: { id: number; end_date: string; note?: string | null; successor?: SuccessorPayload }) =>
      hrApi.employees.workAssignments.end(payload.id, { end_date: payload.end_date, note: payload.note, successor: payload.successor }),
    onSuccess: () => {
      invalidate();
      setEndingAssignment(null);
      show(t('notify.employeeDetail.assignmentEnded'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.employeeDetail.assignmentEndFailed'), 'error'),
  });

  if (isLoading) return <LoadingState label={t('option.employeeDetail.loadingEmployee')} />;
  if (error || !employee) return <ErrorState error={error} onRetry={refetch} />;

  const statusFlowIndex = STATUS_FLOW.indexOf(employee.status);
  const nextStatus = statusFlowIndex === -1 ? undefined : STATUS_FLOW[statusFlowIndex + 1];
  const advanceBlocked = advanceBlockedByWorkflow(employee.registration_workflow, nextStatus);
  const canMoveStage = canOfferStageMove(admin?.role, employee.registration_origin, employee.status);
  const missingProfileFields = PROFILE_COMPLETENESS_FIELDS.filter((field) => !field.isPresent(employee)).map((field) => t(field.labelKey));

  return (
    <div>
      <PageHeader
        title={employee.full_name}
        breadcrumb={[
          { label: t('nav.hrGroup'), to: '/hr' },
          { label: shownFromLabel, to: fromPath },
          { label: employee.employee_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => navigate(fromPath)}>
              <Icon name="chevron-left" size={14} />
              {t('action.employeeDetail.backTo', { label: shownFromLabel })}
            </Button>
            <PermissionGate module="hr">
              <Button variant="outline" size="sm" onClick={() => setIsEditOpen(true)}>
                <Icon name="pencil" size={14} />
                {t('action.edit')}
              </Button>
            </PermissionGate>
          </div>
        }
      />

      {employee.is_archived && (
        <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm text-ink-muted">
          <span>
            <strong className="text-ink">{t('warning.employeeDetail.archivedLead')}</strong> {t('warning.employeeDetail.archivedBody')}
          </span>
          <PermissionGate module="hr">
            <Button size="sm" onClick={() => setIsRestoreOpen(true)}>
              <Icon name="refresh" size={14} />
              {t('action.restore')}
            </Button>
          </PermissionGate>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.label.profile')}</h3>
            <div className="flex min-w-0 flex-wrap items-center justify-end gap-2">
              {employee.pipeline_stage && employee.status === 'applicant' && (
                <StatusBadge status={employee.pipeline_stage} label={tValue('status.stage', employee.pipeline_stage) ?? PIPELINE_STAGE_LABELS[employee.pipeline_stage]} tone={employee.pipeline_stage === 'rejected' ? 'danger' : 'warning'} />
              )}
              {employee.employment_type === 'temporary' && <StatusBadge status="temporary" label={t('option.employmentType.temporary')} tone="warning" />}
              {employee.is_supervisor && <StatusBadge status="supervisor" label={t('nav.supervisorPool')} tone="info" />}
              {employee.guarantor_needed && !employee.damiin_completed && <StatusBadge status="damiin-needed" label={t('status.stage.damiin_needed')} tone="danger" />}
              {employee.profile_complete ? (
                <StatusBadge status="profile-complete" label={t('status.employeeDetail.profileComplete')} tone="success" />
              ) : (
                <StatusBadge status="profile-incomplete" label={t('status.employeeDetail.profileIncomplete')} tone="warning" />
              )}
              <StatusBadge status={employee.status} label={tValue('status', employee.status)} />
            </div>
          </CardHeader>
          <CardBody>
            <div className="mb-5 flex items-center gap-4">
              <div className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">
                {initialsOf(employee.full_name)}
                <PermissionGate module="hr" hideWhen={employee.is_archived}>
                  <button
                    type="button"
                    onClick={() => pictureInputRef.current?.click()}
                    title={employee.profile_picture_document_id ? t('action.employeeDetail.replacePicture') : t('action.employeeDetail.uploadPicture')}
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
                {employee.profile_picture_document_id && <p className="text-xs text-ink-faint">{t('option.employeeDetail.profilePictureOnFile')}</p>}
              </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
              <Field label={t('option.label.phone')} value={employee.phone ?? '—'} />
              <Field label={t('option.employeeDetail.alternatePhone')} value={employee.alternate_phone ?? '—'} />
              {employee.payment_account_number !== undefined && (
                <Field
                  label={t('option.employeeDetail.paymentAccountNumber')}
                  value={
                    employee.payment_account_number ? (
                      <span>
                        {employee.payment_account_number}
                        <span className="block text-xs text-ink-muted">
                          {employee.payment_account_source === 'manual' ? t('option.employeeDetail.paymentSourceManual') : employee.payment_account_source ? t('option.employeeDetail.paymentSourceImported') : t('status.pipeline.dayRecorded')}
                          {employee.payment_account_register_check === 'mismatch' && ` · ${t('option.employeeDetail.paymentMismatch')}`}
                          {employee.payment_account_register_check === 'accepted' && ` · ${t('option.employeeDetail.paymentMismatchAccepted')}`}
                        </span>
                      </span>
                    ) : (
                      <span className="text-ink-muted">{t('option.employeeDetail.paymentNotRecorded')}</span>
                    )
                  }
                />
              )}
              <Field label={t('option.label.location')} value={employee.location ?? '—'} />
              <Field label={t('option.label.gender')} value={employee.gender ? tValue('option.gender', employee.gender) ?? employee.gender[0].toUpperCase() + employee.gender.slice(1) : t('option.label.notRecorded')} />
              <Field label={t('option.label.age')} value={employee.age?.toString() ?? '—'} />
              <Field label={t('option.employeeDetail.maritalStatus')} value={employee.marital_status ?? '—'} />
              <Field label={t('option.employeeDetail.livesWith')} value={employee.lives_with ?? '—'} />
              <Field label={t('option.employeeDetail.referenceGuarantor')} value={employee.reference_name ?? '—'} />
              <Field label={t('option.employeeDetail.secondaryContactName')} value={employee.secondary_contact_name ?? '—'} />
              <Field label={t('option.employeeDetail.secondaryContactPhone')} value={employee.secondary_contact_phone ?? '—'} />
              <Field label={t('option.employeeDetail.employmentType')} value={employee.employment_type === 'temporary' ? t('option.employmentType.temporary') : t('option.employmentType.permanent')} />
              <Field label={t('option.label.category')} value={employee.employee_category_name ?? '—'} />
              <Field label={t('option.employeeDetail.categorySpecialization')} value={employee.category_specialization ?? '—'} />
              <Field label={t('option.label.department')} value={employee.department_name ?? '—'} />
              <Field label={t('option.label.position')} value={employee.position_name ?? '—'} />
              <Field label={t('option.employeeDetail.applicationDate')} value={formatDate(employee.application_date)} />
              <Field label={t('option.label.source')} value={employee.source ?? '—'} />
              <Field
                label={t('option.employeeDetail.currentSalary')}
                value={employee.current_salary ? `${employee.current_salary.amount} ${employee.current_salary.currency} / ${tValue('option.frequency', employee.current_salary.frequency) ?? employee.current_salary.frequency}` : '—'}
              />
            </dl>

            {employee.experience && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">{t('option.employeeDetail.experience')}</p>
                <p className="text-sm text-ink">{employee.experience}</p>
              </div>
            )}
            {employee.notes && (
              <div className="mt-4 border-t border-border pt-4">
                <p className="mb-1 text-xs font-medium text-ink-faint">{t('option.label.notes')}</p>
                <p className="text-sm text-ink">{employee.notes}</p>
              </div>
            )}

            <div className="mt-4 border-t border-border pt-4">
              <div className="mb-1.5 flex items-center gap-2">
                <p className="text-xs font-medium text-ink-faint">{t('option.employeeDetail.profileCompleteness')}</p>
                {employee.profile_complete ? (
                  <StatusBadge status="complete" tone="success" label={t('status.employeeDetail.complete')} />
                ) : (
                  <StatusBadge status="incomplete" tone="warning" label={t('status.employeeDetail.incomplete')} />
                )}
              </div>
              {missingProfileFields.length > 0 ? (
                <p className="text-sm text-ink-muted">{t('option.employeeDetail.missingFields', { fields: missingProfileFields.join(', ') })}</p>
              ) : (
                <p className="text-sm text-ink-muted">
                  {t('option.employeeDetail.allCoreOnRecord')}
                  {!employee.profile_complete && ` ${t('option.employeeDetail.notYetMarkedComplete')}`}
                </p>
              )}
            </div>

            {employee.registration_workflow && (
              <div className="mt-6 border-t border-border pt-5" data-testid="registration-workflow">
                <div className="mb-2 flex items-center gap-2">
                  <p className="text-xs font-medium text-ink-faint">{t('option.employeeDetail.registration')}</p>
                  <StatusBadge
                    status={employee.registration_workflow.origin}
                    label={employee.registration_workflow.origin_label}
                    tone={employee.registration_workflow.applies ? 'warning' : 'info'}
                  />
                </div>
                {employee.registration_workflow.applies ? (
                  <>
                    <ul className="space-y-1 text-sm">
                      {employee.registration_workflow.steps.map((step) => (
                        <li key={step.key} className={step.done ? 'text-success' : 'text-ink-muted'} data-testid={`registration-step-${step.key}`}>
                          {step.done ? '✓' : '○'} {step.label}
                          <span className="sr-only"> {step.done ? t('option.employeeDetail.stepDone') : t('option.employeeDetail.stepNotDone')}</span>
                        </li>
                      ))}
                    </ul>
                    {employee.registration_workflow.complete ? (
                      <p className="mt-2 text-xs text-ink-muted">{t('warning.employeeDetail.registrationComplete')}</p>
                    ) : employee.registration_workflow.override_recorded ? (
                      <p className="mt-2 text-xs text-ink-muted">{t('warning.employeeDetail.registrationOverridden')}</p>
                    ) : (
                      <p className="mt-2 text-xs text-ink-muted">
                        {t('warning.employeeDetail.registrationRule')}
                      </p>
                    )}
                  </>
                ) : (
                  <p className="text-sm text-ink-muted">
                    {employee.registration_workflow.origin === 'existing'
                      ? t('warning.employeeDetail.registrationExisting')
                      : t('warning.employeeDetail.registrationOfficeStaff')}
                  </p>
                )}
              </div>
            )}

            <PermissionGate module="hr" hideWhen={employee.is_archived}>
              <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-border pt-5">
                {employee.status !== 'applicant' && nextStatus && (
                  <Button size="sm" isLoading={statusMutation.isPending} disabled={advanceBlocked} onClick={() => statusMutation.mutate(nextStatus)}
                    title={advanceBlocked ? t('warning.employeeDetail.completeRegistrationFirst') : undefined}>
                    {t('action.advanceTo', { status: tValue('status', nextStatus) ?? nextStatus[0].toUpperCase() + nextStatus.slice(1) })}
                  </Button>
                )}
                {canMoveStage && (
                  <Button variant="outline" size="sm" onClick={() => setIsMoveStageOpen(true)}>
                    {t('action.moveToStageMore')}
                  </Button>
                )}
                {employee.status === 'active' && (
                  <Button
                    variant="outline"
                    size="sm"
                    isLoading={supervisorMutation.isPending}
                    onClick={() => supervisorMutation.mutate(!employee.is_supervisor)}
                  >
                    {employee.is_supervisor ? t('action.removeFromSupervisorPool') : t('action.addToSupervisorPool')}
                  </Button>
                )}
                {employee.status === 'inactive' && (
                  <Button size="sm" onClick={() => setIsRehireOpen(true)}>
                    {t('action.rehire')}
                  </Button>
                )}
                {employee.status !== 'inactive' && (
                  <Button variant="danger" size="sm" onClick={() => setIsSeparateOpen(true)}>
                    {t('action.markInactive')}
                  </Button>
                )}
                {canOverrideStatus && (
                  <Button variant="outline" size="sm" onClick={() => setIsOverrideOpen(true)}>
                    {t('action.overrideStatusMore')}
                  </Button>
                )}
              </div>
              {employee.status === 'applicant' && (
                <p className="mt-3 border-t border-border pt-3 text-xs text-ink-faint">
                  {t('warning.employeeDetail.applicantProgression')}
                </p>
              )}
            </PermissionGate>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.workAssignments')}</h3>
              {employee.status === 'active' && (
                <PermissionGate module="hr" hideWhen={employee.is_archived}>
                  <Button size="sm" variant="outline" onClick={() => setIsAssignOpen(true)}>
                    <Icon name="plus" size={14} />
                    {t('action.assign')}
                  </Button>
                </PermissionGate>
              )}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.status !== 'active' && (
                <p className="rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
                  {t('warning.employeeDetail.assignmentsActiveOnly')}
                </p>
              )}
              {employee.active_work_assignments && employee.active_work_assignments.length > 0 ? (
                employee.active_work_assignments.map((assignment) => (
                  <div key={assignment.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between">
                      <p className="text-sm font-medium text-ink">{assignmentLabel(assignment)}</p>
                      <StatusBadge status={assignment.status} label={tValue('status', assignment.status)} tone="success" />
                    </div>
                    {isWorkplaceMissing(assignment) && (
                      <p className="mt-1 text-xs italic text-warning">{t('option.workplaceNotSet')}</p>
                    )}
                    <p className="mt-1 text-xs text-ink-muted">
                      {assignment.position_name ?? t('option.employeeDetail.noPositionSet')} · {assignment.salary_amount} {assignment.salary_currency} / {tValue('option.frequency', assignment.salary_frequency) ?? assignment.salary_frequency}{assignment.shift ? ` · ${assignment.shift === 'night' ? t('option.shift.night') : t('option.shift.day')}` : ''}
                    </p>
                    <p className="mt-1 text-xs text-ink-faint">{t('option.employeeDetail.since', { date: formatDate(assignment.start_date) })}</p>
                    <PermissionGate module="hr" hideWhen={employee.is_archived}>
                      <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                        {isWorkplaceMissing(assignment) && assignment.client_company_id !== null && (
                          <button
                            type="button"
                            onClick={() => setSettingWorkplaceFor(assignment)}
                            className="text-xs font-medium text-primary hover:underline"
                          >
                            {t('action.setWorkplace')}
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setChangingSalaryFor(assignment)}
                          className="text-xs font-medium text-primary hover:underline"
                        >
                          {t('action.employeeDetail.changeSalary')}
                        </button>
                        <button
                          type="button"
                          onClick={() => setEndingAssignment(assignment)}
                          className="text-xs font-medium text-danger hover:underline"
                        >
                          {t('action.employeeDetail.endAssignmentLink')}
                        </button>
                      </div>
                    </PermissionGate>
                  </div>
                ))
              ) : (
                <EmptyState icon="briefcase" title={t('option.employeeDetail.emptyNoActiveAssignment')} />
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.assignmentHistory')}</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.work_assignments && employee.work_assignments.filter((a) => a.status !== 'active').length > 0 ? (
                employee.work_assignments
                  .filter((a) => a.status !== 'active')
                  .map((assignment) => (
                    <div key={assignment.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                      <p className="text-sm text-ink">{assignmentLabel(assignment)}</p>
                      <p className="mt-1 text-xs text-ink-faint">
                        {formatDate(assignment.start_date)} → {assignment.end_date ? formatDate(assignment.end_date) : t('option.employeeDetail.present')}
                      </p>
                    </div>
                  ))
              ) : (
                <EmptyState icon="list" title={t('option.employeeDetail.emptyNoPastAssignments')} />
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.statusHistory')}</h3>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.status_histories && employee.status_histories.length > 0 ? (
                employee.status_histories.map((entry) => (
                  <div key={entry.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center gap-2">
                      <StatusBadge status={entry.to_status} label={tValue('status', entry.to_status)} />
                      <span className="text-xs text-ink-faint">{formatDateTime(entry.created_at)}</span>
                    </div>
                    {entry.is_override && (
                      <div className="mt-1">
                        <StatusBadge status="override" tone="warning" label={t('status.employeeDetail.superAdminOverride')} />
                        <p className="mt-1 text-xs text-ink-muted">{t('option.label.reasonColon')} {entry.override_reason}</p>
                      </div>
                    )}
                    {entry.note && <p className="mt-1 text-xs text-ink-muted">{entry.note}</p>}
                    <p className="mt-1 text-xs text-ink-faint">{t('option.employeeDetail.byPerson', { name: entry.changed_by ?? '—' })}</p>
                  </div>
                ))
              ) : (
                <EmptyState icon="list" title={t('option.employeeDetail.emptyNoHistory')} />
              )}
            </CardBody>
          </Card>

          {employee.separations && employee.separations.length > 0 && (
            <Card>
              <CardHeader>
                <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.separationHistory')}</h3>
              </CardHeader>
              <CardBody className="space-y-3">
                {employee.separations.map((separation) => (
                  <div key={separation.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center gap-2">
                      <StatusBadge status={separation.reason} label={tValue('option.separation', separation.reason) ?? separation.reason_label} tone="danger" />
                      <span className="text-xs text-ink-faint">{formatDate(separation.separation_date)}</span>
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      {separation.rehire_eligible ? t('option.employeeDetail.fieldRehireEligible') : t('option.employeeDetail.notMarkedRehireEligible')}
                    </p>
                    {separation.notes && <p className="mt-1 text-xs text-ink-muted">{separation.notes}</p>}
                    <p className="mt-1 text-xs text-ink-faint">{t('option.employeeDetail.byPerson', { name: separation.separated_by ?? '—' })}</p>
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
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.attendanceHistory')}</h3>
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.attendances && employee.attendances.length > 0 ? (
              employee.attendances.map((attendance) => (
                <div key={attendance.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <StatusBadge
                      status={attendance.status}
                      label={tValue('option.attendance', attendance.status) ?? ATTENDANCE_STATUS_LABEL[attendance.status]}
                      tone={ATTENDANCE_STATUS_TONE[attendance.status]}
                    />
                    <span className="text-xs text-ink-faint">{formatDate(attendance.date)}</span>
                  </div>
                  {attendance.notes && <p className="mt-1 text-xs text-ink-muted">{attendance.notes}</p>}
                </div>
              ))
            ) : (
              <EmptyState icon="calendar" title={t('option.employeeDetail.emptyNoAttendance')} />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.leaveHistory')}</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                <Button size="sm" variant="outline" onClick={() => setIsLeaveOpen(true)}>
                  <Icon name="plus" size={14} />
                  {t('action.recordLeave')}
                </Button>
              </PermissionGate>
            )}
          </CardHeader>
          <CardBody className="space-y-3">
            {employee.leaves && employee.leaves.length > 0 ? (
              employee.leaves.map((leave) => (
                <div key={leave.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <StatusBadge status={leave.leave_type} label={tValue('option.leaveType', leave.leave_type) ?? leave.leave_type_label} tone="warning" />
                    <span className="text-xs text-ink-faint">
                      {formatDate(leave.start_date)} → {formatDate(leave.end_date)}
                    </span>
                  </div>
                  {leave.notes && <p className="mt-1 text-xs text-ink-muted">{leave.notes}</p>}
                  {leave.leave_type === 'unpaid' && (
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                      <StatusBadge
                        status={leave.approval_status}
                        label={leave.approval_status === 'approved' ? t('status.employeeDetail.leaveApproved') : leave.approval_status === 'pending' ? t('status.employeeDetail.leavePending') : t('status.employeeDetail.leaveRejected')}
                        tone={leave.approval_status === 'approved' ? 'success' : leave.approval_status === 'pending' ? 'warning' : 'danger'}
                      />
                      {leave.decided_by && (
                        <span className="text-xs text-ink-faint">
                          {t('option.employeeDetail.byPerson', { name: leave.decided_by })}
                          {leave.decided_at ? ` ${t('option.employeeDetail.onDate', { date: formatDate(leave.decided_at) })}` : ''}
                          {leave.decision_note ? ` - ${leave.decision_note}` : ''}
                        </span>
                      )}
                      {leave.approval_status !== 'rejected' && (
                        <PermissionGate module="hr" hideWhen={employee.is_archived}>
                          {leave.approval_status === 'pending' && (
                            <Button size="sm" variant="outline" isLoading={approveLeaveMutation.isPending} onClick={() => approveLeaveMutation.mutate(leave.id)}>
                              {t('action.approve')}
                            </Button>
                          )}
                          <Button size="sm" variant="ghost" onClick={() => setRejectingLeave(leave)}>
                            {t('action.rejectLeave')}
                          </Button>
                        </PermissionGate>
                      )}
                    </div>
                  )}
                </div>
              ))
            ) : (
              <EmptyState icon="calendar" title={t('option.employeeDetail.emptyNoLeave')} />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.performanceReviews')}</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                <Button size="sm" variant="outline" onClick={() => setIsReviewOpen(true)}>
                  <Icon name="plus" size={14} />
                  {t('action.addReview')}
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
                      label={tValue('option.employeeDetail.rating', review.rating) ?? review.rating_label}
                      tone={review.rating === 'excellent' || review.rating === 'good' ? 'success' : review.rating === 'poor' ? 'danger' : 'warning'}
                    />
                    <span className="text-xs text-ink-faint">{formatDate(review.review_date)}</span>
                  </div>
                  {review.notes && <p className="mt-1 text-xs text-ink-muted">{review.notes}</p>}
                </div>
              ))
            ) : (
              <EmptyState icon="star" title={t('option.employeeDetail.emptyNoReviews')} />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.payments')}</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                <Button size="sm" variant="outline" onClick={() => setIsPaymentOpen(true)}>
                  <Icon name="plus" size={14} />
                  {t('action.recordPayment')}
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
              <EmptyState icon="credit-card" title={t('option.employeeDetail.emptyNoPayments')} />
            )}
          </CardBody>
        </Card>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.penaltyHistory')}</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                <Button size="sm" variant="outline" onClick={() => setIsPenaltyOpen(true)}>
                  <Icon name="plus" size={14} />
                  {t('action.recordPenalty')}
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
                  <p className="mt-0.5 text-[11px] text-ink-faint">{t('option.employeeDetail.payrollPeriodValue', { period: penalty.payroll_period })}</p>
                </div>
              ))
            ) : (
              <EmptyState icon="x" title={t('option.employeeDetail.emptyNoPenalties')} />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.advanceHistory')}</h3>
            {employee.status === 'active' && (
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                <Button size="sm" variant="outline" onClick={() => setIsAdvanceOpen(true)}>
                  <Icon name="plus" size={14} />
                  {t('action.recordAdvance')}
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
                  <p className="mt-0.5 text-[11px] text-ink-faint">{t('option.employeeDetail.payrollPeriodValue', { period: advance.payroll_period })}</p>
                </div>
              ))
            ) : (
              <EmptyState icon="credit-card" title={t('option.employeeDetail.emptyNoAdvances')} />
            )}
          </CardBody>
        </Card>

        <PayrollSummaryCard employeeId={employeeId} employee={employee} />
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.guarantorCard')}</h3>
              <div className="flex items-center gap-2">
                {employee.guarantor_needed && !employee.damiin_completed && <StatusBadge status="damiin-needed" label={t('status.employeeDetail.needed')} tone="danger" />}
                {employee.guarantor?.verified_at && <StatusBadge status="verified" label={tValue('status.employeeDetail.verification', 'verified')} tone="success" />}
              </div>
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.guarantor ? (
                <dl className="space-y-2 text-sm">
                  <Field label={t('option.label.name')} value={employee.guarantor.guarantor_name ?? '—'} />
                  <Field label={t('option.label.phone')} value={employee.guarantor.guarantor_phone ?? '—'} />
                  <Field label={t('option.employeeDetail.relationship')} value={employee.guarantor.relationship ?? '—'} />
                  <Field label={t('option.label.verified')} value={employee.guarantor.verified_at ? formatDateTime(employee.guarantor.verified_at) : t('option.employeeDetail.notYet')} />
                </dl>
              ) : (
                <EmptyState icon="users" title={t('option.employeeDetail.emptyNoGuarantor')} />
              )}
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                {!employee.guarantor?.verified_at && (employee.guarantor_needed || employee.pipeline_stage === 'damiin_needed') && (
                  <div className="space-y-2 border-t border-border pt-3">
                    <input placeholder={t('option.employeeDetail.guarantorName')} className={inputClasses} value={guarantorForm.guarantor_name} onChange={(e) => setGuarantorForm({ ...guarantorForm, guarantor_name: e.target.value })} />
                    <input placeholder={t('option.employeeDetail.guarantorPhone')} className={inputClasses} value={guarantorForm.guarantor_phone} onChange={(e) => setGuarantorForm({ ...guarantorForm, guarantor_phone: e.target.value })} />
                    <input placeholder={t('option.employeeDetail.relationship')} className={inputClasses} value={guarantorForm.relationship} onChange={(e) => setGuarantorForm({ ...guarantorForm, relationship: e.target.value })} />
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" isLoading={guarantorSaveMutation.isPending} disabled={!guarantorForm.guarantor_name || !guarantorForm.guarantor_phone} onClick={() => guarantorSaveMutation.mutate()}>
                        {t('action.save')}
                      </Button>
                      {employee.guarantor && (
                        <Button size="sm" isLoading={guarantorVerifyMutation.isPending} onClick={() => guarantorVerifyMutation.mutate()}>
                          {t('action.verify')}
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
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.contractCard')}</h3>
              {employee.current_contract && <StatusBadge status={employee.current_contract.status} label={tValue('status.employeeDetail.contract', employee.current_contract.status)} />}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.current_contract ? (
                <dl className="space-y-2 text-sm">
                  <Field label={t('option.label.type')} value={employee.current_contract.contract_type} />
                  <Field label={t('option.employeeDetail.signedDate')} value={employee.current_contract.signed_date ? formatDate(employee.current_contract.signed_date) : t('option.employeeDetail.notSigned')} />
                </dl>
              ) : (
                <EmptyState icon="file-text" title={t('option.employeeDetail.emptyNoContract')} />
              )}
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                {!employee.current_contract && employee.pipeline_stage === 'contract_pending' && (
                  <Button size="sm" isLoading={contractCreateMutation.isPending} onClick={() => contractCreateMutation.mutate()}>
                    {t('action.issueContract')}
                  </Button>
                )}
                {employee.current_contract && employee.current_contract.status === 'issued' && (
                  <div className="space-y-2 border-t border-border pt-3">
                    <input type="date" className={inputClasses} value={signedDate} onChange={(e) => setSignedDate(e.target.value)} />
                    <Button size="sm" isLoading={contractSignMutation.isPending} onClick={() => contractSignMutation.mutate(employee.current_contract!.id)}>
                      {t('action.markSigned')}
                    </Button>
                  </div>
                )}
              </PermissionGate>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.uniformCard')}</h3>
              {employee.uniform && <StatusBadge status={employee.uniform.status} label={tValue('status.employeeDetail.uniform', employee.uniform.status)} tone={employee.uniform.status === 'confirmed' ? 'success' : 'warning'} />}
            </CardHeader>
            <CardBody className="space-y-3">
              {employee.uniform ? (
                <dl className="space-y-2 text-sm">
                  <Field label={t('status.employeeDetail.uniform.confirmed')} value={employee.uniform.confirmed_at ? formatDateTime(employee.uniform.confirmed_at) : t('option.employeeDetail.notYet')} />
                </dl>
              ) : (
                <EmptyState icon="box" title={t('option.employeeDetail.emptyNoUniform')} />
              )}
              <PermissionGate module="hr" hideWhen={employee.is_archived}>
                {employee.pipeline_stage === 'uniform_pending' && (
                  <div className="flex flex-wrap gap-2 border-t border-border pt-3">
                    <Button size="sm" variant="outline" isLoading={uniformUpdateMutation.isPending} onClick={() => uniformUpdateMutation.mutate('purchased')}>
                      {t('action.markPurchased')}
                    </Button>
                    <Button size="sm" variant="outline" isLoading={uniformUpdateMutation.isPending} onClick={() => uniformUpdateMutation.mutate('received')}>
                      {t('action.markReceived')}
                    </Button>
                    <Button size="sm" isLoading={uniformConfirmMutation.isPending} onClick={() => uniformConfirmMutation.mutate()}>
                      {t('action.confirmUniform')}
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
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.historicalCompletion')}</h3>
              <span className="rounded-full bg-secondary-soft px-2.5 py-1 text-xs font-semibold text-secondary">{t('option.employeeDetail.historicalBadge')}</span>
            </CardHeader>
            <CardBody>
              <p className="mb-3 text-xs text-ink-muted">
                {t('warning.employeeDetail.historicalNote')}
              </p>
              <ul className="space-y-1.5 text-sm">
                {employee.historical_completions.map((completion) => (
                  <li key={completion.id} className="flex items-center gap-2 text-ink">
                    <Icon name="check" size={14} className="text-secondary" />
                    <span className="font-medium capitalize">{completion.stage}</span>
                    <span className="text-ink-muted">{t('option.employeeDetail.completedHistorically')}</span>
                  </li>
                ))}
              </ul>
              <p className="mt-3 text-xs text-ink-faint">{t('option.employeeDetail.sourceValue', { source: employee.historical_completions[0]?.source_reference ?? t('option.employeeDetail.excelMigration') })}</p>
            </CardBody>
          </Card>
        </div>
      )}

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.practicalAssessments')}</h3>
          </CardHeader>
          <CardBody>
            {employee.practical_assessments && employee.practical_assessments.length > 0 ? (
              <div className="mb-4 space-y-3">
                {[...employee.practical_assessments].reverse().map((a) => (
                  <div key={a.id} className="flex items-start justify-between border-b border-border pb-3 last:border-0">
                    <div>
                      <div className="flex items-center gap-2">
                        {a.attempt_number && <span className="text-xs font-semibold text-ink-faint">{t('option.employeeDetail.attemptNumber', { n: a.attempt_number })}</span>}
                        <StatusBadge
                          status={a.result}
                          label={tValue('status.employeeDetail.assessment', a.result)}
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
              <EmptyState icon="check" title={t('option.employeeDetail.emptyNoPracticalDecisions')} />
            )}
            <PermissionGate module="hr" hideWhen={employee.is_archived}>
              {(employee.pipeline_stage === 'need_practical' || employee.pipeline_stage === 'practical_repeat') && (
                <div className="space-y-2 border-t border-border pt-4">
                  <Select
                    value={practicalBatchId}
                    onChange={(e) => setPracticalBatchId(e.target.value)}
                    placeholder={t('option.practicalBatchOptional')}
                    options={(practicalBatches ?? []).map((b) => ({ value: String(b.id), label: `${b.batch_number} — ${formatDate(b.batch_date)}` }))}
                  />
                  <input
                    type="text"
                    placeholder={t('option.employeeDetail.notesOptional')}
                    value={assessmentNotes}
                    onChange={(e) => setAssessmentNotes(e.target.value)}
                    className={inputClasses}
                  />
                  <div className="flex flex-wrap gap-2">
                    {DECISION_OPTIONS.map((option) => (
                      <Button
                        key={option.value}
                        size="sm"
                        variant={option.tone === 'success' ? 'primary' : option.tone === 'danger' ? 'danger' : 'outline'}
                        isLoading={decisionMutation.isPending && decisionMutation.variables === option.value}
                        disabled={decisionMutation.isPending}
                        onClick={() => decisionMutation.mutate(option.value)}
                      >
                        {t(option.labelKey)}
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
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.trainingHistory')}</h3>
          </CardHeader>
          <CardBody>
            {employee.training_history && employee.training_history.length > 0 ? (
              <div className="space-y-3">
                {employee.training_history.map((training) => (
                  <div key={training.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-ink">{training.batch_number ?? t('option.employeeDetail.batchNumber', { id: training.training_batch_id })}</span>
                      <StatusBadge
                        status={training.result}
                        label={tValue('status.employeeDetail.training', training.result) ?? training.result[0].toUpperCase() + training.result.slice(1)}
                        tone={training.result === 'completed' ? 'success' : training.result === 'absent' ? 'danger' : 'warning'}
                      />
                    </div>
                    <p className="mt-1 text-xs text-ink-faint">
                      {formatDate(training.batch_date)}
                      {training.start_time && training.end_time ? ` · ${training.start_time}–${training.end_time}` : ''}
                      {training.team_or_group ? ` · ${training.team_or_group}` : ''}
                    </p>
                    {(training.trainer_name || training.location) && (
                      <p className="text-xs text-ink-faint">
                        {training.trainer_name ? t('option.employeeDetail.trainerValue', { name: training.trainer_name }) : ''}
                        {training.trainer_name && training.location ? ' · ' : ''}
                        {training.location ? t('option.employeeDetail.locationValue', { location: training.location }) : ''}
                      </p>
                    )}
                    {training.notes && <p className="mt-1 text-xs text-ink-muted">{training.notes}</p>}
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="list" title={t('option.employeeDetail.emptyNoTraining')} />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.documents')}</h3>
            <PermissionGate module="hr" hideWhen={employee.is_archived}>
              <Button size="sm" variant="outline" onClick={() => fileInputRef.current?.click()} isLoading={uploadMutation.isPending}>
                <Icon name="plus" size={14} />
                {t('action.upload')}
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
            <PermissionGate module="hr" hideWhen={employee.is_archived}>
              <Select
                value={documentCategoryId}
                onChange={(e) => setDocumentCategoryId(e.target.value)}
                placeholder={t('option.employeeDetail.categoryForUpload')}
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
                        {!doc.is_current && <span className="text-[10px] uppercase text-ink-faint">{t('status.employeeDetail.superseded')}</span>}
                      </div>
                      <div className="ml-6 mt-0.5 flex items-center gap-1.5 text-xs text-ink-faint">
                        <span>{doc.category_name ?? t('option.employeeDetail.noCategory')}</span>
                        <StatusBadge
                          status={doc.verification_status}
                          label={tValue('status.employeeDetail.verification', doc.verification_status)}
                          tone={doc.verification_status === 'verified' ? 'success' : doc.verification_status === 'rejected' ? 'danger' : 'neutral'}
                        />
                      </div>
                    </div>
                    <PermissionGate module="hr" hideWhen={employee.is_archived}>
                      <div className="flex shrink-0 items-center gap-2">
                        {canDownloadDocuments && (
                          <button
                            type="button"
                            aria-label={t('action.employeeDetail.downloadFile', { name: doc.file_name })}
                            disabled={downloadDocumentMutation.isPending}
                            onClick={() => downloadDocumentMutation.mutate(doc)}
                            className="text-xs font-medium text-primary hover:underline disabled:opacity-50"
                          >
                            {t('action.download')}
                          </button>
                        )}
                        {canChangeDocumentCategory && doc.is_current && employee.profile_picture_document_id !== doc.id && (
                          <button
                            type="button"
                            aria-label={t('action.employeeDetail.changeCategoryOf', { name: doc.file_name })}
                            onClick={() => {
                              setRecategorizeError(null);
                              setRecategorizeTo(doc.employee_document_category_id ? String(doc.employee_document_category_id) : '');
                              setRecategorizing(doc);
                            }}
                            className="text-xs font-medium text-primary hover:underline"
                          >
                            {t('action.employeeDetail.category')}
                          </button>
                        )}
                        {doc.is_current && doc.verification_status === 'pending' && (
                          <button type="button" onClick={() => verifyDocumentMutation.mutate(doc.id)} className="text-xs font-medium text-primary hover:underline">
                            {t('action.verify')}
                          </button>
                        )}
                        <button
                          type="button"
                          title={t('action.employeeDetail.removeMistakenUpload')}
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
              <EmptyState icon="file-text" title={t('option.employeeDetail.emptyNoDocuments')} />
            )}
          </CardBody>
        </Card>
      </div>

      <Modal
        isOpen={recategorizing !== null}
        onClose={closeRecategorize}
        title={t('confirm.employeeDetail.changeCategoryTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={closeRecategorize} disabled={recategorizeMutation.isPending}>
              {t('action.cancel')}
            </Button>
            <Button
              size="sm"
              isLoading={recategorizeMutation.isPending}
              disabled={!recategorizeTo || (recategorizing !== null && String(recategorizing.employee_document_category_id ?? '') === recategorizeTo)}
              onClick={() => recategorizing && recategorizeMutation.mutate({ documentId: recategorizing.id, categoryId: Number(recategorizeTo) })}
            >
              {t('action.employeeDetail.saveCategory')}
            </Button>
          </>
        }
      >
        <p className="mb-3 text-sm text-ink-muted">
          {t('confirm.employeeDetail.changeCategoryBody', { name: recategorizing?.file_name ?? '' })}
        </p>
        <FormField label={t('action.employeeDetail.category')} htmlFor="doc-recategory" required>
          <Select
            id="doc-recategory"
            value={recategorizeTo}
            onChange={(e) => setRecategorizeTo(e.target.value)}
            placeholder={t('option.employeeDetail.chooseCategory')}
            options={(documentCategories ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
          />
        </FormField>
        {recategorizeError && (
          <p role="alert" className="mt-3 rounded-sm bg-danger-soft px-3 py-2 text-sm text-danger">
            {recategorizeError}
          </p>
        )}
      </Modal>

      <EmployeeFormDialog isOpen={isEditOpen} onClose={() => setIsEditOpen(false)} mode="edit" employee={employee} />
      {employee.is_archived && <RestoreEmployeeDialog isOpen={isRestoreOpen} employee={employee} onClose={() => setIsRestoreOpen(false)} onDone={invalidate} />}

      <AssignWorkLocationModal
        isOpen={isAssignOpen}
        employeeId={employeeId}
        onClose={() => setIsAssignOpen(false)}
        onSaved={() => {
          invalidate();
          setIsAssignOpen(false);
        }}
      />

      {settingWorkplaceFor && (
        <SetWorkplaceModal
          assignment={settingWorkplaceFor}
          onClose={() => setSettingWorkplaceFor(null)}
          onSaved={() => {
            invalidate();
            setSettingWorkplaceFor(null);
          }}
        />
      )}

      {changingSalaryFor && (
        <ChangeSalaryModal
          assignment={changingSalaryFor}
          onClose={() => setChangingSalaryFor(null)}
          onSaved={() => {
            invalidate();
            setChangingSalaryFor(null);
          }}
        />
      )}

      {endingAssignment && (
        <EndAssignmentModal
          assignment={endingAssignment}
          onClose={() => setEndingAssignment(null)}
          onConfirm={(endDate, note, successor) => endAssignmentMutation.mutate({ id: endingAssignment.id, end_date: endDate, note, successor })}
          isLoading={endAssignmentMutation.isPending}
        />
      )}

      {canMoveStage && <MoveStageDialog isOpen={isMoveStageOpen} employee={employee} onClose={() => setIsMoveStageOpen(false)} onDone={invalidate} />}

      {canOverrideStatus && (
        <StatusOverrideDialog isOpen={isOverrideOpen} employee={employee} onClose={() => setIsOverrideOpen(false)} onDone={invalidate} />
      )}

      <MarkSeparatedModal
        isOpen={isSeparateOpen}
        permanentAssignments={(employee.active_work_assignments ?? []).filter((a) => a.status === 'active' && needsSuccessor(a.assignment_type))}
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

      <Modal
        isOpen={rejectingLeave !== null}
        onClose={() => setRejectingLeave(null)}
        title={t('confirm.employeeDetail.rejectLeaveTitle')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setRejectingLeave(null)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={rejectLeaveMutation.isPending} disabled={rejectNote.trim().length < 5} onClick={() => rejectLeaveMutation.mutate()}>
              {t('action.rejectLeave')}
            </Button>
          </>
        }
      >
        <p className="mb-3 text-xs text-ink-muted">{t('confirm.employeeDetail.rejectLeaveBody')}</p>
        <FormField label={t('option.employeeDetail.fieldReasonMin5')} htmlFor="reject-leave-note" required>
          <textarea id="reject-leave-note" rows={3} className={inputClasses + ' h-auto py-2'} value={rejectNote} onChange={(e) => setRejectNote(e.target.value)} />
        </FormField>
      </Modal>

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

function Field({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-ink-faint">{label}</dt>
      <dd className="text-ink">{value}</dd>
    </div>
  );
}

const FREQUENCY_OPTIONS: { value: SalaryFrequency; labelKey: MessageKey }[] = [
  { value: 'monthly', labelKey: 'option.frequency.monthly' },
  { value: 'weekly', labelKey: 'option.frequency.weekly' },
  { value: 'daily', labelKey: 'option.frequency.daily' },
];

/** Value of the "company" choice that stands for the Fayadhowr Office (which has no client company). */
const OFFICE_CHOICE = 'office';

/**
 * Assign an employee to a company (or the Fayadhowr Office). The workplace is
 * optional - HR can pick one now or add it later from the employee page.
 */
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
  const { t } = useI18n();
  const { show } = useToast();
  const { isSuperAdmin } = useAuth();
  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list, enabled: isOpen });
  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list(), enabled: isOpen });

  const [companyChoice, setCompanyChoice] = useState('');
  const [workLocationId, setWorkLocationId] = useState('');
  const [startDate, setStartDate] = useState(localToday());
  const [salaryAmount, setSalaryAmount] = useState('');
  const [salaryCurrency, setSalaryCurrency] = useState('USD');
  const [salaryFrequency, setSalaryFrequency] = useState<SalaryFrequency>('monthly');
  const [notes, setNotes] = useState('');
  const [overrideCapacity, setOverrideCapacity] = useState(false);
  const [question, setQuestion] = useState<string | null>(null);

  const isOffice = companyChoice === OFFICE_CHOICE;
  const office = (locations ?? []).find((l) => l.location_type === 'office');
  const companyLocations = (locations ?? []).filter(
    (l) => l.location_type === 'client' && l.status === 'active' && companyChoice !== '' && String(l.client_company_id) === companyChoice,
  );

  const assignMutation = useMutation({
    mutationFn: (confirmAdditional: boolean) =>
      hrApi.employees.workAssignments.create(employeeId, {
        // The office has no company, so it is sent on its own; a company may carry an optional workplace.
        ...(isOffice
          ? { work_location_id: office?.id ?? null }
          : { client_company_id: Number(companyChoice), work_location_id: workLocationId ? Number(workLocationId) : null }),
        start_date: startDate,
        salary_amount: Number(salaryAmount),
        salary_currency: salaryCurrency,
        salary_frequency: salaryFrequency,
        notes: notes || null,
        ...(isSuperAdmin && overrideCapacity ? { override_capacity: true } : {}),
        ...(confirmAdditional ? { confirm_additional_company: true } : {}),
      }),
    onSuccess: () => {
      show(t('notify.employeeDetail.assignmentCreated'));
      setCompanyChoice('');
      setWorkLocationId('');
      setSalaryAmount('');
      setNotes('');
      onSaved();
    },
    onError: (err) => {
      // The server asks for an explicit yes before an employee who already works for another company is given a second one.
      const asked = additionalCompanyQuestion(err);
      if (asked !== null) setQuestion(asked);
      else show(friendlyErrorMessage(err, t('notify.employeeDetail.assignmentCreateFailed')), 'error');
    },
  });

  if (!isOpen) return null;

  const companyOptions = [
    ...(office ? [{ value: OFFICE_CHOICE, label: officeLabel() }] : []),
    ...(companies ?? [])
      .filter((c) => c.status === 'active')
      .map((c) => ({ value: String(c.id), label: c.category === 'guri' ? `${c.name} (${t('option.company.filterGuri')})` : c.name })),
  ];

  return (
    <>
    <ConfirmDialog
      isOpen={question !== null}
      title={t('confirm.employeeDetail.additionalCompanyTitle')}
      description={question ?? ''}
      confirmLabel={t('action.assignBoth')}
      isLoading={assignMutation.isPending}
      onCancel={() => setQuestion(null)}
      onConfirm={() => {
        setQuestion(null);
        assignMutation.mutate(true);
      }}
    />
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.employeeDetail.assignTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            size="sm"
            isLoading={assignMutation.isPending}
            disabled={companyChoice === '' || salaryAmount === ''}
            onClick={() => assignMutation.mutate(false)}
          >
            {t('action.save')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldCompany')} htmlFor="wa-company" required>
        <Select
          id="wa-company"
          value={companyChoice}
          onChange={(e) => {
            setCompanyChoice(e.target.value);
            setWorkLocationId('');
          }}
          placeholder={t('option.selectCompany')}
          options={companyOptions}
        />
      </FormField>
      {!isOffice && (
        <FormField
          label={t('option.employeeDetail.fieldWorkLocationOptional')}
          htmlFor="wa-location"
          hint={
            companyChoice !== '' && companyLocations.length === 0
              ? t('warning.employeeDetail.noWorkplacesYetLater')
              : t('warning.employeeDetail.addWorkplaceLater')
          }
        >
          <Select
            id="wa-location"
            value={workLocationId}
            onChange={(e) => setWorkLocationId(e.target.value)}
            placeholder={companyChoice === '' ? t('option.employeeDetail.selectCompanyFirst') : t('option.workplaceNotSet')}
            options={companyLocations.map((l) => ({ value: String(l.id), label: l.name }))}
            disabled={companyChoice === ''}
          />
        </FormField>
      )}
      <FormField label={t('option.employeeDetail.fieldStartDate')} htmlFor="wa-start" required>
        <input id="wa-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-3 gap-2">
        <FormField label={t('option.employeeDetail.fieldSalary')} htmlFor="wa-salary" required>
          <input id="wa-salary" type="number" min={0} className={inputClasses} value={salaryAmount} onChange={(e) => setSalaryAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor="wa-currency" required>
          <input id="wa-currency" maxLength={3} className={inputClasses} value={salaryCurrency} onChange={(e) => setSalaryCurrency(e.target.value.toUpperCase())} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldFrequency')} htmlFor="wa-frequency" required>
          <Select id="wa-frequency" value={salaryFrequency} onChange={(e) => setSalaryFrequency(e.target.value as SalaryFrequency)} options={translatedOptions(FREQUENCY_OPTIONS, t)} />
        </FormField>
      </div>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="wa-notes">
        <textarea id="wa-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      {isSuperAdmin && (
        <label className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={overrideCapacity} onChange={(e) => setOverrideCapacity(e.target.checked)} />
          {t('warning.employeeDetail.overrideCapacity')}
        </label>
      )}
    </Modal>
    </>
  );
}

/** Adds the workplace to an assignment that was created without one. The server allows this only once. */
function SetWorkplaceModal({
  assignment,
  onClose,
  onSaved,
}: {
  assignment: WorkAssignment;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const { show } = useToast();
  const { isSuperAdmin } = useAuth();
  const { data: locations, isLoading } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });
  const [workLocationId, setWorkLocationId] = useState('');
  const [overrideCapacity, setOverrideCapacity] = useState(false);

  const companyLocations = (locations ?? []).filter(
    (l) => l.location_type === 'client' && l.status === 'active' && l.client_company_id === assignment.client_company_id,
  );

  const saveMutation = useMutation({
    mutationFn: () =>
      hrApi.employees.workAssignments.update(assignment.id, {
        work_location_id: Number(workLocationId),
        ...(isSuperAdmin && overrideCapacity ? { override_capacity: true } : {}),
      }),
    onSuccess: () => {
      show(t('notify.employeeDetail.workplaceSet'));
      onSaved();
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.employeeDetail.workplaceSetFailed')), 'error'),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.setWorkplace')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={saveMutation.isPending} disabled={workLocationId === ''} onClick={() => saveMutation.mutate()}>
            {t('action.employeeDetail.saveWorkplace')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{assignmentLabel(assignment)}</p>
      <FormField
        label={t('option.employeeDetail.fieldWorkplace')}
        htmlFor="sw-location"
        required
        hint={t('confirm.employeeDetail.setWorkplaceHint')}
      >
        <Select
          id="sw-location"
          value={workLocationId}
          onChange={(e) => setWorkLocationId(e.target.value)}
          placeholder={isLoading ? t('option.label.loading') : t('option.employeeDetail.selectWorkplace')}
          options={companyLocations.map((l) => ({ value: String(l.id), label: l.name }))}
        />
      </FormField>
      {!isLoading && companyLocations.length === 0 && (
        <p className="mb-3 text-xs text-ink-muted">{t('warning.employeeDetail.noWorkplacesAddFirst')}</p>
      )}
      {isSuperAdmin && (
        <label className="flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={overrideCapacity} onChange={(e) => setOverrideCapacity(e.target.checked)} />
          {t('warning.employeeDetail.overrideCapacity')}
        </label>
      )}
    </Modal>
  );
}

/** Changes the salary on an assignment from a chosen date; earlier salaries are kept as history. */
function ChangeSalaryModal({
  assignment,
  onClose,
  onSaved,
}: {
  assignment: WorkAssignment;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const { show } = useToast();
  const [salaryAmount, setSalaryAmount] = useState(assignment.salary_amount);
  const [salaryCurrency, setSalaryCurrency] = useState(assignment.salary_currency);
  const [salaryFrequency, setSalaryFrequency] = useState<SalaryFrequency>(assignment.salary_frequency);
  const [effectiveFrom, setEffectiveFrom] = useState(localToday());
  const [reason, setReason] = useState('');

  const saveMutation = useMutation({
    mutationFn: () =>
      hrApi.employees.workAssignments.update(assignment.id, {
        salary_amount: Number(salaryAmount),
        salary_currency: salaryCurrency,
        salary_frequency: salaryFrequency,
        salary_effective_from: effectiveFrom,
        salary_change_reason: reason || null,
      }),
    onSuccess: () => {
      show(t('notify.employeeDetail.salaryUpdated'));
      onSaved();
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.employeeDetail.salaryUpdateFailed')), 'error'),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.employeeDetail.changeSalary')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={saveMutation.isPending} disabled={salaryAmount === '' || effectiveFrom === ''} onClick={() => saveMutation.mutate()}>
            {t('action.employeeDetail.saveSalary')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{assignmentLabel(assignment)}</p>
      <div className="grid grid-cols-3 gap-2">
        <FormField label={t('option.employeeDetail.fieldNewSalary')} htmlFor="cs-salary" required>
          <input id="cs-salary" type="number" min={0} step="0.01" className={inputClasses} value={salaryAmount} onChange={(e) => setSalaryAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor="cs-currency" required>
          <input id="cs-currency" maxLength={3} className={inputClasses} value={salaryCurrency} onChange={(e) => setSalaryCurrency(e.target.value.toUpperCase())} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldFrequency')} htmlFor="cs-frequency" required>
          <Select id="cs-frequency" value={salaryFrequency} onChange={(e) => setSalaryFrequency(e.target.value as SalaryFrequency)} options={translatedOptions(FREQUENCY_OPTIONS, t)} />
        </FormField>
      </div>
      <FormField
        label={t('option.employeeDetail.fieldEffectiveFrom')}
        htmlFor="cs-effective"
        required
        hint={t('confirm.employeeDetail.effectiveFromHint')}
      >
        <input id="cs-effective" type="date" className={inputClasses} value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldReasonOptional')} htmlFor="cs-reason">
        <input id="cs-reason" className={inputClasses} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
      </FormField>
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
  onConfirm: (endDate: string, note?: string, successor?: SuccessorPayload) => void;
  isLoading: boolean;
}) {
  const { t } = useI18n();
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [note, setNote] = useState('');
  // A Permanent position cannot be left without a replacement (docs/HR_PAYROLL_RULES.md N1).
  const askSuccessor = needsSuccessor(assignment.assignment_type);
  const [successor, setSuccessor] = useState<SuccessorDraft>(EMPTY_SUCCESSOR);
  const [successorError, setSuccessorError] = useState<string | undefined>();

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.employeeDetail.endAssignmentTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            variant="danger"
            size="sm"
            isLoading={isLoading}
            onClick={() => {
              const missing = askSuccessor ? validateSuccessor(successor, endDate) : null;
              setSuccessorError(missing ?? undefined);
              if (missing === null) onConfirm(endDate, note || undefined, askSuccessor ? (toSuccessorPayload(successor, endDate) ?? undefined) : undefined);
            }}
          >
            {t('action.endAssignment')}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{assignmentLabel(assignment)}</p>
      <FormField label={t('option.employeeDetail.fieldEndDate')} htmlFor="end-date" required>
        <input id="end-date" type="date" className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </FormField>
      {askSuccessor && <SuccessorPicker assignmentId={assignment.id} lastDay={endDate} value={successor} onChange={setSuccessor} error={successorError} idPrefix="end-successor" />}
      <FormField label={t('option.employeeDetail.fieldNote')} htmlFor="end-note">
        <textarea id="end-note" rows={2} className={inputClasses + ' h-auto py-2'} value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </Modal>
  );
}

function MarkSeparatedModal({
  isOpen,
  permanentAssignments,
  onClose,
  onConfirm,
  isLoading,
}: {
  isOpen: boolean;
  /** The employee's open Permanent assignments: each needs the replacement choice (docs/HR_PAYROLL_RULES.md N1). */
  permanentAssignments: WorkAssignment[];
  onClose: () => void;
  onConfirm: (payload: MarkEmployeeSeparatedPayload) => void;
  isLoading: boolean;
}) {
  const { t } = useI18n();
  const [reason, setReason] = useState<EmployeeSeparationReason>('resigned');
  const [separationDate, setSeparationDate] = useState(new Date().toISOString().slice(0, 10));
  const [rehireEligible, setRehireEligible] = useState(true);
  const [notes, setNotes] = useState('');
  const [drafts, setDrafts] = useState<Record<number, SuccessorDraft>>({});
  const [showMissing, setShowMissing] = useState(false);
  const ids = permanentAssignments.map((a) => a.id);
  const unresolved = missingSuccessors(ids, drafts, separationDate);

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.markInactive')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            variant="danger"
            size="sm"
            isLoading={isLoading}
            onClick={() => {
              if (unresolved.length > 0) {
                setShowMissing(true);
                return;
              }
              onConfirm({
                reason,
                separation_date: separationDate,
                rehire_eligible: rehireEligible,
                notes: notes || null,
                successors: ids.map((id) => ({ assignment_id: id, ...(toSuccessorPayload(drafts[id] ?? EMPTY_SUCCESSOR, separationDate) as SuccessorPayload) })),
              });
            }}
          >
            {t('action.markInactive')}
          </Button>
        </>
      }
    >
      <p className="mb-3 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
        {t('confirm.employeeDetail.markInactiveBody')}
      </p>
      <FormField label={t('option.employeeDetail.fieldReason')} htmlFor="sep-reason" required>
        <Select id="sep-reason" value={reason} onChange={(e) => setReason(e.target.value as EmployeeSeparationReason)} options={translatedOptions(SEPARATION_REASON_OPTIONS, t)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldSeparationDate')} htmlFor="sep-date" required>
        <input id="sep-date" type="date" className={inputClasses} value={separationDate} onChange={(e) => setSeparationDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldRehireEligible')} htmlFor="sep-rehire">
        <label className="flex items-center gap-2 text-sm text-ink">
          <input id="sep-rehire" type="checkbox" checked={rehireEligible} onChange={(e) => setRehireEligible(e.target.checked)} />
          {t('option.rehireEligible')}
        </label>
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="sep-notes">
        <textarea id="sep-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
      {permanentAssignments.map((assignment) => (
        <div key={assignment.id}>
          <p className="mb-1 text-xs font-medium text-ink-muted">{assignmentLabel(assignment)}</p>
          <SuccessorPicker
            assignmentId={assignment.id}
            lastDay={separationDate}
            value={drafts[assignment.id] ?? EMPTY_SUCCESSOR}
            onChange={(next) => setDrafts((current) => ({ ...current, [assignment.id]: next }))}
            error={showMissing && unresolved.includes(assignment.id) ? (validateSuccessor(drafts[assignment.id] ?? EMPTY_SUCCESSOR, separationDate) ?? undefined) : undefined}
            idPrefix={`sep-successor-${assignment.id}`}
          />
        </div>
      ))}
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
  const { t } = useI18n();
  const [note, setNote] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.employeeDetail.rehireTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} onClick={() => onConfirm(note)}>{t('action.rehire')}</Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-ink-muted">{t('confirm.employeeDetail.rehireBody')}</p>
      <FormField label={t('option.employeeDetail.fieldNote')} htmlFor="rehire-note">
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
  const { t } = useI18n();
  const [leaveType, setLeaveType] = useState<LeaveType>('annual');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.recordLeave')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            onClick={() => onConfirm({ leave_type: leaveType, start_date: startDate, end_date: endDate, notes: notes || null })}
          >
            {t('action.record')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldLeaveType')} htmlFor="leave-type" required>
        <Select id="leave-type" value={leaveType} onChange={(e) => setLeaveType(e.target.value as LeaveType)} options={translatedOptions(LEAVE_TYPE_OPTIONS, t)} />
      </FormField>
      {leaveType === 'unpaid' && (
        <p className="mb-3 rounded-sm bg-surface-alt px-3 py-2 text-xs text-ink-muted">
          {t('warning.employeeDetail.unpaidLeaveRules')}
        </p>
      )}
      <FormField label={t('option.employeeDetail.fieldStartDate')} htmlFor="leave-start" required>
        <input id="leave-start" type="date" className={inputClasses} value={startDate} onChange={(e) => setStartDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldEndDate')} htmlFor="leave-end" required>
        <input id="leave-end" type="date" className={inputClasses} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="leave-notes">
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
  const { t } = useI18n();
  const [reviewDate, setReviewDate] = useState(new Date().toISOString().slice(0, 10));
  const [rating, setRating] = useState<PerformanceRating>('good');
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('confirm.employeeDetail.addReviewTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button size="sm" isLoading={isLoading} onClick={() => onConfirm({ review_date: reviewDate, rating, notes: notes || null })}>
            {t('action.record')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldReviewDate')} htmlFor="review-date" required>
        <input id="review-date" type="date" className={inputClasses} value={reviewDate} onChange={(e) => setReviewDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldRating')} htmlFor="review-rating" required>
        <Select id="review-rating" value={rating} onChange={(e) => setRating(e.target.value as PerformanceRating)} options={translatedOptions(PERFORMANCE_RATING_OPTIONS, t)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="review-notes">
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
  const { t } = useI18n();
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [notes, setNotes] = useState('');

  if (!isOpen) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.recordPayment')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
          <Button
            size="sm"
            isLoading={isLoading}
            disabled={!amount}
            onClick={() => onConfirm({ payment_date: paymentDate, amount: Number(amount), currency, notes: notes || null })}
          >
            {t('action.record')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldPaymentDate')} htmlFor="pay-date" required>
        <input id="pay-date" type="date" className={inputClasses} value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label={t('option.employeeDetail.fieldAmount')} htmlFor="pay-amount" required>
          <input id="pay-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor="pay-currency" required>
          <input id="pay-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="pay-notes">
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
  const { t } = useI18n();
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
      title={t('action.recordPenalty')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
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
            {t('action.record')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldPenaltyDate')} htmlFor="pen-date" required>
        <input id="pen-date" type="date" className={inputClasses} value={penaltyDate} onChange={(e) => setPenaltyDate(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldReason')} htmlFor="pen-reason" required>
        <input id="pen-reason" className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label={t('option.employeeDetail.fieldDeductionAmount')} htmlFor="pen-amount" required>
          <input id="pen-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor="pen-currency" required>
          <input id="pen-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label={t('option.employeeDetail.fieldPayrollPeriod')} htmlFor="pen-period" required>
        <input id="pen-period" type="month" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="pen-notes">
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
  const { t } = useI18n();
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
      title={t('confirm.employeeDetail.recordAdvanceTitle')}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>{t('action.cancel')}</Button>
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
            {t('action.record')}
          </Button>
        </>
      }
    >
      <FormField label={t('option.employeeDetail.fieldAdvanceDate')} htmlFor="adv-date" required>
        <input id="adv-date" type="date" className={inputClasses} value={advanceDate} onChange={(e) => setAdvanceDate(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField label={t('option.employeeDetail.fieldAmount')} htmlFor="adv-amount" required>
          <input id="adv-amount" type="number" min={0} step="0.01" className={inputClasses} value={amount} onChange={(e) => setAmount(e.target.value)} />
        </FormField>
        <FormField label={t('option.employeeDetail.fieldCurrency')} htmlFor="adv-currency" required>
          <input id="adv-currency" className={inputClasses} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} />
        </FormField>
      </div>
      <FormField label={t('option.employeeDetail.fieldPayrollPeriod')} htmlFor="adv-period" required>
        <input id="adv-period" type="month" className={inputClasses} value={payrollPeriod} onChange={(e) => setPayrollPeriod(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldReason')} htmlFor="adv-reason" required>
        <input id="adv-reason" className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
      <FormField label={t('option.employeeDetail.fieldNotes')} htmlFor="adv-notes">
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
  const { t, tValue, language } = useI18n();
  // Every non-cancelled assignment is selectable, because a month can contain final pay for an assignment that has since ended.
  const activeAssignments = (employee.work_assignments ?? employee.active_work_assignments ?? []).filter((a) => a.status !== 'cancelled');
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [pickedAssignmentId, setWorkAssignmentId] = useState('');
  // Falls back to the first assignment so the card still works when an assignment is added after the page loaded.
  const workAssignmentId = activeAssignments.some((a) => String(a.id) === pickedAssignmentId) ? pickedAssignmentId : activeAssignments[0] ? String(activeAssignments[0].id) : '';

  const { data, isLoading, error } = useQuery({
    queryKey: ['payroll-summary', employeeId, workAssignmentId, period],
    queryFn: () => hrApi.payroll.summary(employeeId, Number(workAssignmentId), period),
    enabled: workAssignmentId !== '',
  });
  const runStatus = data?.run_status ? tValue('option.payrollStatus', data.run_status) ?? data.run_status : '';

  return (
    <Card>
      <CardHeader>
        <h3 className="font-display text-sm font-bold text-ink">{t('option.employeeDetail.payrollSummary')}</h3>
      </CardHeader>
      <CardBody className="space-y-3">
        {activeAssignments.length === 0 ? (
          <EmptyState icon="credit-card" title={t('option.employeeDetail.payrollNoAssignment')} description={t('warning.employeeDetail.payrollNeedsAssignment')} />
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
                    label: assignmentLabel(a),
                  }))}
                />
              )}
              <input type="month" className={`${inputClasses} h-8 text-xs`} value={period} onChange={(e) => setPeriod(e.target.value)} />
            </div>

            {isLoading && <p className="text-xs text-ink-faint">{t('option.employeeDetail.payrollCalculating')}</p>}
            {error && <p className="text-xs text-danger">{t('notify.employeeDetail.payrollLoadFailed')}</p>}

            {data && !data.payable && (
              <p data-testid="payroll-not-payable" className="rounded-sm border border-border bg-surface-muted px-3 py-2 text-xs text-ink-muted">
                {t('option.employeeDetail.payrollNoLine')} {data.not_payable_reason}
              </p>
            )}

            {data && data.payable && (
              <dl className="space-y-1.5 text-sm">
                <div
                  data-testid="payroll-provenance"
                  className={`rounded-sm border px-3 py-2 text-xs ${data.is_estimate ? 'border-warning/40 bg-warning/10 text-ink' : 'border-border bg-surface-muted text-ink-muted'}`}
                >
                  {data.is_estimate ? (
                    <>
                      <p className="font-semibold text-ink">{t('status.employeeDetail.payrollEstimate')}</p>
                      <p>{localizeServerMessage(language, data.estimate_note ?? '')}</p>
                      {data.run_id && data.differs_from_run && (
                        <p className="mt-1 font-medium text-danger">{t('warning.employeeDetail.payrollRunMismatch', { status: runStatus })}</p>
                      )}
                    </>
                  ) : (
                    <p>{t('warning.employeeDetail.payrollFinalFigures', { status: runStatus })}</p>
                  )}
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollMonthlySalary')}</dt>
                  <dd className="text-ink">{data.monthly_salary} {data.currency}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollDaysPaid', { eligible: data.eligible_days, total: data.days_in_month })}</dt>
                  <dd className="text-ink">{data.gross_pay}</dd>
                </div>
                {data.warnings.length > 0 && (
                  <ul data-testid="payroll-warnings" className="list-disc space-y-1 rounded-sm border border-warning/40 bg-warning/10 py-2 pl-6 pr-3 text-[11px] text-ink">
                    {data.warnings.map((w) => (
                      <li key={w}>{w}</li>
                    ))}
                  </ul>
                )}
                {data.final_pay && (
                  <p className="text-[11px] italic text-ink-faint">{t('warning.employeeDetail.payrollFinalPay', { day: data.final_pay.last_paid_day })}</p>
                )}
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollAbsenceDeduction', { days: data.absent_days })}</dt>
                  <dd className="text-danger">−{data.absence_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollPenaltyDeduction')}</dt>
                  <dd className="text-danger">−{data.penalty_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollSalaryAdvance')}</dt>
                  <dd className="text-danger">−{data.advance_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollReplacementCost')}</dt>
                  <dd className="text-danger">−{data.replacement_deduction}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-ink-muted">{t('option.employeeDetail.payrollUnpaidLeave')}</dt>
                  <dd className="text-danger">−{data.unpaid_leave_deduction}</dd>
                </div>
                <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                  <dt className="text-ink">{t('option.employeeDetail.payrollNetPayable')}</dt>
                  <dd className="text-ink">{data.net_payable} {data.currency}</dd>
                </div>
                {data.location_type === 'office' && (
                  <p className="pt-1 text-[11px] italic text-ink-faint">{t('warning.employeeDetail.payrollOvertimePending')}</p>
                )}
              </dl>
            )}
          </>
        )}
      </CardBody>
    </Card>
  );
}
