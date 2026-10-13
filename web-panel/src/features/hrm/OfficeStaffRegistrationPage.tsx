import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { SearchInput } from '@/components/ui/SearchInput';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { duplicateMatchesFromError, friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { OFFICE_STAFF_CATEGORY } from '@/utils/employeeCategories';
import {
  ALLOWED_DOCUMENT_EXTENSIONS,
  buildPayload,
  DEFAULT_DOCUMENT_GROUPS,
  documentFileError,
  documentSummary,
  emptyForm,
  formFromSummary,
  needsAdditionalAssignmentConfirmation,
  overwriteConflicts,
  overwriteConflictsFromError,
  uploadPlan,
  validateOfficeStaffForm,
  type OfficeStaffDocumentGroup,
  type OfficeStaffForm,
  type OfficeStaffMode,
  type OfficeStaffSummary,
  type OverwriteField,
} from '@/utils/officeStaff';

const OVERWRITE_LABEL: Record<OverwriteField, string> = {
  emergency_contact: 'emergency contact',
  guarantor: 'guarantor',
  position: 'position',
};

type StagedFiles = Partial<Record<OfficeStaffDocumentGroup['key'], File | null>>;

/** Add an existing employee to Office Staff, or register a new one straight into it. */
export function OfficeStaffRegistrationPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const today = localToday();

  const [mode, setMode] = useState<OfficeStaffMode>('existing');
  const [form, setForm] = useState<OfficeStaffForm>(emptyForm(today));
  const [showErrors, setShowErrors] = useState(false);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<OfficeStaffSummary | null>(null);
  const [confirmOverwrite, setConfirmOverwrite] = useState<OverwriteField[]>([]);
  const [confirmAdditional, setConfirmAdditional] = useState(false);
  const [duplicateMatches, setDuplicateMatches] = useState<ReturnType<typeof duplicateMatchesFromError>>(null);
  const [duplicateReason, setDuplicateReason] = useState('');
  const [staged, setStaged] = useState<StagedFiles>({});
  const [stagedErrors, setStagedErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState<{ summary: OfficeStaffSummary; uploadFailures: string[] } | null>(null);
  const debouncedSearch = useDebouncedValue(search);

  const { data: positions } = useQuery({ queryKey: ['positions'], queryFn: hrApi.positions.list });
  const { data: documentCategories } = useQuery({ queryKey: ['employee-document-categories'], queryFn: hrApi.documentCategories.list });
  const { data: matches, isFetching: isSearching } = useQuery({
    queryKey: ['office-staff-lookup', debouncedSearch],
    queryFn: () => hrApi.officeStaff.lookup(debouncedSearch),
    enabled: mode === 'existing' && debouncedSearch.trim().length >= 2,
  });

  const categoryIdByName = useMemo(() => Object.fromEntries((documentCategories ?? []).map((c) => [c.name, c.id])), [documentCategories]);
  const errors = showErrors ? validateOfficeStaffForm(form, mode) : {};
  const pending = selected && mode === 'existing' ? overwriteConflicts(selected, form) : [];
  const unconfirmed = pending.filter((field) => !confirmOverwrite.includes(field));
  const needsAdditional = mode === 'existing' && selected !== null && selected.active_assignments.length > 0;

  const setField = (field: keyof OfficeStaffForm) => (value: string) => setForm((current) => ({ ...current, [field]: value }));

  // Switching tabs or person must never carry confirmations over: each one belongs to one specific record.
  useEffect(() => {
    setConfirmOverwrite([]);
    setConfirmAdditional(false);
    setDuplicateMatches(null);
    setDuplicateReason('');
  }, [mode, selected?.id]);

  const choose = (summary: OfficeStaffSummary) => {
    setSelected(summary);
    setForm(formFromSummary(summary, today));
    setShowErrors(false);
  };

  const reset = () => {
    setSaved(null);
    setSelected(null);
    setForm(emptyForm(today));
    setStaged({});
    setStagedErrors({});
    setSearch('');
    setShowErrors(false);
  };

  const saveMutation = useMutation({
    mutationFn: async () => {
      const options = {
        confirmOverwrite,
        confirmAdditionalAssignment: confirmAdditional,
        confirmDuplicate: duplicateMatches && duplicateMatches.length > 0 ? { reason: duplicateReason } : null,
      };
      if (mode === 'existing') return { summary: await hrApi.officeStaff.assignExisting(buildPayload('existing', form, selected?.id ?? null, options)), uploadFailures: [] as string[] };

      const summary = await hrApi.officeStaff.registerNew(buildPayload('new', form, null, options));
      const uploadFailures: string[] = [];
      const uploads = uploadPlan(staged, categoryIdByName, DEFAULT_DOCUMENT_GROUPS);
      for (const upload of uploads) {
        try {
          await hrApi.employees.uploadDocument(summary.id, upload.file, { employee_document_category_id: upload.categoryId });
        } catch (error) {
          uploadFailures.push(`${upload.file.name}: ${friendlyErrorMessage(error, 'could not be uploaded')}`);
        }
      }

      // Re-read after any upload attempt so the saved screen shows what is really on file, not the pre-upload snapshot.
      return { summary: uploads.length > 0 ? await hrApi.officeStaff.get(summary.id) : summary, uploadFailures };
    },
    onSuccess: (result) => {
      setSaved(result);
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      queryClient.invalidateQueries({ queryKey: ['hr-dashboard'] });
      queryClient.invalidateQueries({ queryKey: ['office-staff-lookup'] });
      show(mode === 'new' ? 'Office Staff registered.' : 'Employee added to Office Staff.');
    },
    onError: (error) => {
      const conflicts = overwriteConflictsFromError(error);
      if (conflicts !== null) {
        show(`Some ${conflicts.map((c) => OVERWRITE_LABEL[c]).join(', ')} details are already recorded and differ. Nothing was changed - confirm each replacement below, or keep the existing value.`, 'error');
        return;
      }
      if (needsAdditionalAssignmentConfirmation(error)) {
        show('This employee already has an assignment. Tick the confirmation below to add Office Staff as a second assignment.', 'error');
        return;
      }
      const duplicates = duplicateMatchesFromError(error);
      if (duplicates !== null) {
        setDuplicateMatches(duplicates);
        show('An employee with this phone number already exists.', 'error');
        return;
      }
      show(friendlyErrorMessage(error, 'Could not save.'), 'error');
    },
  });

  const submit = () => {
    setShowErrors(true);
    if (Object.keys(validateOfficeStaffForm(form, mode)).length > 0) return;
    if (mode === 'existing' && (selected === null || !selected.can_add)) return;
    if (unconfirmed.length > 0 || (needsAdditional && !confirmAdditional)) return;
    if (duplicateMatches && duplicateMatches.length > 0 && duplicateReason.trim().length < 10) return;
    saveMutation.mutate();
  };

  const stage = (key: OfficeStaffDocumentGroup['key'], file: File | null) => {
    const problem = file ? documentFileError(file) : null;
    setStagedErrors((current) => ({ ...current, [key]: problem ?? '' }));
    setStaged((current) => ({ ...current, [key]: problem ? null : file }));
  };

  if (saved) {
    return <SavedPanel saved={saved} categoryIdByName={categoryIdByName} onAnother={reset} />;
  }

  return (
    <div>
      <PageHeader
        title="Add Office Staff"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Office Staff', to: '/hr/office-staff' }, { label: 'Add' }]}
      />

      <div role="tablist" aria-label="How to add Office Staff" className="mb-4 flex flex-wrap gap-2">
        {([['existing', 'Add existing employee'], ['new', 'Register new Office Staff']] as const).map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={mode === value}
            onClick={() => { setMode(value); setShowErrors(false); }}
            className={`rounded-full border px-4 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 ${
              mode === value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-ink-muted hover:border-primary/40 hover:text-primary'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {mode === 'existing' && (
        <Card className="mb-4">
          <CardHeader><h3 className="font-display text-sm font-bold text-ink">Find the employee</h3></CardHeader>
          <CardBody>
            <SearchInput value={search} onChange={setSearch} placeholder="Search by name, phone number or employee number…" className="w-full max-w-md" />
            {isSearching && <p className="mt-2 text-xs text-ink-muted">Searching…</p>}
            {matches && matches.length === 0 && debouncedSearch.trim().length >= 2 && <p className="mt-3 text-sm text-ink-muted">No employee found. Check the spelling, or use "Register new Office Staff".</p>}
            <ul className="mt-3 flex flex-col gap-2">
              {(matches ?? []).map((candidate) => (
                <li key={candidate.id}>
                  <button
                    type="button"
                    onClick={() => choose(candidate)}
                    aria-pressed={selected?.id === candidate.id}
                    className={`w-full rounded-md border p-3 text-left transition hover:border-primary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary ${selected?.id === candidate.id ? 'border-primary bg-primary-soft' : 'border-border bg-surface'}`}
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-ink">{candidate.full_name}</span>
                      <span className="text-xs text-ink-muted">{candidate.employee_number}</span>
                      <StatusBadge status={candidate.status} />
                      {candidate.is_office_staff && <StatusBadge status="office" label="Already Office Staff" tone="info" />}
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      {candidate.phone ?? 'No phone'} · {candidate.position_name ?? 'No position'} ·{' '}
                      {candidate.current_salary ? `${candidate.current_salary.amount} ${candidate.current_salary.currency}/month` : 'No salary recorded'} · {documentSummary(candidate.documents)}
                    </p>
                  </button>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      )}

      {mode === 'existing' && selected && !selected.can_add && (
        <div role="alert" className="mb-4 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">{selected.cannot_add_reason}</div>
      )}

      {(mode === 'new' || (selected && selected.can_add)) && (
        <>
          <Card className="mb-4">
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">1. Employee information</h3></CardHeader>
            <CardBody>
              <div className="grid gap-x-4 sm:grid-cols-2">
                {mode === 'new' ? (
                  <>
                    <TextField id="os-name" label="Full name" required value={form.full_name} onChange={setField('full_name')} error={errors.full_name} />
                    <FormField label="Employee number" htmlFor="os-number" hint="Assigned automatically when you save, using the usual EMP-000000 numbering.">
                      <input id="os-number" className={inputClasses} value="Assigned on save" disabled readOnly />
                    </FormField>
                    <TextField id="os-phone" label="Phone number" required value={form.phone} onChange={setField('phone')} error={errors.phone} />
                    <FormField label="Employee category" htmlFor="os-category" hint="Set automatically for everyone registered as Office Staff.">
                      <input id="os-category" className={inputClasses} value={OFFICE_STAFF_CATEGORY} disabled readOnly />
                    </FormField>
                  </>
                ) : (
                  <>
                    <FormField label="Full name" htmlFor="os-name"><input id="os-name" className={inputClasses} value={selected?.full_name ?? ''} disabled readOnly /></FormField>
                    <FormField label="Employee number" htmlFor="os-number" hint="The existing number is kept."><input id="os-number" className={inputClasses} value={selected?.employee_number ?? ''} disabled readOnly /></FormField>
                    <FormField label="Employee category" htmlFor="os-category" hint="Unchanged: adding Office Staff never changes an employee's category."><input id="os-category" className={inputClasses} value={selected?.category_name ?? '—'} disabled readOnly /></FormField>
                  </>
                )}
                <FormField label="Position / job title" htmlFor="os-position" required error={errors.position_id} hint={mode === 'existing' && selected?.position_name ? `Currently recorded: ${selected.position_name}` : 'Positions are managed under Human Resources > Positions.'}>
                  <Select id="os-position" value={form.position_id} onChange={(e) => setField('position_id')(e.target.value)} options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))} placeholder="Choose a position…" />
                </FormField>
                <TextField
                  id="os-salary" label="Monthly salary (USD)" required inputMode="decimal" value={form.monthly_salary} onChange={setField('monthly_salary')} error={errors.monthly_salary}
                  hint={mode === 'existing' && selected?.current_salary ? `Current salary on record: ${selected.current_salary.amount} ${selected.current_salary.currency}. Confirm or change it. Saving does not pay anyone or create payroll.` : 'Saving a salary does not pay anyone or create payroll.'}
                />
                <FormField label="Start date" htmlFor="os-start" required error={errors.start_date}>
                  <input id="os-start" type="date" className={inputClasses} value={form.start_date} onChange={(e) => setField('start_date')(e.target.value)} />
                </FormField>
                <TextField id="os-ec-name" label="Emergency contact name" required value={form.emergency_contact_name} onChange={setField('emergency_contact_name')} error={errors.emergency_contact_name} />
                <TextField id="os-ec-phone" label="Emergency contact phone" required value={form.emergency_contact_phone} onChange={setField('emergency_contact_phone')} error={errors.emergency_contact_phone} />
              </div>
              <ConfirmRow shown={pending.includes('emergency_contact')} checked={confirmOverwrite.includes('emergency_contact')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'emergency_contact'] : c.filter((x) => x !== 'emergency_contact')))}
                text={`Replace the recorded emergency contact (${selected?.emergency_contact.name ?? ''}, ${selected?.emergency_contact.phone ?? ''}).`} />
              <ConfirmRow shown={pending.includes('position')} checked={confirmOverwrite.includes('position')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'position'] : c.filter((x) => x !== 'position')))}
                text={`Replace the recorded position (${selected?.position_name ?? ''}).`} />
            </CardBody>
          </Card>

          <Card className="mb-4">
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">2. Guarantor (Damiin) - separate from the emergency contact</h3></CardHeader>
            <CardBody>
              <div className="grid gap-x-4 sm:grid-cols-2">
                <TextField id="os-g-name" label="Guarantor full name" required value={form.guarantor_name} onChange={setField('guarantor_name')} error={errors.guarantor_name} />
                <TextField id="os-g-phone" label="Guarantor phone number" required value={form.guarantor_phone} onChange={setField('guarantor_phone')} error={errors.guarantor_phone} />
                <TextField id="os-g-rel" label="Relationship (optional)" value={form.guarantor_relationship} onChange={setField('guarantor_relationship')} />
              </div>
              <ConfirmRow shown={pending.includes('guarantor')} checked={confirmOverwrite.includes('guarantor')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'guarantor'] : c.filter((x) => x !== 'guarantor')))}
                text={`Replace the recorded guarantor (${selected?.guarantor?.name ?? ''}, ${selected?.guarantor?.phone ?? ''}).`} />
            </CardBody>
          </Card>

          <Card className="mb-4">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">3. Documents</h3>
              {mode === 'existing' && selected && <span className="text-xs text-ink-muted">{documentSummary(selected.documents)}</span>}
            </CardHeader>
            <CardBody>
              <DocumentGroupsPanel
                groups={mode === 'existing' && selected ? selected.documents : DEFAULT_DOCUMENT_GROUPS.map((g) => ({ ...g, status: 'missing' as const, files: [] }))}
                employeeId={mode === 'existing' ? (selected?.id ?? null) : null}
                categoryIdByName={categoryIdByName}
                staged={staged}
                stagedErrors={stagedErrors}
                onStage={stage}
                onUploaded={(summary) => { setSelected(summary); }}
              />
              {mode === 'new' && <p className="mt-2 text-xs text-ink-faint">Chosen files are uploaded right after the employee is saved. Documents can also be added later.</p>}
            </CardBody>
          </Card>

          {needsAdditional && selected && (
            <div role="alert" className="mb-4 rounded-lg border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-ink">
              <p className="font-medium">This employee already has an active assignment: {selected.active_assignments.map((a) => `${a.label} (${a.salary})`).join('; ')}.</p>
              <p className="mt-1 text-ink-muted">Adding Office Staff creates a second assignment with its own salary, and payroll pays both.</p>
              <label className="mt-2 flex items-center gap-2 text-sm">
                <input type="checkbox" checked={confirmAdditional} onChange={(e) => setConfirmAdditional(e.target.checked)} /> I understand - add Office Staff as an additional assignment.
              </label>
            </div>
          )}

          {duplicateMatches && duplicateMatches.length > 0 && (
            <div role="alert" className="mb-4 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm">
              <p className="font-medium text-danger">An employee with this phone number already exists:</p>
              <ul className="mt-1 list-disc pl-5 text-ink">
                {duplicateMatches.map((m) => (
                  <li key={m.id}>
                    {m.full_name} ({m.employee_number}, {m.status}{m.archived ? ', archived' : ''}){' '}
                    <button type="button" className="font-medium text-primary hover:underline" onClick={() => { setMode('existing'); setSearch(m.employee_number); }}>Add this employee instead</button>
                  </li>
                ))}
              </ul>
              <p className="mt-2 text-ink-muted">If this really is a different person, give a reason (at least 10 characters) to register anyway.</p>
              <input aria-label="Reason this is a different person" className={`${inputClasses} mt-2`} value={duplicateReason} onChange={(e) => setDuplicateReason(e.target.value)} placeholder="Why is this a different person?" />
            </div>
          )}

          {showErrors && (Object.keys(errors).length > 0 || unconfirmed.length > 0) && (
            <p role="alert" className="mb-3 text-sm text-danger">
              {Object.keys(errors).length > 0 ? 'Some required details are missing or invalid - see the highlighted fields.' : `Confirm the replacement of the existing ${unconfirmed.map((f) => OVERWRITE_LABEL[f]).join(', ')} or change the value back.`}
            </p>
          )}

          <div className="flex flex-wrap items-center gap-3">
            <Button isLoading={saveMutation.isPending} onClick={submit}>{mode === 'new' ? 'Register Office Staff' : 'Add to Office Staff'}</Button>
            <Link to="/hr/office-staff" className="text-sm text-ink-muted hover:text-primary">Cancel</Link>
          </div>
        </>
      )}

      {mode === 'existing' && !selected && !isSearching && debouncedSearch.trim().length < 2 && <p className="text-sm text-ink-muted">Search for an employee above to continue.</p>}
    </div>
  );
}

function TextField({ id, label, value, onChange, error, required, hint, inputMode }: { id: string; label: string; value: string; onChange: (value: string) => void; error?: string; required?: boolean; hint?: string; inputMode?: 'decimal' | 'text' }) {
  return (
    <FormField label={label} htmlFor={id} required={required} error={error} hint={hint}>
      <input id={id} className={inputClasses} value={value} inputMode={inputMode} aria-invalid={error ? true : undefined} onChange={(e) => onChange(e.target.value)} />
    </FormField>
  );
}

function ConfirmRow({ shown, checked, onChange, text }: { shown: boolean; checked: boolean; onChange: (on: boolean) => void; text: string }) {
  if (!shown) return null;

  return (
    <label className="mb-2 flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-ink">
      <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{text} The existing value is kept unless you tick this.</span>
    </label>
  );
}

/** One row per document group: what is on file (with a download), or a file picker for what is missing. */
function DocumentGroupsPanel({
  groups, employeeId, categoryIdByName, staged, stagedErrors, onStage, onUploaded,
}: {
  groups: OfficeStaffDocumentGroup[];
  employeeId: number | null;
  categoryIdByName: Record<string, number>;
  staged: StagedFiles;
  stagedErrors: Record<string, string>;
  onStage: (key: OfficeStaffDocumentGroup['key'], file: File | null) => void;
  onUploaded: (summary: OfficeStaffSummary) => void;
}) {
  const { show } = useToast();
  const [replacing, setReplacing] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const upload = async (group: OfficeStaffDocumentGroup, file: File) => {
    const problem = documentFileError(file);
    if (problem) { show(problem, 'error'); return; }
    if (employeeId === null || !categoryIdByName[group.category]) return;
    setBusy(group.key);
    try {
      await hrApi.employees.uploadDocument(employeeId, file, { employee_document_category_id: categoryIdByName[group.category] });
      onUploaded(await hrApi.officeStaff.get(employeeId));
      setReplacing(null);
      show(`${group.label} uploaded.`);
    } catch (error) {
      show(friendlyErrorMessage(error, 'Could not upload the file.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <ul className="flex flex-col gap-3">
      {groups.map((group) => {
        const live = employeeId !== null;
        const askForFile = group.status === 'missing' || replacing === group.key;

        return (
          <li key={group.key} className="rounded-md border border-border p-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium text-ink">{group.label}{group.required ? ' *' : ''}</span>
              <StatusBadge status={group.status} label={group.status === 'on_file' ? 'On file' : 'Missing'} tone={group.status === 'on_file' ? 'success' : group.required ? 'warning' : 'neutral'} />
            </div>
            {group.files.map((file) => (
              <p key={file.id} className="mt-1 text-xs text-ink-muted">
                {file.file_name} · {file.verification_status}
                {employeeId !== null && (
                  <button type="button" className="ml-2 font-medium text-primary hover:underline" onClick={() => hrApi.employees.downloadDocument(employeeId, file.id, file.file_name).catch((e) => show(friendlyErrorMessage(e, 'Could not open the file.'), 'error'))}>View</button>
                )}
              </p>
            ))}
            {group.status === 'on_file' && replacing !== group.key && live && (
              <button type="button" className="mt-1 text-xs font-medium text-primary hover:underline" onClick={() => setReplacing(group.key)}>Replace file…</button>
            )}
            {askForFile && (live || group.status === 'missing') && (
              <div className="mt-2">
                <input
                  type="file"
                  aria-label={`${group.status === 'on_file' ? 'Replace' : 'Upload'} ${group.label}`}
                  accept={ALLOWED_DOCUMENT_EXTENSIONS.map((e) => `.${e}`).join(',')}
                  disabled={busy === group.key}
                  className="text-sm"
                  onChange={(e) => {
                    const file = e.target.files?.[0] ?? null;
                    if (live && file) void upload(group, file); else onStage(group.key, file);
                  }}
                />
                {group.status === 'on_file' && <p className="mt-1 text-xs text-ink-faint">The new file becomes the current one; the previous file is kept in the history.</p>}
                {!live && staged[group.key] && <p className="mt-1 text-xs text-ink-muted">Ready to upload: {staged[group.key]?.name}</p>}
                {stagedErrors[group.key] && <p className="mt-1 text-xs text-danger">{stagedErrors[group.key]}</p>}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

function SavedPanel({ saved, categoryIdByName, onAnother }: { saved: { summary: OfficeStaffSummary; uploadFailures: string[] }; categoryIdByName: Record<string, number>; onAnother: () => void }) {
  const [summary, setSummary] = useState(saved.summary);

  return (
    <div>
      <PageHeader title="Office Staff saved" breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Office Staff', to: '/hr/office-staff' }, { label: 'Saved' }]} />
      <Card className="mb-4">
        <CardBody>
          <p className="text-lg font-bold text-ink">{summary.full_name} <span className="text-sm font-normal text-ink-muted">{summary.employee_number}</span></p>
          <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            <div><dt className="text-xs text-ink-faint">Position</dt><dd className="text-ink">{summary.position_name ?? '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Monthly salary</dt><dd className="text-ink">{summary.current_salary ? `${summary.current_salary.amount} ${summary.current_salary.currency}` : '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Emergency contact</dt><dd className="text-ink">{summary.emergency_contact.name ?? '—'} · {summary.emergency_contact.phone ?? '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Guarantor</dt><dd className="text-ink">{summary.guarantor ? `${summary.guarantor.name} · ${summary.guarantor.phone}` : '—'}</dd></div>
          </dl>
        </CardBody>
      </Card>
      {saved.uploadFailures.length > 0 && (
        <div role="alert" className="mb-4 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          The employee was saved, but some documents could not be uploaded - add them below:
          <ul className="mt-1 list-disc pl-5">{saved.uploadFailures.map((f) => <li key={f}>{f}</li>)}</ul>
        </div>
      )}
      <Card className="mb-4">
        <CardHeader><h3 className="font-display text-sm font-bold text-ink">Documents</h3><span className="text-xs text-ink-muted">{documentSummary(summary.documents)}</span></CardHeader>
        <CardBody>
          <DocumentGroupsPanel groups={summary.documents} employeeId={summary.id} categoryIdByName={categoryIdByName} staged={{}} stagedErrors={{}} onStage={() => undefined} onUploaded={setSummary} />
        </CardBody>
      </Card>
      <div className="flex gap-3">
        <Button onClick={onAnother}>Add another</Button>
        <Link to="/hr/office-staff" className="inline-flex items-center text-sm text-primary hover:underline">Go to Office Staff</Link>
      </div>
    </div>
  );
}

