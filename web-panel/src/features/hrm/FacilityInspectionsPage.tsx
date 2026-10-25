import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
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
import { InspectionComplaintSection } from '@/features/hrm/InspectionComplaintSection';
import { DeepCleaningOverview } from '@/features/hrm/DeepCleaningOverview';
import { InspectionDeepCleaningSection } from '@/features/hrm/InspectionDeepCleaningSection';
import { PageHeader } from '@/components/ui/PageHeader';
import { Pagination } from '@/components/ui/Pagination';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useToast } from '@/components/ui/useToast';
import { useAuth } from '@/features/auth/useAuth';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { isDirty } from '@/utils/unsavedChanges';
import { pageFromParam, withParam } from '@/utils/urlFilters';
import {
  blankDeepCleaning,
  blankItems,
  blankUniformIssue,
  deepCleaningFromDetail,
  deepCleaningPayload,
  defaultInspectionDate,
  facilityToday,
  formatCalendarDate,
  validateDeepCleaning,
  type DeepCleaningFormState,
  complaintAnswerText,
  dateParam,
  issuesFromDetail,
  itemsFromDetail,
  complaintsRequest,
  complaintStatusTone,
  validateComplaintSection,
  type ComplaintSection,
  dueStateTone,
  dueText,
  itemsPayload,
  uniformPayload,
  validateItems,
  validateUniformIssues,
  type ItemChoice,
  type ItemFormState,
  type UniformIssueFormState,
} from '@/utils/facility';
import type { FacilityDueState, FacilityInspectionDetail, FacilityStatusRow } from '@/types/facility';

const STATE_FILTERS: { value: FacilityDueState | ''; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'due', label: 'Due' },
  { value: 'due_soon', label: 'Due soon' },
  { value: 'never_inspected', label: 'Never inspected' },
  { value: 'no_schedule', label: 'No frequency set' },
  { value: 'ok', label: 'Up to date' },
];

const CHOICES: { value: ItemChoice; label: string }[] = [
  { value: 'satisfactory', label: 'Clean / satisfactory' },
  { value: 'unsatisfactory', label: 'Dirty / unsatisfactory' },
  { value: 'not_applicable', label: 'Not applicable' },
];

/**
 * Facility Inspections: which companies/guri are due, overdue or never inspected (worked out from the stored inspections and each company's
 * own frequency when this page opens - nothing is sent automatically), the history of inspections, and the form that records a new one.
 */
