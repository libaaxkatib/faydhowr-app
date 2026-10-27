import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { facilityApi } from '@/api/facility';
import { hrApi } from '@/api/hr';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Icon } from '@/components/ui/Icon';
import { LoadingState } from '@/components/ui/LoadingState';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDate, formatDateTime } from '@/utils/formatters';
import type { MessageKey } from '@/i18n/messages';
import { useI18n } from '@/i18n/useI18n';
import { pageFromParam, withParam } from '@/utils/urlFilters';
import { friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { COMPLAINT_STATUS_LABELS, COMPLAINT_TRANSITIONS, complaintStatusTone, dateParam, satisfactionText } from '@/utils/facility';
import type { FacilityComplaintRow, FacilityComplaintStatus, FacilityComplaintType, FacilitySatisfaction } from '@/types/facility';

const TYPE_OPTIONS: { value: FacilityComplaintType; labelKey: MessageKey }[] = [
  { value: 'staff', labelKey: 'option.facility.type.staff' },
  { value: 'customer', labelKey: 'option.facility.type.customer' },
];

const STATUS_OPTIONS: { value: string; labelKey: MessageKey }[] = [
  { value: 'unresolved', labelKey: 'option.facility.status.unresolved' },
  { value: 'open', labelKey: 'option.facility.status.open' },
  { value: 'in_progress', labelKey: 'option.facility.status.in_progress' },
  { value: 'answered', labelKey: 'option.facility.status.answered' },
  { value: 'resolved', labelKey: 'option.facility.status.resolved' },
];

const SATISFACTION_OPTIONS: { value: string; labelKey: MessageKey }[] = [
  { value: 'satisfied', labelKey: 'option.facility.satisfaction.satisfied' },
  { value: 'not_satisfied', labelKey: 'option.facility.satisfaction.not_satisfied' },
  { value: 'not_recorded', labelKey: 'option.facility.satisfaction.not_recorded' },
];

/**
 * Staff and customer complaints about a company or guri. A complaint moves New/Open -> In progress -> Answered -> Resolved; "Answered" means a
 * reply was given and is NOT the same as "Resolved". Satisfaction is shown only when someone recorded explicit feedback with its evidence.
 */
export function FacilityComplaintsPage() {
  const { t, tValue } = useI18n();
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.inspections.manage');
  const [searchParams, setSearchParams] = useSearchParams();
  const company = /^\d+$/.test(searchParams.get('company') ?? '') ? searchParams.get('company') ?? '' : '';
  const type = (['staff', 'customer'].includes(searchParams.get('type') ?? '') ? searchParams.get('type') : '') as FacilityComplaintType | '';
  const status = (STATUS_OPTIONS.some((o) => o.value === searchParams.get('status')) ? searchParams.get('status') : '') as FacilityComplaintStatus | 'unresolved' | '';
  const satisfaction = (SATISFACTION_OPTIONS.some((o) => o.value === searchParams.get('satisfaction')) ? searchParams.get('satisfaction') : '') as FacilitySatisfaction | 'not_recorded' | '';
  const from = dateParam(searchParams.get('from'));
  const to = dateParam(searchParams.get('to'));
  const page = pageFromParam(searchParams.get('page'));
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<number | null>(null);

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const list = useQuery({
    queryKey: ['facility-complaints', company, type, status, satisfaction, from, to, page],
    queryFn: () => facilityApi.complaints.list({ client_company_id: company ? Number(company) : undefined, type, status, satisfaction, from: from || undefined, to: to || undefined, page, per_page: 15 }),
    placeholderData: (previous) => previous,
  });

  function setParam(key: string, value: string) {
    setSearchParams((previous) => withParam(previous, key, value), { replace: true });
  }
  const setPage = (next: number) => setParam('page', next <= 1 ? '' : String(next));

  const filtered = company !== '' || type !== '' || status !== '' || satisfaction !== '' || from !== '' || to !== '';

  return (
    <div>
      <PageHeader
        title={t('nav.complaints')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.companiesWorkplaces'), to: '/hr/companies' }, { label: t('nav.complaints') }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link to="/hr/facility-inspections" className="inline-flex h-10 items-center gap-1.5 rounded-sm border border-border bg-surface px-4 text-sm font-medium text-ink hover:bg-surface-alt">
              {t('nav.facilityInspections')}
            </Link>
            {canManage && (
              <Button onClick={() => setCreating(true)}>
                <Icon name="plus" size={15} />
                {t('action.facility.registerComplaint')}
              </Button>
            )}
          </div>
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
          <Select
            value={company}
            onChange={(e) => setParam('company', e.target.value)}
            placeholder={t('option.facility.allCompanies')}
            aria-label={t('option.label.company')}
            className="w-52"
            options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
          />
          <Select value={type} onChange={(e) => setParam('type', e.target.value)} placeholder={t('option.facility.allTypes')} aria-label={t('option.label.type')} className="w-44" options={TYPE_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
          <Select value={status} onChange={(e) => setParam('status', e.target.value)} placeholder={t('option.facility.anyStatus')} aria-label={t('option.label.status')} className="w-44" options={STATUS_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
          <Select value={satisfaction} onChange={(e) => setParam('satisfaction', e.target.value)} placeholder={t('option.facility.anySatisfaction')} aria-label={t('option.facility.satisfactionLabel')} className="w-48" options={SATISFACTION_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
          {(from || to) && (
            <span className="rounded-full bg-surface-alt px-3 py-1 text-xs text-ink-muted" data-testid="period-filter">{t('option.facility.reportedPeriod', { from: from ? formatDate(from) : t('option.facility.fromTheStart'), to: to ? formatDate(to) : t('option.facility.todayWord') })}</span>
          )}
          {filtered && (
            <button type="button" onClick={() => setSearchParams({}, { replace: true })} className="text-xs font-semibold text-primary hover:underline">
              {t('action.facility.clearFilters')}
            </button>
          )}
        </div>

        {list.isLoading && <LoadingState label={t('option.facility.loadingComplaints')} />}
        {list.error && <ErrorState error={list.error} onRetry={list.refetch} />}
        {list.data && list.data.data.length === 0 && <EmptyState icon="alert-triangle" title={filtered ? t('option.facility.noComplaintsMatch') : t('option.facility.noComplaintsYet')} />}
        {list.data && list.data.data.length > 0 && (
          <div className="divide-y divide-border">
            {list.data.data.map((complaint) => (
              <button key={complaint.id} type="button" onClick={() => setOpenId(complaint.id)} className="flex w-full flex-wrap items-center justify-between gap-2 px-5 py-3 text-left hover:bg-surface-alt">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-ink">
                    {complaint.company} <span className="font-normal text-ink-muted">· {tValue('option.facility.type', complaint.type) ?? complaint.type_label} · {formatDate(complaint.reported_on)}</span>
                  </p>
                  <p className="truncate text-xs text-ink-muted">{complaint.details}</p>
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge status={complaint.status} label={tValue('option.facility.status', complaint.status) ?? complaint.status_label} tone={complaintStatusTone(complaint.status)} />
                  <span className="text-xs text-ink-muted">{satisfactionText(complaint.satisfaction)}</span>
                </div>
              </button>
            ))}
          </div>
        )}
        {list.data && list.data.meta.total > 0 && <Pagination meta={list.data.meta} onPageChange={setPage} />}
      </Card>

      {creating && <RegisterComplaintModal initialCompanyId={company} onClose={() => setCreating(false)} />}
      {openId !== null && <ComplaintDetailModal id={openId} canManage={canManage} onClose={() => setOpenId(null)} />}
    </div>
  );
}

function Banner({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <div role="alert" className="mb-4 rounded-md border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm text-danger">
      {message}
    </div>
  );
}

function RegisterComplaintModal({ initialCompanyId, onClose }: { initialCompanyId: string; onClose: () => void }) {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [companyId, setCompanyId] = useState(initialCompanyId);
  const [type, setType] = useState<FacilityComplaintType | ''>('');
  const [details, setDetails] = useState('');
  const [reporter, setReporter] = useState('');
  const [reportedOn, setReportedOn] = useState(localToday());
  const [responsible, setResponsible] = useState('');
  const [response, setResponse] = useState('');
  const [respondedOn, setRespondedOn] = useState('');
  const [reporterEmployee, setReporterEmployee] = useState('');
  const [reporterRole, setReporterRole] = useState('');
  const [reporterPhone, setReporterPhone] = useState('');
  const [correctiveAction, setCorrectiveAction] = useState('');
  const [followUpOn, setFollowUpOn] = useState('');
  const [error, setError] = useState<string | null>(null);
  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: staffOptions } = useQuery({
    queryKey: ['facility-company-employees', companyId, reportedOn],
    queryFn: () => facilityApi.inspections.companyEmployees(Number(companyId), reportedOn),
    enabled: companyId !== '' && type === 'staff',
  });
  // A different company means a different list of people.
  useEffect(() => setReporterEmployee(''), [companyId]);
  const dirty = [details, reporter, responsible, response, respondedOn, reporterEmployee, reporterRole, reporterPhone, correctiveAction, followUpOn].some((v) => v !== '') || type !== '' || companyId !== initialCompanyId;

  const problems: string[] = [];
  if (companyId === '') problems.push(t('validation.hr.complaintChooseCompany'));
  if (type === '') problems.push(t('validation.hr.complaintChooseType'));
  if (type === 'staff' && reporterEmployee === '') problems.push(t('validation.hr.complaintChooseStaff'));
  if (type === 'customer' && reporter.trim() === '') problems.push(t('validation.facility.complainantCustomer'));
  if (details.trim().length < 5) problems.push(t('validation.facility.complaintDetails'));
  if (followUpOn !== '' && followUpOn < reportedOn) problems.push(t('validation.facility.followUpBeforeComplaint'));
  if (respondedOn !== '' && respondedOn < reportedOn) problems.push(t('validation.facility.responseBeforeComplaint'));

  const save = useMutation({
    mutationFn: () =>
      facilityApi.complaints.create({
        client_company_id: Number(companyId),
        type: type as FacilityComplaintType,
        details: details.trim(),
        reporter_name: type === 'customer' && reporter.trim() !== '' ? reporter.trim() : null,
        reporter_type: type === 'customer' && reporterRole.trim() !== '' ? reporterRole.trim() : null,
        reporter_phone: type === 'customer' && reporterPhone.trim() !== '' ? reporterPhone.trim() : null,
        reporter_employee_id: type === 'staff' && reporterEmployee !== '' ? Number(reporterEmployee) : null,
        corrective_action: correctiveAction.trim() === '' ? null : correctiveAction.trim(),
        follow_up_on: followUpOn === '' ? null : followUpOn,
        reported_on: reportedOn,
        responsible_person: responsible.trim() === '' ? null : responsible.trim(),
        response: response.trim() === '' ? null : response.trim(),
        responded_on: respondedOn === '' ? null : respondedOn,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['facility-complaints'] });
      queryClient.invalidateQueries({ queryKey: ['facility-report'] });
      show(t('notify.facility.complaintRegistered'));
      onClose();
    },
    onError: (err) => setError(friendlyErrorMessage(err, t('notify.facility.complaintFailed'))),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={t('action.facility.registerComplaint')}
      confirmDiscard
      dirty={dirty}
      footer={
        <>
          <ModalCancelButton disabled={save.isPending} />
          <Button size="sm" isLoading={save.isPending} disabled={problems.length > 0} onClick={() => { setError(null); save.mutate(); }}>{t('action.facility.register')}</Button>
        </>
      }
    >
      <Banner message={error} />
      <FormField label={t('option.label.companyGuri')} htmlFor="cmp-company" required>
        <Select id="cmp-company" value={companyId} onChange={(e) => setCompanyId(e.target.value)} placeholder={t('option.facility.choose')} options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
      </FormField>
      <FormField label={t('option.label.type')} htmlFor="cmp-type" required hint={t('warning.facility.newComplaintStartsOpen')}>
        <Select id="cmp-type" value={type} onChange={(e) => setType(e.target.value as FacilityComplaintType | '')} placeholder={t('option.facility.choose')} options={TYPE_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey) }))} />
      </FormField>
      {type === 'staff' && (
        <FormField label={t('option.facility.employeeWhoComplained')} htmlFor="cmp-employee" required hint={companyId === '' ? t('warning.facility.chooseCompanyFirst') : t('warning.facility.onlyCompanyPeople')}>
          <Select id="cmp-employee" value={reporterEmployee} disabled={companyId === ''} onChange={(e) => setReporterEmployee(e.target.value)} placeholder={t('option.facility.chooseEmployee')} options={(staffOptions ?? []).map((e) => ({ value: String(e.id), label: `${e.full_name} (${e.employee_number})` }))} />
        </FormField>
      )}
      {type === 'customer' && (
        <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-3">
          <FormField label={t('option.facility.customerName')} htmlFor="cmp-reporter" required>
            <input id="cmp-reporter" className={inputClasses} value={reporter} onChange={(e) => setReporter(e.target.value)} />
          </FormField>
          <FormField label={t('option.facility.roleOptional')} htmlFor="cmp-role">
            <input id="cmp-role" className={inputClasses} value={reporterRole} onChange={(e) => setReporterRole(e.target.value)} />
          </FormField>
          <FormField label={t('option.facility.phoneOptional')} htmlFor="cmp-phone">
            <input id="cmp-phone" className={inputClasses} value={reporterPhone} onChange={(e) => setReporterPhone(e.target.value)} />
          </FormField>
        </div>
      )}
      <FormField label={t('option.facility.complaint')} htmlFor="cmp-details" required>
        <textarea id="cmp-details" rows={3} className={`${inputClasses} h-auto py-2`} value={details} onChange={(e) => setDetails(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <FormField label={t('option.facility.dateReported')} htmlFor="cmp-date" required>
          <input id="cmp-date" type="date" max={localToday()} className={inputClasses} value={reportedOn} onChange={(e) => setReportedOn(e.target.value)} />
        </FormField>
        <FormField label={t('option.facility.responsiblePerson')} htmlFor="cmp-responsible">
          <input id="cmp-responsible" className={inputClasses} value={responsible} onChange={(e) => setResponsible(e.target.value)} />
        </FormField>
        <FormField label={t('option.facility.responseGivenOn')} htmlFor="cmp-responded">
          <input id="cmp-responded" type="date" max={localToday()} className={inputClasses} value={respondedOn} onChange={(e) => setRespondedOn(e.target.value)} />
        </FormField>
      </div>
      <FormField label={t('option.facility.responseIfGiven')} htmlFor="cmp-response">
        <textarea id="cmp-response" rows={2} className={`${inputClasses} h-auto py-2`} value={response} onChange={(e) => setResponse(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <FormField label={t('option.facility.correctiveActionOptional')} htmlFor="cmp-action">
          <input id="cmp-action" className={inputClasses} value={correctiveAction} onChange={(e) => setCorrectiveAction(e.target.value)} />
        </FormField>
        <FormField label={t('option.facility.followUpDateOptional')} htmlFor="cmp-followup">
          <input id="cmp-followup" type="date" className={inputClasses} value={followUpOn} onChange={(e) => setFollowUpOn(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function ComplaintDetailModal({ id, canManage, onClose }: { id: number; canManage: boolean; onClose: () => void }) {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { data, isLoading, error: loadError } = useQuery({ queryKey: ['facility-complaint', id], queryFn: () => facilityApi.complaints.get(id) });
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const [response, setResponse] = useState<string | null>(null);
  const [respondedOn, setRespondedOn] = useState('');
  const [action, setAction] = useState('');
  const [followUp, setFollowUp] = useState('');
  const [evidence, setEvidence] = useState('');
  const [voidReason, setVoidReason] = useState<string | null>(null);

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['facility-complaint', id] });
    queryClient.invalidateQueries({ queryKey: ['facility-complaints'] });
    queryClient.invalidateQueries({ queryKey: ['facility-report'] });
  };
  const failed = (err: unknown) => setError(friendlyErrorMessage(err, t('notify.facility.saveFailed')));

  const transition = useMutation({
    mutationFn: (status: FacilityComplaintStatus) => facilityApi.complaints.transition(id, { status, note: note.trim() === '' ? null : note.trim(), resolved_on: status === 'resolved' ? localToday() : null }),
    onSuccess: (_row, status) => {
      refresh();
      setNote('');
      show(t('notify.facility.statusChanged', { status: COMPLAINT_STATUS_LABELS[status] }));
    },
    onError: failed,
  });
  const saveResponse = useMutation({
    mutationFn: () => facilityApi.complaints.update(id, { response: (response ?? '').trim() === '' ? null : (response ?? '').trim(), responded_on: respondedOn === '' ? null : respondedOn, corrective_action: action.trim() === '' ? null : action.trim(), follow_up_on: followUp === '' ? null : followUp }),
    onSuccess: () => {
      refresh();
      setResponse(null);
      show(t('notify.facility.responseSaved'));
    },
    onError: failed,
  });
  const saveSatisfaction = useMutation({
    mutationFn: (satisfaction: FacilitySatisfaction | null) => facilityApi.complaints.satisfaction(id, { satisfaction, evidence: evidence.trim() }),
    onSuccess: () => {
      refresh();
      setEvidence('');
      show(t('notify.facility.feedbackRecorded'));
    },
    onError: failed,
  });
  const voidIt = useMutation({
    mutationFn: () => facilityApi.complaints.void(id, (voidReason ?? '').trim()),
    onSuccess: () => {
      refresh();
      show(t('notify.facility.complaintVoided'));
      onClose();
    },
    onError: failed,
  });

  const complaint: FacilityComplaintRow | undefined = data;
  const next = complaint ? COMPLAINT_TRANSITIONS[complaint.status] : [];

  return (
    <Modal isOpen onClose={onClose} title={complaint ? `${tValue('option.facility.type', complaint.type) ?? complaint.type_label} - ${complaint.company ?? ''}` : t('option.facility.complaint')} size="lg">
      {isLoading && <LoadingState label={t('option.facility.loadingComplaint')} />}
      {loadError && <ErrorState error={loadError} />}
      {complaint && (
        <div className="space-y-5 text-sm">
          <Banner message={error} />
          {complaint.voided && (
            <div className="rounded-md border border-border bg-surface-alt px-3.5 py-2.5 text-ink-muted">{t('warning.facility.complaintVoided', { reason: complaint.void_reason ? `: ${complaint.void_reason}` : '' })}</div>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={complaint.status} label={tValue('option.facility.status', complaint.status) ?? complaint.status_label} tone={complaintStatusTone(complaint.status)} />
            <span className="text-ink-muted">{t('option.facility.reportedOn', { date: formatDate(complaint.reported_on) })}</span>
            {complaint.reporter_name && <span className="text-ink-muted">{t('option.facility.reportedBy', { name: complaint.reporter_name })}</span>}
            {complaint.responsible_person && <span className="text-ink-muted">· {t('option.facility.responsibleName', { name: complaint.responsible_person })}</span>}
            {complaint.type === 'customer' && (complaint.reporter_type || complaint.reporter_phone) && <span className="text-ink-muted">· {[complaint.reporter_type, complaint.reporter_phone].filter(Boolean).join(', ')}</span>}
            {complaint.type === 'staff' && complaint.reporter_employee && <span className="text-ink-muted">· {t('option.facility.employeeName', { name: complaint.reporter_employee })}</span>}
          </div>
          <p className="whitespace-pre-wrap text-ink">{complaint.details}</p>

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">{t('option.facility.response')}</h3>
            {response === null ? (
              <>
                <p className="whitespace-pre-wrap text-ink-muted">{complaint.response ?? t('option.facility.noResponseYet')}</p>
                {complaint.responded_on && <p className="text-xs text-ink-faint">{t('option.facility.respondedOn', { date: formatDate(complaint.responded_on) })}</p>}
                <p className="mt-1 text-ink-muted">{t('option.facility.correctiveActionIs', { action: complaint.corrective_action ?? t('option.facility.notRecordedLower') })}{complaint.follow_up_on ? ` · ${t('option.facility.followUpLower', { date: formatDate(complaint.follow_up_on) })}` : ''}</p>
                {complaint.facility_inspection_id !== null && <p className="text-xs text-ink-faint">{t('option.facility.recordedDuringInspection', { id: complaint.facility_inspection_id })}</p>}
                {canManage && !complaint.voided && (
                  <button type="button" onClick={() => { setResponse(complaint.response ?? ''); setRespondedOn(complaint.responded_on ?? localToday()); setAction(complaint.corrective_action ?? ''); setFollowUp(complaint.follow_up_on ?? ''); }} className="mt-1 text-xs font-semibold text-primary hover:underline">
                    {complaint.response ? t('action.facility.editResponse') : t('action.facility.writeResponse')}
                  </button>
                )}
              </>
            ) : (
              <div>
                <textarea aria-label={t('option.facility.response')} rows={3} className={`${inputClasses} h-auto py-2`} value={response} onChange={(e) => setResponse(e.target.value)} />
                <input aria-label={t('option.label.correctiveAction')} className={`${inputClasses} mt-2`} placeholder={t('option.label.correctiveAction')} value={action} onChange={(e) => setAction(e.target.value)} />
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <input aria-label={t('option.label.followUpDate')} type="date" className={`${inputClasses} w-40`} value={followUp} onChange={(e) => setFollowUp(e.target.value)} />
                  <input aria-label={t('option.facility.responseDate')} type="date" max={localToday()} className={`${inputClasses} w-40`} value={respondedOn} onChange={(e) => setRespondedOn(e.target.value)} />
                  <Button size="sm" isLoading={saveResponse.isPending} onClick={() => saveResponse.mutate()}>{t('action.facility.saveResponse')}</Button>
                  <Button size="sm" variant="outline" onClick={() => setResponse(null)}>{t('action.cancel')}</Button>
                </div>
              </div>
            )}
          </section>

          {canManage && !complaint.voided && (
            <section>
              <h3 className="mb-1.5 font-display text-sm font-bold text-ink">{t('option.facility.changeStatus')}</h3>
              <p className="mb-2 text-xs text-ink-muted">{t('warning.facility.answeredMeans')}</p>
              <input aria-label={t('option.facility.noteForChange')} className={`${inputClasses} mb-2`} placeholder={t('option.label.noteOptional')} value={note} onChange={(e) => setNote(e.target.value)} />
              <div className="flex flex-wrap gap-2">
                {next.map((status) => (
                  <Button key={status} variant="outline" size="sm" isLoading={transition.isPending && transition.variables === status} onClick={() => { setError(null); transition.mutate(status); }}>
                    {status === 'open' && complaint.status === 'resolved' ? t('action.facility.reopenComplaint') : t('action.facility.markStatus', { status: COMPLAINT_STATUS_LABELS[status] })}
                  </Button>
                ))}
              </div>
            </section>
          )}

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">{t('option.facility.satisfactionLabel')}</h3>
            <p className="text-ink">
              {tValue('option.facility.satisfaction', complaint.satisfaction ?? 'not_recorded') ?? complaint.satisfaction_label}
              {complaint.satisfaction_recorded_on && <span className="text-ink-muted"> · {t('option.facility.recordedOn', { date: formatDate(complaint.satisfaction_recorded_on) })}</span>}
            </p>
            {complaint.satisfaction_evidence && <p className="text-xs text-ink-muted">{t('option.facility.evidenceIs', { evidence: complaint.satisfaction_evidence })}</p>}
            {complaint.satisfaction === null && <p className="text-xs text-ink-faint">{t('warning.facility.nothingAssumed')}</p>}
            {canManage && !complaint.voided && (
              <div className="mt-2 space-y-2">
                <input aria-label={t('option.facility.evidenceOfFeedback')} className={inputClasses} placeholder={t('option.facility.evidencePlaceholder')} value={evidence} onChange={(e) => setEvidence(e.target.value)} />
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="outline" disabled={evidence.trim().length < 10} isLoading={saveSatisfaction.isPending} onClick={() => { setError(null); saveSatisfaction.mutate('satisfied'); }}>{t('action.facility.recordSatisfied')}</Button>
                  <Button size="sm" variant="outline" disabled={evidence.trim().length < 10} isLoading={saveSatisfaction.isPending} onClick={() => { setError(null); saveSatisfaction.mutate('not_satisfied'); }}>{t('action.facility.recordNotSatisfied')}</Button>
                  {complaint.satisfaction !== null && (
                    <Button size="sm" variant="ghost" disabled={evidence.trim().length < 10} onClick={() => { setError(null); saveSatisfaction.mutate(null); }}>{t('action.facility.clearFeedback')}</Button>
                  )}
                </div>
              </div>
            )}
          </section>

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">{t('action.history')}</h3>
            <ol className="space-y-1.5">
              {(complaint.events ?? []).map((event) => (
                <li key={event.id} className="text-xs text-ink-muted">
                  <span className="text-ink">{event.event.replace(/_/g, ' ')}</span>
                  {event.to_status ? ` → ${COMPLAINT_STATUS_LABELS[event.to_status as FacilityComplaintStatus] ?? event.to_status}` : ''}
                  {event.note ? ` · ${event.note}` : ''} · {event.by ?? t('option.company.bySystem')} · {formatDateTime(event.at)}
                </li>
              ))}
            </ol>
          </section>

          {canManage && !complaint.voided && (
            <section className="border-t border-border pt-3">
              {voidReason === null ? (
                <button type="button" onClick={() => setVoidReason('')} className="text-xs font-semibold text-danger hover:underline">{t('action.facility.voidComplaint')}</button>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <input aria-label={t('option.facility.reasonForVoiding')} className={`${inputClasses} flex-1`} placeholder={t('confirm.facility.voidComplaintReason')} value={voidReason} onChange={(e) => setVoidReason(e.target.value)} />
                  <Button size="sm" variant="danger" disabled={voidReason.trim().length < 5} isLoading={voidIt.isPending} onClick={() => voidIt.mutate()}>{t('action.void')}</Button>
                  <Button size="sm" variant="outline" onClick={() => setVoidReason(null)}>{t('action.cancel')}</Button>
                </div>
              )}
            </section>
          )}
        </div>
      )}
    </Modal>
  );
}
