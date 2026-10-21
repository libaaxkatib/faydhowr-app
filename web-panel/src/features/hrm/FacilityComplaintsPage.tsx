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
import { pageFromParam, withParam } from '@/utils/urlFilters';
import { friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { COMPLAINT_STATUS_LABELS, COMPLAINT_TRANSITIONS, complaintStatusTone, dateParam, satisfactionText } from '@/utils/facility';
import type { FacilityComplaintRow, FacilityComplaintStatus, FacilityComplaintType, FacilitySatisfaction } from '@/types/facility';

const TYPE_OPTIONS: { value: FacilityComplaintType; label: string }[] = [
  { value: 'staff', label: 'Staff complaint' },
  { value: 'customer', label: 'Customer complaint' },
];

const STATUS_OPTIONS = [
  { value: 'unresolved', label: 'Not resolved yet' },
  { value: 'open', label: 'New / open' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'answered', label: 'Answered' },
  { value: 'resolved', label: 'Resolved' },
];

const SATISFACTION_OPTIONS = [
  { value: 'satisfied', label: 'Satisfied (documented)' },
  { value: 'not_satisfied', label: 'Not satisfied (documented)' },
  { value: 'not_recorded', label: 'Not recorded' },
];

/**
 * Staff and customer complaints about a company or guri. A complaint moves New/Open -> In progress -> Answered -> Resolved; "Answered" means a
 * reply was given and is NOT the same as "Resolved". Satisfaction is shown only when someone recorded explicit feedback with its evidence.
 */
export function FacilityComplaintsPage() {
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
        title="Complaints"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Companies & Workplaces', to: '/hr/companies' }, { label: 'Complaints' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link to="/hr/facility-inspections" className="inline-flex h-10 items-center gap-1.5 rounded-sm border border-border bg-surface px-4 text-sm font-medium text-ink hover:bg-surface-alt">
              Facility Inspections
            </Link>
            {canManage && (
              <Button onClick={() => setCreating(true)}>
                <Icon name="plus" size={15} />
                Register complaint
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
            placeholder="All companies"
            aria-label="Company"
            className="w-52"
            options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
          />
          <Select value={type} onChange={(e) => setParam('type', e.target.value)} placeholder="All types" aria-label="Type" className="w-44" options={TYPE_OPTIONS} />
          <Select value={status} onChange={(e) => setParam('status', e.target.value)} placeholder="Any status" aria-label="Status" className="w-44" options={STATUS_OPTIONS} />
          <Select value={satisfaction} onChange={(e) => setParam('satisfaction', e.target.value)} placeholder="Any satisfaction" aria-label="Satisfaction" className="w-48" options={SATISFACTION_OPTIONS} />
          {(from || to) && (
            <span className="rounded-full bg-surface-alt px-3 py-1 text-xs text-ink-muted" data-testid="period-filter">Reported {from ? formatDate(from) : 'from the start'} to {to ? formatDate(to) : 'today'}</span>
          )}
          {filtered && (
            <button type="button" onClick={() => setSearchParams({}, { replace: true })} className="text-xs font-semibold text-primary hover:underline">
              Clear filters
            </button>
          )}
        </div>

        {list.isLoading && <LoadingState label="Loading complaints…" />}
        {list.error && <ErrorState error={list.error} onRetry={list.refetch} />}
        {list.data && list.data.data.length === 0 && <EmptyState icon="alert-triangle" title={filtered ? 'No complaints match these filters' : 'No complaints registered yet'} />}
        {list.data && list.data.data.length > 0 && (
          <div className="divide-y divide-border">
            {list.data.data.map((complaint) => (
              <button key={complaint.id} type="button" onClick={() => setOpenId(complaint.id)} className="flex w-full flex-wrap items-center justify-between gap-2 px-5 py-3 text-left hover:bg-surface-alt">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-ink">
                    {complaint.company} <span className="font-normal text-ink-muted">· {complaint.type_label} · {formatDate(complaint.reported_on)}</span>
                  </p>
                  <p className="truncate text-xs text-ink-muted">{complaint.details}</p>
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge status={complaint.status} label={complaint.status_label} tone={complaintStatusTone(complaint.status)} />
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
  if (companyId === '') problems.push('Choose the company.');
  if (type === '') problems.push('Choose whether this is a staff or a customer complaint.');
  if (type === 'staff' && reporterEmployee === '') problems.push('Choose the employee who made the staff complaint.');
  if (type === 'customer' && reporter.trim() === '') problems.push('Enter the name of the customer who complained.');
  if (details.trim().length < 5) problems.push('Describe the complaint (at least 5 characters).');
  if (followUpOn !== '' && followUpOn < reportedOn) problems.push('The follow-up date cannot be before the complaint date.');
  if (respondedOn !== '' && respondedOn < reportedOn) problems.push('The response date cannot be before the complaint date.');

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
      show('Complaint registered.');
      onClose();
    },
    onError: (err) => setError(friendlyErrorMessage(err, 'Could not register the complaint.')),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Register complaint"
      confirmDiscard
      dirty={dirty}
      footer={
        <>
          <ModalCancelButton disabled={save.isPending} />
          <Button size="sm" isLoading={save.isPending} disabled={problems.length > 0} onClick={() => { setError(null); save.mutate(); }}>Register</Button>
        </>
      }
    >
      <Banner message={error} />
      <FormField label="Company / Guri" htmlFor="cmp-company" required>
        <Select id="cmp-company" value={companyId} onChange={(e) => setCompanyId(e.target.value)} placeholder="Choose…" options={(companies ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
      </FormField>
      <FormField label="Type" htmlFor="cmp-type" required hint="A new complaint always starts as New / open.">
        <Select id="cmp-type" value={type} onChange={(e) => setType(e.target.value as FacilityComplaintType | '')} placeholder="Choose…" options={TYPE_OPTIONS} />
      </FormField>
      {type === 'staff' && (
        <FormField label="Employee who complained" htmlFor="cmp-employee" required hint={companyId === '' ? 'Choose the company first.' : 'Only people who work for the chosen company are listed.'}>
          <Select id="cmp-employee" value={reporterEmployee} disabled={companyId === ''} onChange={(e) => setReporterEmployee(e.target.value)} placeholder="Choose the employee…" options={(staffOptions ?? []).map((e) => ({ value: String(e.id), label: `${e.full_name} (${e.employee_number})` }))} />
        </FormField>
      )}
      {type === 'customer' && (
        <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-3">
          <FormField label="Customer name" htmlFor="cmp-reporter" required>
            <input id="cmp-reporter" className={inputClasses} value={reporter} onChange={(e) => setReporter(e.target.value)} />
          </FormField>
          <FormField label="Role (optional)" htmlFor="cmp-role">
            <input id="cmp-role" className={inputClasses} value={reporterRole} onChange={(e) => setReporterRole(e.target.value)} />
          </FormField>
          <FormField label="Phone (optional)" htmlFor="cmp-phone">
            <input id="cmp-phone" className={inputClasses} value={reporterPhone} onChange={(e) => setReporterPhone(e.target.value)} />
          </FormField>
        </div>
      )}
      <FormField label="Complaint" htmlFor="cmp-details" required>
        <textarea id="cmp-details" rows={3} className={`${inputClasses} h-auto py-2`} value={details} onChange={(e) => setDetails(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <FormField label="Date reported" htmlFor="cmp-date" required>
          <input id="cmp-date" type="date" max={localToday()} className={inputClasses} value={reportedOn} onChange={(e) => setReportedOn(e.target.value)} />
        </FormField>
        <FormField label="Responsible person" htmlFor="cmp-responsible">
          <input id="cmp-responsible" className={inputClasses} value={responsible} onChange={(e) => setResponsible(e.target.value)} />
        </FormField>
        <FormField label="Response given on" htmlFor="cmp-responded">
          <input id="cmp-responded" type="date" max={localToday()} className={inputClasses} value={respondedOn} onChange={(e) => setRespondedOn(e.target.value)} />
        </FormField>
      </div>
      <FormField label="Response (if already given)" htmlFor="cmp-response">
        <textarea id="cmp-response" rows={2} className={`${inputClasses} h-auto py-2`} value={response} onChange={(e) => setResponse(e.target.value)} />
      </FormField>
      <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        <FormField label="Corrective action (optional)" htmlFor="cmp-action">
          <input id="cmp-action" className={inputClasses} value={correctiveAction} onChange={(e) => setCorrectiveAction(e.target.value)} />
        </FormField>
        <FormField label="Follow-up date (optional)" htmlFor="cmp-followup">
          <input id="cmp-followup" type="date" className={inputClasses} value={followUpOn} onChange={(e) => setFollowUpOn(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}

function ComplaintDetailModal({ id, canManage, onClose }: { id: number; canManage: boolean; onClose: () => void }) {
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
  const failed = (err: unknown) => setError(friendlyErrorMessage(err, 'Could not save. Nothing was changed.'));

  const transition = useMutation({
    mutationFn: (status: FacilityComplaintStatus) => facilityApi.complaints.transition(id, { status, note: note.trim() === '' ? null : note.trim(), resolved_on: status === 'resolved' ? localToday() : null }),
    onSuccess: (_row, status) => {
      refresh();
      setNote('');
      show(`Status changed to ${COMPLAINT_STATUS_LABELS[status]}.`);
    },
    onError: failed,
  });
  const saveResponse = useMutation({
    mutationFn: () => facilityApi.complaints.update(id, { response: (response ?? '').trim() === '' ? null : (response ?? '').trim(), responded_on: respondedOn === '' ? null : respondedOn, corrective_action: action.trim() === '' ? null : action.trim(), follow_up_on: followUp === '' ? null : followUp }),
    onSuccess: () => {
      refresh();
      setResponse(null);
      show('Response saved.');
    },
    onError: failed,
  });
  const saveSatisfaction = useMutation({
    mutationFn: (satisfaction: FacilitySatisfaction | null) => facilityApi.complaints.satisfaction(id, { satisfaction, evidence: evidence.trim() }),
    onSuccess: () => {
      refresh();
      setEvidence('');
      show('Feedback recorded.');
    },
    onError: failed,
  });
  const voidIt = useMutation({
    mutationFn: () => facilityApi.complaints.void(id, (voidReason ?? '').trim()),
    onSuccess: () => {
      refresh();
      show('Complaint voided. It is kept on record but no longer counted.');
      onClose();
    },
    onError: failed,
  });

  const complaint: FacilityComplaintRow | undefined = data;
  const next = complaint ? COMPLAINT_TRANSITIONS[complaint.status] : [];

  return (
    <Modal isOpen onClose={onClose} title={complaint ? `${complaint.type_label} - ${complaint.company ?? ''}` : 'Complaint'} size="lg">
      {isLoading && <LoadingState label="Loading complaint…" />}
      {loadError && <ErrorState error={loadError} />}
      {complaint && (
        <div className="space-y-5 text-sm">
          <Banner message={error} />
          {complaint.voided && (
            <div className="rounded-md border border-border bg-surface-alt px-3.5 py-2.5 text-ink-muted">Voided{complaint.void_reason ? `: ${complaint.void_reason}` : ''}. Kept on record, not counted.</div>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={complaint.status} label={complaint.status_label} tone={complaintStatusTone(complaint.status)} />
            <span className="text-ink-muted">Reported {formatDate(complaint.reported_on)}</span>
            {complaint.reporter_name && <span className="text-ink-muted">by {complaint.reporter_name}</span>}
            {complaint.responsible_person && <span className="text-ink-muted">· Responsible: {complaint.responsible_person}</span>}
            {complaint.type === 'customer' && (complaint.reporter_type || complaint.reporter_phone) && <span className="text-ink-muted">· {[complaint.reporter_type, complaint.reporter_phone].filter(Boolean).join(', ')}</span>}
            {complaint.type === 'staff' && complaint.reporter_employee && <span className="text-ink-muted">· Employee: {complaint.reporter_employee}</span>}
          </div>
          <p className="whitespace-pre-wrap text-ink">{complaint.details}</p>

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Response</h3>
            {response === null ? (
              <>
                <p className="whitespace-pre-wrap text-ink-muted">{complaint.response ?? 'No response recorded yet.'}</p>
                {complaint.responded_on && <p className="text-xs text-ink-faint">Responded {formatDate(complaint.responded_on)}</p>}
                <p className="mt-1 text-ink-muted">Corrective action: {complaint.corrective_action ?? 'not recorded'}{complaint.follow_up_on ? ` · follow up ${formatDate(complaint.follow_up_on)}` : ''}</p>
                {complaint.facility_inspection_id !== null && <p className="text-xs text-ink-faint">Recorded during facility inspection #{complaint.facility_inspection_id}.</p>}
                {canManage && !complaint.voided && (
                  <button type="button" onClick={() => { setResponse(complaint.response ?? ''); setRespondedOn(complaint.responded_on ?? localToday()); setAction(complaint.corrective_action ?? ''); setFollowUp(complaint.follow_up_on ?? ''); }} className="mt-1 text-xs font-semibold text-primary hover:underline">
                    {complaint.response ? 'Edit response and action' : 'Write response and action'}
                  </button>
                )}
              </>
            ) : (
              <div>
                <textarea aria-label="Response" rows={3} className={`${inputClasses} h-auto py-2`} value={response} onChange={(e) => setResponse(e.target.value)} />
                <input aria-label="Corrective action" className={`${inputClasses} mt-2`} placeholder="Corrective action" value={action} onChange={(e) => setAction(e.target.value)} />
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <input aria-label="Follow-up date" type="date" className={`${inputClasses} w-40`} value={followUp} onChange={(e) => setFollowUp(e.target.value)} />
                  <input aria-label="Response date" type="date" max={localToday()} className={`${inputClasses} w-40`} value={respondedOn} onChange={(e) => setRespondedOn(e.target.value)} />
                  <Button size="sm" isLoading={saveResponse.isPending} onClick={() => saveResponse.mutate()}>Save response</Button>
                  <Button size="sm" variant="outline" onClick={() => setResponse(null)}>Cancel</Button>
                </div>
              </div>
            )}
          </section>

          {canManage && !complaint.voided && (
            <section>
              <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Change status</h3>
              <p className="mb-2 text-xs text-ink-muted">"Answered" means a reply was given. The complaint is only closed when it is marked "Resolved".</p>
              <input aria-label="Note for this change" className={`${inputClasses} mb-2`} placeholder="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} />
              <div className="flex flex-wrap gap-2">
                {next.map((status) => (
                  <Button key={status} variant="outline" size="sm" isLoading={transition.isPending && transition.variables === status} onClick={() => { setError(null); transition.mutate(status); }}>
                    {status === 'open' && complaint.status === 'resolved' ? 'Re-open' : `Mark ${COMPLAINT_STATUS_LABELS[status]}`}
                  </Button>
                ))}
              </div>
            </section>
          )}

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Satisfaction</h3>
            <p className="text-ink">
              {complaint.satisfaction_label}
              {complaint.satisfaction_recorded_on && <span className="text-ink-muted"> · recorded {formatDate(complaint.satisfaction_recorded_on)}</span>}
            </p>
            {complaint.satisfaction_evidence && <p className="text-xs text-ink-muted">Evidence: {complaint.satisfaction_evidence}</p>}
            {complaint.satisfaction === null && <p className="text-xs text-ink-faint">Nothing is assumed: this stays "Not recorded" until someone writes down the feedback that was actually given.</p>}
            {canManage && !complaint.voided && (
              <div className="mt-2 space-y-2">
                <input aria-label="Evidence of the feedback" className={inputClasses} placeholder="What was said, by whom and when (at least 10 characters)" value={evidence} onChange={(e) => setEvidence(e.target.value)} />
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="outline" disabled={evidence.trim().length < 10} isLoading={saveSatisfaction.isPending} onClick={() => { setError(null); saveSatisfaction.mutate('satisfied'); }}>Record: satisfied</Button>
                  <Button size="sm" variant="outline" disabled={evidence.trim().length < 10} isLoading={saveSatisfaction.isPending} onClick={() => { setError(null); saveSatisfaction.mutate('not_satisfied'); }}>Record: not satisfied</Button>
                  {complaint.satisfaction !== null && (
                    <Button size="sm" variant="ghost" disabled={evidence.trim().length < 10} onClick={() => { setError(null); saveSatisfaction.mutate(null); }}>Clear (recorded by mistake)</Button>
                  )}
                </div>
              </div>
            )}
          </section>

          <section>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">History</h3>
            <ol className="space-y-1.5">
              {(complaint.events ?? []).map((event) => (
                <li key={event.id} className="text-xs text-ink-muted">
                  <span className="text-ink">{event.event.replace(/_/g, ' ')}</span>
                  {event.to_status ? ` → ${COMPLAINT_STATUS_LABELS[event.to_status as FacilityComplaintStatus] ?? event.to_status}` : ''}
                  {event.note ? ` · ${event.note}` : ''} · {event.by ?? 'system'} · {formatDateTime(event.at)}
                </li>
              ))}
            </ol>
          </section>

          {canManage && !complaint.voided && (
            <section className="border-t border-border pt-3">
              {voidReason === null ? (
                <button type="button" onClick={() => setVoidReason('')} className="text-xs font-semibold text-danger hover:underline">Void this complaint (registered by mistake)</button>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <input aria-label="Reason for voiding" className={`${inputClasses} flex-1`} placeholder="Why is it being voided? (at least 5 characters)" value={voidReason} onChange={(e) => setVoidReason(e.target.value)} />
                  <Button size="sm" variant="danger" disabled={voidReason.trim().length < 5} isLoading={voidIt.isPending} onClick={() => voidIt.mutate()}>Void</Button>
                  <Button size="sm" variant="outline" onClick={() => setVoidReason(null)}>Cancel</Button>
                </div>
              )}
            </section>
          )}
        </div>
      )}
    </Modal>
  );
}