export function FacilityInspectionsPage() {
  const { hasPermission } = useEffectivePermissions();
  const canManage = hasPermission('hr.inspections.manage');
  const [searchParams, setSearchParams] = useSearchParams();
  const stateFilter = (STATE_FILTERS.some((f) => f.value === searchParams.get('state')) ? (searchParams.get('state') ?? '') : '') as FacilityDueState | '';
  const companyParam = /^\d+$/.test(searchParams.get('company') ?? '') ? Number(searchParams.get('company')) : undefined;
  const from = dateParam(searchParams.get('from'));
  const to = dateParam(searchParams.get('to'));
  const search = searchParams.get('q') ?? '';
  const page = pageFromParam(searchParams.get('page'));
  const [recording, setRecording] = useState<{ companyId: number | null } | null>(null);
  const [scheduling, setScheduling] = useState<FacilityStatusRow | null>(null);
  const [viewing, setViewing] = useState<number | null>(null);
  const [correcting, setCorrecting] = useState<FacilityInspectionDetail | null>(null);

  const status = useQuery({
    queryKey: ['facility-status', stateFilter, search],
    queryFn: () => facilityApi.status({ state: stateFilter, search: search.trim() || undefined }),
  });
  // The tabs show counts of the whole list, whatever tab or search is active.
  const allStatus = useQuery({ queryKey: ['facility-status', '', ''], queryFn: () => facilityApi.status({}) });
  const counts = allStatus.data?.meta.counts;

  const history = useQuery({
    queryKey: ['facility-inspections', companyParam, from, to, page],
    queryFn: () => facilityApi.inspections.list({ client_company_id: companyParam, from: from || undefined, to: to || undefined, page, per_page: 10 }),
    placeholderData: (previous) => previous,
  });

  function setParam(key: string, value: string) {
    setSearchParams((previous) => withParam(previous, key, value), { replace: true });
  }
  const setSearch = (value: string) => setParam('q', value);
  const setPage = (next: number) => setParam('page', next <= 1 ? '' : String(next));

  const companyName = useMemo(() => allStatus.data?.rows.find((row) => row.client_company_id === companyParam)?.company, [allStatus.data, companyParam]);

  return (
    <div>
      <PageHeader
        title="Facility Inspections"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Companies & Workplaces', to: '/hr/companies' }, { label: 'Facility Inspections' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link to="/hr/facility-complaints" className="inline-flex h-10 items-center gap-1.5 rounded-sm border border-border bg-surface px-4 text-sm font-medium text-ink hover:bg-surface-alt">
              Complaints
            </Link>
            {hasPermission('hr.reports.view') && (
              <Link to="/hr/reports/facility" className="inline-flex h-10 items-center gap-1.5 rounded-sm border border-border bg-surface px-4 text-sm font-medium text-ink hover:bg-surface-alt">
                <Icon name="bar-chart" size={15} />
                Facility report
              </Link>
            )}
            {canManage && (
              <Button onClick={() => setRecording({ companyId: companyParam ?? null })}>
                <Icon name="plus" size={15} />
                Record inspection
              </Button>
            )}
          </div>
        }
      />

      <p className="mb-3 text-xs text-ink-muted">{status.data?.meta.reminders ?? 'Due and overdue states are calculated when this list is opened. No automatic reminder is sent.'}</p>

      <div role="group" aria-label="Filter by inspection state" className="mb-4 flex flex-wrap items-center gap-2">
        {STATE_FILTERS.map((option) => {
          const isActive = stateFilter === option.value;
          const count = counts === undefined ? undefined : option.value === '' ? counts.total : counts[option.value];

          return (
            <button
              key={option.value || 'all'}
              type="button"
              aria-pressed={isActive}
              onClick={() => setParam('state', option.value)}
              className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 ${
                isActive ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-ink-muted hover:border-primary/40 hover:text-primary'
              }`}
            >
              {option.label} {count !== undefined && <span className={isActive ? 'text-white/80' : 'text-ink-faint'}>({count})</span>}
            </button>
          );
        })}
        <input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search company…"
          aria-label="Search company"
          className={`${inputClasses} ml-auto w-52`}
        />
      </div>

      <Card className="mb-6">
        {status.isLoading && <LoadingState label="Loading inspection status…" />}
        {status.error && <ErrorState error={status.error} onRetry={status.refetch} />}
        {status.data && status.data.rows.length === 0 && <EmptyState icon="eye" title="No companies match" description="Change the filter or the search." />}
        {status.data && status.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                  <th className="px-4 py-2.5 font-semibold">Company / Guri</th>
                  <th className="px-4 py-2.5 font-semibold">Frequency</th>
                  <th className="px-4 py-2.5 font-semibold">Last inspected</th>
                  <th className="px-4 py-2.5 font-semibold">Next due</th>
                  <th className="px-4 py-2.5 font-semibold">Inspections</th>
                  <th className="px-4 py-2.5 font-semibold">State</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {status.data.rows.map((row) => (
                  <tr key={row.client_company_id}>
                    <td className="px-4 py-2.5 font-medium text-ink">
                      {row.company}
                      {row.category === 'guri' && <span className="ml-1.5 text-xs font-normal text-ink-faint">Guri</span>}
                    </td>
                    <td className="px-4 py-2.5 text-ink">{row.frequency_days ? `Every ${row.frequency_days} days` : <span className="text-ink-faint">Not set</span>}</td>
                    <td className="px-4 py-2.5 text-ink">{row.last_inspected_on ? formatDate(row.last_inspected_on) : <span className="text-ink-faint">Never</span>}</td>
                    <td className="px-4 py-2.5 text-ink">
                      {row.next_due_on ? formatDate(row.next_due_on) : '—'}
                      <span className="block text-xs text-ink-muted">{dueText(row.state, row.days_until_due)}</span>
                    </td>
                    <td className="px-4 py-2.5 text-ink">{row.inspection_count}</td>
                    <td className="px-4 py-2.5">
                      <StatusBadge status={row.state} label={row.state_label} tone={dueStateTone(row.state)} />
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center justify-end gap-1.5">
                        <Button variant="outline" size="sm" onClick={() => setParam('company', String(row.client_company_id))}>History</Button>
                        {canManage && (
                          <>
                            <Button variant="outline" size="sm" onClick={() => setScheduling(row)}>Frequency</Button>
                            <Button size="sm" onClick={() => setRecording({ companyId: row.client_company_id })}>Inspect</Button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <DeepCleaningOverview canManage={canManage} onRecord={(companyId) => setRecording({ companyId })} />

      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-display text-base font-bold text-ink">Inspection history{companyName ? ` - ${companyName}` : ''}</h2>
        {(from || to) && <span className="rounded-full bg-surface-alt px-3 py-1 text-xs text-ink-muted" data-testid="period-filter">Inspected {from ? formatDate(from) : 'from the start'} to {to ? formatDate(to) : 'today'}</span>}
        {companyParam !== undefined && (
          <button type="button" onClick={() => setParam('company', '')} className="text-xs font-semibold text-primary hover:underline">
            Show all companies
          </button>
        )}
      </div>
      <Card>
        {history.isLoading && <LoadingState label="Loading inspections…" />}
        {history.error && <ErrorState error={history.error} onRetry={history.refetch} />}
        {history.data && history.data.data.length === 0 && <EmptyState icon="eye" title="No inspections recorded yet" />}
        {history.data && history.data.data.length > 0 && (
          <div className="divide-y divide-border">
            {history.data.data.map((inspection) => (
              <button key={inspection.id} type="button" onClick={() => setViewing(inspection.id)} className="flex w-full flex-wrap items-center justify-between gap-2 px-5 py-3 text-left hover:bg-surface-alt">
                <div>
                  <p className="text-sm font-medium text-ink">
                    {inspection.company} <span className="font-normal text-ink-muted">· {formatDate(inspection.inspected_on)}</span>
                  </p>
                  <p className="text-xs text-ink-muted">Inspector: {inspection.inspector_name}</p>
                </div>
                <div className="flex items-center gap-2">
                  {inspection.unsatisfactory_count > 0 ? (
                    <StatusBadge status="issues" tone="danger" label={`${inspection.unsatisfactory_count} unsatisfactory`} />
                  ) : (
                    <StatusBadge status="clean" tone="success" label="No issues" />
                  )}
                  {inspection.uniform_issue_count > 0 && <StatusBadge status="uniform" tone="warning" label={`${inspection.uniform_issue_count} uniform`} />}
                  {inspection.staff_complaint_count > 0 && <StatusBadge status="staff-complaint" tone="danger" label={`${inspection.staff_complaint_count} staff complaint${inspection.staff_complaint_count === 1 ? '' : 's'}`} />}
                  {inspection.customer_complaint_count > 0 && <StatusBadge status="customer-complaint" tone="danger" label={`${inspection.customer_complaint_count} customer complaint${inspection.customer_complaint_count === 1 ? '' : 's'}`} />}
                  {inspection.voided && <StatusBadge status="voided" tone="neutral" label="Voided" />}
                </div>
              </button>
            ))}
          </div>
        )}
        {history.data && history.data.meta.total > 0 && <Pagination meta={history.data.meta} onPageChange={setPage} />}
      </Card>

      {recording && <RecordInspectionModal initialCompanyId={recording.companyId} onClose={() => setRecording(null)} />}
      {correcting && <RecordInspectionModal initialCompanyId={correcting.client_company_id} editing={correcting} onClose={() => setCorrecting(null)} />}
      {scheduling && <ScheduleModal row={scheduling} onClose={() => setScheduling(null)} />}
      {viewing !== null && (
        <InspectionDetailModal
          id={viewing}
          canManage={canManage}
          onClose={() => setViewing(null)}
          onEdit={(detail) => {
            setViewing(null);
            setCorrecting(detail);
          }}
        />
      )}
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

function ScheduleModal({ row, onClose }: { row: FacilityStatusRow; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [days, setDays] = useState(row.frequency_days ? String(row.frequency_days) : '');
  const [soon, setSoon] = useState(row.due_soon_days !== null ? String(row.due_soon_days) : '');
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => facilityApi.setSchedule(row.client_company_id, { frequency_days: Number(days), due_soon_days: soon === '' ? null : Number(soon) }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['facility-status'] });
      show('Inspection frequency saved.');
      onClose();
    },
    onError: (err) => setError(friendlyErrorMessage(err, 'Could not save the frequency.')),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`Inspection frequency - ${row.company}`}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={save.isPending} disabled={!/^\d+$/.test(days) || Number(days) < 1} onClick={() => save.mutate()}>Save</Button>
        </>
      }
    >
      <Banner message={error} />
      <FormField label="Inspect every (days)" htmlFor="freq-days" required hint="For example 30 for a monthly inspection. The next due date is counted from the last inspection.">
        <input id="freq-days" type="number" min={1} max={730} className={inputClasses} value={days} onChange={(e) => setDays(e.target.value)} />
      </FormField>
      <FormField label="Show as 'Due soon' this many days before" htmlFor="freq-soon" hint="Optional. Leave empty for the default.">
        <input id="freq-soon" type="number" min={0} max={60} className={inputClasses} value={soon} onChange={(e) => setSoon(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/** Records a new inspection, or - with `editing` - corrects a saved one (header, checklist, uniform issues; complaints are managed on the Complaints screen). */
function RecordInspectionModal({ initialCompanyId, editing, onClose }: { initialCompanyId: number | null; editing?: FacilityInspectionDetail; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { admin } = useAuth();
  const [companyId, setCompanyId] = useState(initialCompanyId === null ? '' : String(initialCompanyId));
  const [inspectedOn, setInspectedOn] = useState(defaultInspectionDate(editing?.inspected_on));
  const [inspector, setInspector] = useState(editing?.inspector_name ?? admin?.full_name ?? '');
  const [notes, setNotes] = useState(editing?.general_notes ?? '');
  const [followUp, setFollowUp] = useState(editing?.next_follow_up_on ?? '');
  const [items, setItems] = useState<ItemFormState[]>(editing ? itemsFromDetail(editing.items) : blankItems());
  const [issues, setIssues] = useState<UniformIssueFormState[]>(editing ? issuesFromDetail(editing.uniform_issues) : []);
  const [staff, setStaff] = useState<ComplaintSection>({ answer: 'unanswered', rows: [] });
  const [customer, setCustomer] = useState<ComplaintSection>({ answer: 'unanswered', rows: [] });
  const [deepCleaning, setDeepCleaning] = useState<DeepCleaningFormState>(editing ? deepCleaningFromDetail(editing.deep_cleaning) : blankDeepCleaning());
  const [showErrors, setShowErrors] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const snapshot = () => ({ companyId, inspectedOn, inspector, notes, followUp, items, issues, staff, customer, deepCleaning });
  const initial = useRef(snapshot());
  const dirty = isDirty(initial.current, snapshot());

  const { data: companies } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: employees } = useQuery({
    queryKey: ['facility-company-employees', companyId, inspectedOn],
    queryFn: () => facilityApi.inspections.companyEmployees(Number(companyId), inspectedOn),
    enabled: companyId !== '',
  });

  // Only people who work for the chosen company can be named: a different company means a different list, so any chosen names are cleared.
  useEffect(() => {
    if (editing) return; // a saved inspection never changes company
    setIssues((current) => current.map((row) => ({ ...row, employeeId: '' })));
    setStaff((current) => ({ ...current, rows: current.rows.map((row) => ({ ...row, employeeId: '' })) }));
  }, [companyId, editing]);

  const itemProblems = validateItems(items);
  const issueProblems = validateUniformIssues(issues);
  const staffProblems = editing ? {} : validateComplaintSection(staff, 'staff');
  const customerProblems = editing ? {} : validateComplaintSection(customer, 'customer');
  const headerProblems: string[] = [];
  if (companyId === '') headerProblems.push('Choose the company or workplace.');
  if (inspector.trim() === '') headerProblems.push('Enter the inspector name.');
  if (inspectedOn === '') headerProblems.push('Enter the inspection date.');
  if (followUp !== '' && inspectedOn !== '' && followUp < inspectedOn) headerProblems.push('The follow-up date cannot be before the inspection date.');
  const deepCleaningProblems = validateDeepCleaning(deepCleaning, editing?.deep_cleaning ?? null, facilityToday());
  const valid = headerProblems.length === 0 && deepCleaningProblems.length === 0 && Object.keys(itemProblems).length === 0 && Object.keys(issueProblems).length === 0 && Object.keys(staffProblems).length === 0 && Object.keys(customerProblems).length === 0;

  // The Deep Cleaning section is sent only when the user recorded, changed or withdrew something; otherwise the inspection says nothing about it.
  const deepCleaningInput = deepCleaningPayload(deepCleaning, editing?.deep_cleaning ?? null);

  const save = useMutation({
    mutationFn: () =>
      editing
        ? facilityApi.inspections.update(editing.id, {
            inspector_name: inspector.trim(),
            inspected_on: inspectedOn,
            general_notes: notes.trim() === '' ? null : notes.trim(),
            next_follow_up_on: followUp === '' ? null : followUp,
            items: itemsPayload(items),
            uniform_issues: uniformPayload(issues),
            ...(deepCleaningInput ? { deep_cleaning: deepCleaningInput } : {}),
          })
        : facilityApi.inspections.create({
        client_company_id: Number(companyId),
        inspector_name: inspector.trim(),
        inspected_on: inspectedOn,
        general_notes: notes.trim() === '' ? null : notes.trim(),
        next_follow_up_on: followUp === '' ? null : followUp,
        items: itemsPayload(items),
        uniform_issues: uniformPayload(issues),
        ...complaintsRequest(staff, customer),
        ...(deepCleaningInput ? { deep_cleaning: deepCleaningInput } : {}),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['facility-inspection'] });
      queryClient.invalidateQueries({ queryKey: ['facility-deep-cleaning'] });
      queryClient.invalidateQueries({ queryKey: ['facility-status'] });
      queryClient.invalidateQueries({ queryKey: ['facility-inspections'] });
      queryClient.invalidateQueries({ queryKey: ['facility-report'] });
      queryClient.invalidateQueries({ queryKey: ['facility-complaints'] });
      show(editing ? 'Inspection corrected.' : 'Inspection recorded.');
      onClose();
    },
    onError: (err) => setServerError(friendlyErrorMessage(err, 'Could not record the inspection. Nothing was saved.')),
  });

  const submit = (event?: FormEvent) => {
    event?.preventDefault();
    setServerError(null);
    setShowErrors(true);
    if (valid) save.mutate();
  };

  const updateItem = (key: string, patch: Partial<ItemFormState>) => setItems((current) => current.map((item) => (item.key === key ? { ...item, ...patch } : item)));
  const updateIssue = (index: number, patch: Partial<UniformIssueFormState>) => setIssues((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)));

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={editing ? 'Edit facility inspection' : 'Record facility inspection'}
      size="lg"
      confirmDiscard
      dirty={dirty}
      footer={
        <>
          <ModalCancelButton disabled={save.isPending} />
          <Button size="sm" onClick={() => submit()} isLoading={save.isPending}>{editing ? 'Save changes' : 'Save inspection'}</Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <Banner message={serverError} />
        {showErrors && headerProblems.length > 0 && <Banner message={headerProblems.join(' ')} />}
        <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
          <FormField label="Company / Guri" htmlFor="insp-company" required>
            <Select
              id="insp-company"
              disabled={editing !== undefined}
              value={companyId}
              onChange={(e) => setCompanyId(e.target.value)}
              placeholder="Choose…"
              options={(companies ?? []).filter((c) => c.status === 'active').map((c) => ({ value: String(c.id), label: c.category === 'guri' ? `${c.name} (Guri)` : c.name }))}
            />
          </FormField>
          <FormField label="Inspection date" htmlFor="insp-date" required>
            <input id="insp-date" type="date" max={facilityToday()} className={inputClasses} value={inspectedOn} onChange={(e) => setInspectedOn(e.target.value)} />
          </FormField>
          <FormField label="Inspector" htmlFor="insp-inspector" required>
            <input id="insp-inspector" className={inputClasses} value={inspector} onChange={(e) => setInspector(e.target.value)} />
          </FormField>
          <FormField label="Next follow-up date" htmlFor="insp-followup" hint="Optional. Overrides the usual frequency for this company.">
            <input id="insp-followup" type="date" className={inputClasses} value={followUp} onChange={(e) => setFollowUp(e.target.value)} />
          </FormField>
        </div>

        <h3 className="mb-2 mt-2 font-display text-sm font-bold text-ink">Checklist</h3>
        <div className="mb-4 divide-y divide-border rounded-md border border-border">
          {items.map((item) => (
            <div key={item.key} className="px-3.5 py-2.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium text-ink">{item.label}</p>
                <div role="radiogroup" aria-label={item.label} className="flex flex-wrap gap-1.5">
                  {CHOICES.map((choice) => (
                    <button
                      key={choice.value}
                      type="button"
                      role="radio"
                      aria-checked={item.choice === choice.value}
                      onClick={() => updateItem(item.key, { choice: choice.value })}
                      className={`rounded-full border px-2.5 py-1 text-xs font-medium transition ${
                        item.choice === choice.value
                          ? choice.value === 'unsatisfactory'
                            ? 'border-danger bg-danger text-white'
                            : 'border-primary bg-primary text-white'
                          : 'border-border bg-surface text-ink-muted hover:border-primary/40'
                      }`}
                    >
                      {choice.label}
                    </button>
                  ))}
                </div>
              </div>
              {item.choice === 'unsatisfactory' && (
                <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <input aria-label={`${item.label} - issue found`} className={inputClasses} placeholder="Issue found" value={item.issue} onChange={(e) => updateItem(item.key, { issue: e.target.value })} />
                  <input aria-label={`${item.label} - corrective action`} className={inputClasses} placeholder="Corrective action" value={item.action} onChange={(e) => updateItem(item.key, { action: e.target.value })} />
                </div>
              )}
              {showErrors && itemProblems[item.key] && <p className="mt-1 text-xs text-danger">{itemProblems[item.key]}</p>}
            </div>
          ))}
        </div>

        <div className="mb-2 flex items-center justify-between">
          <h3 className="font-display text-sm font-bold text-ink">Uniform issues</h3>
          <Button variant="outline" size="sm" onClick={() => setIssues((current) => [...current, blankUniformIssue()])} disabled={companyId === ''}>
            <Icon name="plus" size={14} />
            Add employee
          </Button>
        </div>
        {companyId === '' && <p className="mb-3 text-xs text-ink-faint">Choose the company first - only people who work for it can be listed.</p>}
        {issues.length === 0 && companyId !== '' && <p className="mb-3 text-xs text-ink-faint">No uniform issues recorded. Add an employee only if their uniform was not worn properly.</p>}
        {issues.map((row, index) => (
          <div key={index} className="mb-3 rounded-md border border-border px-3.5 py-3">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <Select
                aria-label="Employee"
                value={row.employeeId}
                onChange={(e) => updateIssue(index, { employeeId: e.target.value })}
                placeholder="Employee at this company…"
                options={(employees ?? []).map((e) => ({ value: String(e.id), label: `${e.full_name} (${e.employee_number})` }))}
              />
              <input aria-label="Uniform issue" className={inputClasses} placeholder="Uniform issue" value={row.issue} onChange={(e) => updateIssue(index, { issue: e.target.value })} />
              <input aria-label="Reason the uniform was not worn" className={inputClasses} placeholder="Reason not worn (if known)" value={row.reasonNotWorn} onChange={(e) => updateIssue(index, { reasonNotWorn: e.target.value })} />
              <input aria-label="Corrective action" className={inputClasses} placeholder="Corrective action" value={row.action} onChange={(e) => updateIssue(index, { action: e.target.value })} />
              <label className="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" checked={row.warningGiven} onChange={(e) => updateIssue(index, { warningGiven: e.target.checked })} />
                Warning given
              </label>
              <div className="flex items-center gap-2">
                <span className="text-xs text-ink-muted">Follow up on</span>
                <input aria-label="Follow-up date" type="date" className={inputClasses} value={row.followUpOn} onChange={(e) => updateIssue(index, { followUpOn: e.target.value })} />
              </div>
            </div>
            <div className="mt-2 flex items-center justify-between">
              {showErrors && issueProblems[index] ? <p className="text-xs text-danger">{issueProblems[index]}</p> : <span />}
              <button type="button" onClick={() => setIssues((current) => current.filter((_, i) => i !== index))} className="text-xs font-semibold text-danger hover:underline">
                Remove
              </button>
            </div>
          </div>
        ))}

        {editing ? (
          <div className="mb-4 rounded-md border border-border bg-surface-alt/50 px-3.5 py-3 text-sm text-ink-muted" data-testid="complaints-managed-elsewhere">
            {complaintAnswerText(editing.staff_complaints_reported, editing.staff_complaint_count, 'staff')} · {complaintAnswerText(editing.customer_complaints_reported, editing.customer_complaint_count, 'customer')}.{' '}
            Complaints are changed on the <Link to={`/hr/facility-complaints?company=${editing.client_company_id}`} className="font-semibold text-primary hover:underline">Complaints screen</Link>, where each one keeps its own history.
          </div>
        ) : (
          <>
        <InspectionComplaintSection type="staff" section={staff} onChange={setStaff} employees={employees ?? []} companyChosen={companyId !== ''} inspectionDate={inspectedOn} problems={staffProblems} showErrors={showErrors} />
        <InspectionComplaintSection type="customer" section={customer} onChange={setCustomer} employees={employees ?? []} companyChosen={companyId !== ''} inspectionDate={inspectedOn} problems={customerProblems} showErrors={showErrors} />
          </>
        )}

        <InspectionDeepCleaningSection form={deepCleaning} onChange={setDeepCleaning} saved={editing?.deep_cleaning ?? null} problems={deepCleaningProblems} showErrors={showErrors} />

        <FormField label="Notes" htmlFor="insp-notes">
          <textarea id="insp-notes" rows={3} className={`${inputClasses} h-auto py-2`} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}

function InspectionDetailModal({ id, canManage, onClose, onEdit }: { id: number; canManage: boolean; onClose: () => void; onEdit: (detail: FacilityInspectionDetail) => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [reason, setReason] = useState('');
  const [voiding, setVoiding] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading, error: loadError } = useQuery({ queryKey: ['facility-inspection', id], queryFn: () => facilityApi.inspections.get(id) });

  const voidIt = useMutation({
    mutationFn: () => facilityApi.inspections.void(id, reason.trim()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['facility-status'] });
      queryClient.invalidateQueries({ queryKey: ['facility-deep-cleaning'] });
      queryClient.invalidateQueries({ queryKey: ['facility-inspections'] });
      queryClient.invalidateQueries({ queryKey: ['facility-inspection', id] });
      queryClient.invalidateQueries({ queryKey: ['facility-report'] });
      show('Inspection voided. It is kept on record but no longer counted.');
      onClose();
    },
    onError: (err) => setError(friendlyErrorMessage(err, 'Could not void the inspection.')),
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={data ? `Inspection - ${data.company ?? ''}, ${formatDate(data.inspected_on)}` : 'Inspection'}
      size="lg"
      footer={
        data && canManage && !data.voided ? (
          voiding ? (
            <>
              <Button variant="outline" size="sm" onClick={() => setVoiding(false)}>Keep it</Button>
              <Button variant="danger" size="sm" isLoading={voidIt.isPending} disabled={reason.trim().length < 5} onClick={() => voidIt.mutate()}>Void inspection</Button>
            </>
          ) : (
            <>
              <Button variant="outline" size="sm" onClick={() => setVoiding(true)}>Void this inspection</Button>
              <Button size="sm" onClick={() => onEdit(data)}>Edit inspection</Button>
            </>
          )
        ) : undefined
      }
    >
      {isLoading && <LoadingState label="Loading inspection…" />}
      {loadError && <ErrorState error={loadError} />}
      {data && (
        <div className="space-y-4 text-sm">
          <Banner message={error} />
          {data.voided && (
            <div className="rounded-md border border-border bg-surface-alt px-3.5 py-2.5 text-ink-muted">
              Voided{data.void_reason ? `: ${data.void_reason}` : ''}. This inspection is kept on record but is not counted.
            </div>
          )}
          <dl className="grid grid-cols-2 gap-3">
            <div><dt className="text-xs text-ink-faint">Inspector</dt><dd className="text-ink">{data.inspector_name}</dd></div>
            <div><dt className="text-xs text-ink-faint">Recorded by</dt><dd className="text-ink">{data.recorded_by ?? '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Next follow-up</dt><dd className="text-ink">{data.next_follow_up_on ? formatDate(data.next_follow_up_on) : '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Workplace</dt><dd className="text-ink">{data.workplace ?? '—'}</dd></div>
          </dl>
          <div className="divide-y divide-border rounded-md border border-border">
            {data.items.map((item) => (
              <div key={item.item_key} className="flex flex-wrap items-start justify-between gap-2 px-3.5 py-2">
                <div>
                  <p className="font-medium text-ink">{item.label}</p>
                  {item.result === 'unsatisfactory' && (
                    <p className="text-xs text-ink-muted">
                      Issue: {item.issue_description} · Action: {item.corrective_action}
                    </p>
                  )}
                </div>
                {!item.applicable ? (
                  <StatusBadge status="na" tone="neutral" label="Not applicable" />
                ) : item.result === 'unsatisfactory' ? (
                  <StatusBadge status="bad" tone="danger" label="Unsatisfactory" />
                ) : (
                  <StatusBadge status="good" tone="success" label="Satisfactory" />
                )}
              </div>
            ))}
          </div>
          <div data-testid="deep-cleaning-detail">
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Deep Cleaning</h3>
            {data.deep_cleaning === null ? (
              <p className="text-xs text-ink-muted">Not recorded in this inspection.</p>
            ) : data.deep_cleaning.withdrawn ? (
              <p className="text-xs text-ink-muted">Recorded for {formatCalendarDate(data.deep_cleaning.completed_on)}, then withdrawn{data.deep_cleaning.withdrawn_reason ? `: ${data.deep_cleaning.withdrawn_reason}` : ''}. Kept on record, not counted.</p>
            ) : (
              <p className="text-xs text-ink-muted">
                Completed on {formatCalendarDate(data.deep_cleaning.completed_on)}
                {data.deep_cleaning.recorded_by ? ` · recorded by ${data.deep_cleaning.recorded_by}` : ''}
                {data.deep_cleaning.notes ? ` · ${data.deep_cleaning.notes}` : ''}
                {data.deep_cleaning.changed_by ? ` · changed by ${data.deep_cleaning.changed_by}${data.deep_cleaning.change_reason ? ` (${data.deep_cleaning.change_reason})` : ''}` : ''}
              </p>
            )}
          </div>
          {data.uniform_issues.length > 0 && (
            <div>
              <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Uniform issues</h3>
              <div className="divide-y divide-border rounded-md border border-border">
                {data.uniform_issues.map((row) => (
                  <div key={row.id} className="px-3.5 py-2">
                    <p className="font-medium text-ink">
                      {row.employee_name} <span className="font-normal text-ink-muted">({row.employee_number})</span>
                    </p>
                    <p className="text-xs text-ink-muted">
                      {row.issue}
                      {row.reason_not_worn ? ` · Reason: ${row.reason_not_worn}` : ''}
                      {row.corrective_action ? ` · Action: ${row.corrective_action}` : ''}
                      {row.warning_given ? ' · Warning given' : ''}
                      {row.follow_up_on ? ` · Follow up ${formatDate(row.follow_up_on)}` : ''}
                    </p>
                  </div>
                ))}
              </div>
            </div>
          )}
          <div>
            <h3 className="mb-1.5 font-display text-sm font-bold text-ink">Staff and customer complaints</h3>
            <p className="text-xs text-ink-muted">
              {complaintAnswerText(data.staff_complaints_reported, data.staff_complaint_count, 'staff')} · {complaintAnswerText(data.customer_complaints_reported, data.customer_complaint_count, 'customer')}
            </p>
            {data.complaints.length > 0 && (
              <div className="mt-2 divide-y divide-border rounded-md border border-border" data-testid="inspection-complaints">
                {data.complaints.map((c) => (
                  <div key={c.id} className="px-3.5 py-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-medium text-ink">{c.type_label} <span className="font-normal text-ink-muted">· {c.reporter_employee ?? c.reporter_name ?? 'reporter not named'}</span></p>
                      <StatusBadge status={c.status} label={c.status_label} tone={complaintStatusTone(c.status)} />
                    </div>
                    <p className="text-xs text-ink-muted">{c.details}</p>
                    <p className="text-xs text-ink-faint">
                      Responsible: {c.responsible_person ?? '-'}{c.response ? ` · Response: ${c.response}` : ''}{c.corrective_action ? ` · Action: ${c.corrective_action}` : ''}{c.follow_up_on ? ` · Follow up ${formatDate(c.follow_up_on)}` : ''} · Satisfaction: {c.satisfaction_label}
                    </p>
                    <Link to={`/hr/facility-complaints?company=${c.client_company_id}`} className="text-xs font-semibold text-primary hover:underline">Open on the Complaints screen</Link>
                  </div>
                ))}
              </div>
            )}
          </div>
          {data.general_notes && <p className="text-ink-muted">Notes: {data.general_notes}</p>}
          {voiding && (
            <FormField label="Why is this inspection being voided?" htmlFor="void-reason" required hint="At least 5 characters. It is kept in the audit history.">
              <input id="void-reason" className={inputClasses} value={reason} onChange={(e) => setReason(e.target.value)} />
            </FormField>
          )}
        </div>
      )}
    </Modal>
  );
}
