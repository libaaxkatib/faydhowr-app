import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { SearchInput } from '@/components/ui/SearchInput';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { isDirty } from '@/utils/unsavedChanges';
import { duplicateMatchesFromError, friendlyErrorMessage, localToday } from '@/utils/hrHelpers';
import { OFFICE_STAFF_CATEGORY } from '@/utils/employeeCategories';
import { decideCreatePosition } from '@/utils/positions';
import { ApiClientError } from '@/api/client';
import type { Position } from '@/types/employee';
import {
  ALLOWED_DOCUMENT_EXTENSIONS,
  buildPayload,
  DEFAULT_DOCUMENT_GROUPS,
  documentFileError,
  documentGroupLabel,
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

const OVERWRITE_LABEL_KEYS: Record<OverwriteField, MessageKey> = {
  emergency_contact: 'option.employees.overwriteEmergencyContact',
  guarantor: 'option.employees.overwriteGuarantor',
  position: 'option.employees.overwritePosition',
};

type StagedFiles = Partial<Record<OfficeStaffDocumentGroup['key'], File | null>>;

/** Add an existing employee to Office Staff, or register a new one straight into it. */
export function OfficeStaffRegistrationPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const overwriteLabel = (field: OverwriteField) => t(OVERWRITE_LABEL_KEYS[field]);
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
  const [isPositionDialogOpen, setIsPositionDialogOpen] = useState(false);
  const [newPositionName, setNewPositionName] = useState('');
  const [newPositionError, setNewPositionError] = useState('');
  const debouncedSearch = useDebouncedValue(search);

  // Unsaved-changes protection: leaving the page (link, Back, reload) with a half-filled form asks first; once saved it never does.
  const snapshot = () => ({ mode, form, selectedId: selected?.id ?? null, staged: Object.keys(staged).sort(), duplicateReason });
  const initialSnapshot = useRef(snapshot());
  const unsavedGuard = useUnsavedChangesGuard(saved === null && isDirty(initialSnapshot.current, snapshot()));

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
          uploadFailures.push(`${upload.file.name}: ${friendlyErrorMessage(error, t('notify.employees.couldNotUpload'))}`);
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
      show(mode === 'new' ? t('notify.employees.officeStaffRegistered') : t('notify.employees.officeStaffAdded'));
    },
    onError: (error) => {
      const conflicts = overwriteConflictsFromError(error);
      if (conflicts !== null) {
        show(t('notify.employees.overwriteConflict', { fields: conflicts.map(overwriteLabel).join(', ') }), 'error');
        return;
      }
      if (needsAdditionalAssignmentConfirmation(error)) {
        show(t('notify.employees.additionalAssignmentNeeded'), 'error');
        return;
      }
      const duplicates = duplicateMatchesFromError(error);
      if (duplicates !== null) {
        setDuplicateMatches(duplicates);
        show(t('notify.employees.phoneAlreadyExists'), 'error');
        return;
      }
      show(friendlyErrorMessage(error, t('notify.employees.couldNotSave')), 'error');
    },
  });

  // "Create position": a small dialog on top of THIS page, so everything already typed in the form stays exactly as it is.
  const openCreatePosition = () => {
    setNewPositionName('');
    setNewPositionError('');
    setIsPositionDialogOpen(true);
  };

  const createPositionMutation = useMutation({
    mutationFn: (name: string) => hrApi.positions.create({ name }),
    onSuccess: (created) => {
      queryClient.setQueryData<Position[]>(['positions'], (current) => [...(current ?? []).filter((p) => p.id !== created.id), created].sort((a, b) => a.name.localeCompare(b.name)));
      queryClient.invalidateQueries({ queryKey: ['positions'] });
      setForm((current) => ({ ...current, position_id: String(created.id) }));
      setIsPositionDialogOpen(false);
      show(t('notify.employees.positionCreatedSelected', { name: created.name }));
    },
    onError: async (error) => {
      const message = error instanceof ApiClientError ? (error.fieldErrors?.name?.[0] ?? error.message) : friendlyErrorMessage(error, t('notify.employees.positionCreateFailed'));
      setNewPositionError(message);
      // Someone else may have just created it: refresh the list so it can be picked instead.
      await queryClient.invalidateQueries({ queryKey: ['positions'] });
    },
  });

  const saveNewPosition = () => {
    const decision = decideCreatePosition(positions ?? [], newPositionName);

    if (decision.kind === 'invalid') {
      setNewPositionError(decision.message);
    } else if (decision.kind === 'use_existing') {
      // Already there (any letter case or spacing): select it rather than creating a duplicate.
      setForm((current) => ({ ...current, position_id: String(decision.id) }));
      setIsPositionDialogOpen(false);
      show(t('notify.employees.positionExistsSelected', { name: decision.name }));
    } else {
      setNewPositionError('');
      createPositionMutation.mutate(decision.name);
    }
  };

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
      {unsavedGuard}
      <PageHeader
        title={t('action.employees.addOfficeStaff')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.officeStaff'), to: '/hr/office-staff' }, { label: t('action.add') }]}
      />

      <div role="tablist" aria-label={t('option.employees.howToAddOfficeStaff')} className="mb-4 flex flex-wrap gap-2">
        {([['existing', t('option.employees.addExistingEmployee')], ['new', t('option.employees.registerNewOfficeStaff')]] as const).map(([value, label]) => (
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
          <CardHeader><h3 className="font-display text-sm font-bold text-ink">{t('option.employees.findTheEmployee')}</h3></CardHeader>
          <CardBody>
            <SearchInput value={search} onChange={setSearch} placeholder={t('option.employees.searchOfficeStaff')} className="w-full max-w-md" />
            {isSearching && <p className="mt-2 text-xs text-ink-muted">{t('option.label.searching')}</p>}
            {matches && matches.length === 0 && debouncedSearch.trim().length >= 2 && <p className="mt-3 text-sm text-ink-muted">{t('warning.employees.noEmployeeFound')}</p>}
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
                      <StatusBadge status={candidate.status} label={tValue('status', candidate.status)} />
                      {candidate.is_office_staff && <StatusBadge status="office" label={t('status.employees.alreadyOfficeStaff')} tone="info" />}
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      {candidate.phone ?? t('option.employees.noPhone')} · {candidate.position_name ?? t('option.employees.noPosition')} ·{' '}
                      {candidate.current_salary ? t('option.employees.salaryPerMonth', { amount: candidate.current_salary.amount, currency: candidate.current_salary.currency }) : t('option.employees.noSalaryRecorded')} · {documentSummary(candidate.documents)}
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
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">{t('option.employees.stepEmployeeInfo')}</h3></CardHeader>
            <CardBody>
              <div className="grid gap-x-4 sm:grid-cols-2">
                {mode === 'new' ? (
                  <>
                    <TextField id="os-name" label={t('option.label.fullName')} required value={form.full_name} onChange={setField('full_name')} error={errors.full_name} />
                    <FormField label={t('option.employees.employeeNumber')} htmlFor="os-number" hint={t('warning.employees.employeeNumberNewHint')}>
                      <input id="os-number" className={inputClasses} value={t('option.employees.assignedOnSave')} disabled readOnly />
                    </FormField>
                    <TextField id="os-phone" label={t('option.label.phoneNumber')} required value={form.phone} onChange={setField('phone')} error={errors.phone} />
                    <FormField label={t('option.employees.employeeCategory')} htmlFor="os-category" hint={t('warning.employees.categoryNewHint')}>
                      <input id="os-category" className={inputClasses} value={OFFICE_STAFF_CATEGORY} disabled readOnly />
                    </FormField>
                  </>
                ) : (
                  <>
                    <FormField label={t('option.label.fullName')} htmlFor="os-name"><input id="os-name" className={inputClasses} value={selected?.full_name ?? ''} disabled readOnly /></FormField>
                    <FormField label={t('option.employees.employeeNumber')} htmlFor="os-number" hint={t('warning.employees.employeeNumberKeptHint')}><input id="os-number" className={inputClasses} value={selected?.employee_number ?? ''} disabled readOnly /></FormField>
                    <FormField label={t('option.employees.employeeCategory')} htmlFor="os-category" hint={t('warning.employees.categoryUnchangedHint')}><input id="os-category" className={inputClasses} value={selected?.category_name ?? '—'} disabled readOnly /></FormField>
                  </>
                )}
                <FormField label={t('option.employees.positionJobTitle')} htmlFor="os-position" required error={errors.position_id} hint={mode === 'existing' && selected?.position_name ? t('warning.employees.positionCurrentHint', { name: selected.position_name }) : t('warning.employees.positionsManagedHint')}>
                  <Select id="os-position" value={form.position_id} onChange={(e) => setField('position_id')(e.target.value)} options={(positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))} placeholder={t('option.employees.choosePosition')} />
                  <button type="button" onClick={openCreatePosition} className="mt-1.5 text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    {t('action.employees.createPositionLinkLower')}
                  </button>
                </FormField>
                <TextField
                  id="os-salary" label={t('option.employees.monthlySalaryUsd')} required inputMode="decimal" value={form.monthly_salary} onChange={setField('monthly_salary')} error={errors.monthly_salary}
                  hint={mode === 'existing' && selected?.current_salary ? t('warning.employees.salaryCurrentHint', { amount: selected.current_salary.amount, currency: selected.current_salary.currency }) : t('warning.employees.salarySavingHint')}
                />
                <FormField label={t('option.label.startDate')} htmlFor="os-start" required error={errors.start_date}>
                  <input id="os-start" type="date" className={inputClasses} value={form.start_date} onChange={(e) => setField('start_date')(e.target.value)} />
                </FormField>
                <TextField id="os-ec-name" label={t('option.employees.emergencyContactName')} required value={form.emergency_contact_name} onChange={setField('emergency_contact_name')} error={errors.emergency_contact_name} />
                <TextField id="os-ec-phone" label={t('option.employees.emergencyContactPhone')} required value={form.emergency_contact_phone} onChange={setField('emergency_contact_phone')} error={errors.emergency_contact_phone} />
              </div>
              <ConfirmRow shown={pending.includes('emergency_contact')} checked={confirmOverwrite.includes('emergency_contact')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'emergency_contact'] : c.filter((x) => x !== 'emergency_contact')))}
                text={t('warning.employees.replaceEmergencyContact', { name: selected?.emergency_contact.name ?? '', phone: selected?.emergency_contact.phone ?? '' })} />
              <ConfirmRow shown={pending.includes('position')} checked={confirmOverwrite.includes('position')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'position'] : c.filter((x) => x !== 'position')))}
                text={t('warning.employees.replacePosition', { name: selected?.position_name ?? '' })} />
            </CardBody>
          </Card>

          <Card className="mb-4">
            <CardHeader><h3 className="font-display text-sm font-bold text-ink">{t('option.employees.stepGuarantor')}</h3></CardHeader>
            <CardBody>
              <div className="grid gap-x-4 sm:grid-cols-2">
                <TextField id="os-g-name" label={t('option.employees.guarantorFullName')} required value={form.guarantor_name} onChange={setField('guarantor_name')} error={errors.guarantor_name} />
                <TextField id="os-g-phone" label={t('option.employees.guarantorPhoneNumber')} required value={form.guarantor_phone} onChange={setField('guarantor_phone')} error={errors.guarantor_phone} />
                <TextField id="os-g-rel" label={t('option.employees.relationshipOptional')} value={form.guarantor_relationship} onChange={setField('guarantor_relationship')} />
              </div>
              <ConfirmRow shown={pending.includes('guarantor')} checked={confirmOverwrite.includes('guarantor')} onChange={(on) => setConfirmOverwrite((c) => (on ? [...c, 'guarantor'] : c.filter((x) => x !== 'guarantor')))}
                text={t('warning.employees.replaceGuarantor', { name: selected?.guarantor?.name ?? '', phone: selected?.guarantor?.phone ?? '' })} />
            </CardBody>
          </Card>

          <Card className="mb-4">
            <CardHeader>
              <h3 className="font-display text-sm font-bold text-ink">{t('option.employees.stepDocuments')}</h3>
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
              {mode === 'new' && <p className="mt-2 text-xs text-ink-faint">{t('warning.employees.chosenFilesNote')}</p>}
            </CardBody>
          </Card>

          {needsAdditional && selected && (
            <div role="alert" className="mb-4 rounded-lg border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-ink">
              <p className="font-medium">{t('warning.employees.alreadyAssigned', { assignments: selected.active_assignments.map((a) => `${a.label} (${a.salary})`).join('; ') })}</p>
              <p className="mt-1 text-ink-muted">{t('warning.employees.secondAssignmentNote')}</p>
              <label className="mt-2 flex items-center gap-2 text-sm">
                <input type="checkbox" checked={confirmAdditional} onChange={(e) => setConfirmAdditional(e.target.checked)} /> {t('warning.employees.understandAdditional')}
              </label>
            </div>
          )}

          {duplicateMatches && duplicateMatches.length > 0 && (
            <div role="alert" className="mb-4 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm">
              <p className="font-medium text-danger">{t('warning.employees.phoneExistsList')}</p>
              <ul className="mt-1 list-disc pl-5 text-ink">
                {duplicateMatches.map((m) => (
                  <li key={m.id}>
                    {m.full_name} ({m.employee_number}, {tValue('status', m.status) ?? m.status}{m.archived ? `, ${t('status.employees.archived')}` : ''}){' '}
                    <button type="button" className="font-medium text-primary hover:underline" onClick={() => { setMode('existing'); setSearch(m.employee_number); }}>{t('action.employees.addThisInstead')}</button>
                  </li>
                ))}
              </ul>
              <p className="mt-2 text-ink-muted">{t('warning.employees.differentPersonReason')}</p>
              <input aria-label={t('option.employees.reasonDifferentPerson')} className={`${inputClasses} mt-2`} value={duplicateReason} onChange={(e) => setDuplicateReason(e.target.value)} placeholder={t('option.employees.whyDifferentPerson')} />
            </div>
          )}

          {showErrors && (Object.keys(errors).length > 0 || unconfirmed.length > 0) && (
            <p role="alert" className="mb-3 text-sm text-danger">
              {Object.keys(errors).length > 0 ? t('validation.employees.requiredMissing') : t('validation.employees.confirmReplacement', { fields: unconfirmed.map(overwriteLabel).join(', ') })}
            </p>
          )}

          <div className="flex flex-wrap items-center gap-3">
            <Button isLoading={saveMutation.isPending} onClick={submit}>{mode === 'new' ? t('action.employees.registerOfficeStaff') : t('action.employees.addToOfficeStaff')}</Button>
            <Link to="/hr/office-staff" className="text-sm text-ink-muted hover:text-primary">{t('action.cancel')}</Link>
          </div>
        </>
      )}

      {mode === 'existing' && !selected && !isSearching && debouncedSearch.trim().length < 2 && <p className="text-sm text-ink-muted">{t('warning.employees.searchToContinue')}</p>}

      <Modal
        isOpen={isPositionDialogOpen}
        onClose={() => setIsPositionDialogOpen(false)}
        title={t('action.employees.createPosition')}
        size="sm"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={() => setIsPositionDialogOpen(false)}>{t('action.cancel')}</Button>
            <Button size="sm" isLoading={createPositionMutation.isPending} onClick={saveNewPosition}>{t('action.employees.createAndSelect')}</Button>
          </>
        }
      >
        <FormField label={t('option.employees.positionName')} htmlFor="os-new-position" required error={newPositionError} hint={t('warning.employees.newPositionHint')}>
          <input
            id="os-new-position"
            className={inputClasses}
            value={newPositionName}
            maxLength={150}
            autoFocus
            onChange={(e) => { setNewPositionName(e.target.value); setNewPositionError(''); }}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); saveNewPosition(); } }}
          />
        </FormField>
      </Modal>
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
  const { t } = useI18n();

  if (!shown) return null;

  return (
    <label className="mb-2 flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-ink">
      <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{text} {t('warning.employees.keptUnlessTicked')}</span>
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
  const { t } = useI18n();
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
      show(t('notify.employees.documentUploaded', { label: documentGroupLabel(group) }));
    } catch (error) {
      show(friendlyErrorMessage(error, t('notify.employees.fileUploadFailed')), 'error');
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
              <span className="text-sm font-medium text-ink">{documentGroupLabel(group)}{group.required ? ' *' : ''}</span>
              <StatusBadge status={group.status} label={group.status === 'on_file' ? t('status.employees.onFile') : t('status.employees.missing')} tone={group.status === 'on_file' ? 'success' : group.required ? 'warning' : 'neutral'} />
            </div>
            {group.files.map((file) => (
              <p key={file.id} className="mt-1 text-xs text-ink-muted">
                {file.file_name} · {file.verification_status}
                {employeeId !== null && (
                  <button type="button" className="ml-2 font-medium text-primary hover:underline" onClick={() => hrApi.employees.downloadDocument(employeeId, file.id, file.file_name).catch((e) => show(friendlyErrorMessage(e, t('notify.employees.fileOpenFailed')), 'error'))}>{t('action.employees.viewFile')}</button>
                )}
              </p>
            ))}
            {group.status === 'on_file' && replacing !== group.key && live && (
              <button type="button" className="mt-1 text-xs font-medium text-primary hover:underline" onClick={() => setReplacing(group.key)}>{t('action.employees.replaceFile')}</button>
            )}
            {askForFile && (live || group.status === 'missing') && (
              <div className="mt-2">
                <input
                  type="file"
                  aria-label={group.status === 'on_file' ? t('action.employees.replaceDocument', { label: documentGroupLabel(group) }) : t('action.employees.uploadDocument', { label: documentGroupLabel(group) })}
                  accept={ALLOWED_DOCUMENT_EXTENSIONS.map((e) => `.${e}`).join(',')}
                  disabled={busy === group.key}
                  className="text-sm"
                  onChange={(e) => {
                    const file = e.target.files?.[0] ?? null;
                    if (live && file) void upload(group, file); else onStage(group.key, file);
                  }}
                />
                {group.status === 'on_file' && <p className="mt-1 text-xs text-ink-faint">{t('warning.employees.replaceFileNote')}</p>}
                {!live && staged[group.key] && <p className="mt-1 text-xs text-ink-muted">{t('warning.employees.readyToUpload', { name: staged[group.key]?.name ?? '' })}</p>}
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
  const { t } = useI18n();
  const [summary, setSummary] = useState(saved.summary);

  return (
    <div>
      <PageHeader title={t('notify.employees.officeStaffSavedTitle')} breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.officeStaff'), to: '/hr/office-staff' }, { label: t('notify.employees.savedCrumb') }]} />
      <Card className="mb-4">
        <CardBody>
          <p className="text-lg font-bold text-ink">{summary.full_name} <span className="text-sm font-normal text-ink-muted">{summary.employee_number}</span></p>
          <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            <div><dt className="text-xs text-ink-faint">{t('option.label.position')}</dt><dd className="text-ink">{summary.position_name ?? '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">{t('confirm.company.monthlySalary')}</dt><dd className="text-ink">{summary.current_salary ? `${summary.current_salary.amount} ${summary.current_salary.currency}` : '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">{t('option.employees.emergencyContact')}</dt><dd className="text-ink">{summary.emergency_contact.name ?? '—'} · {summary.emergency_contact.phone ?? '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">{t('option.employees.guarantor')}</dt><dd className="text-ink">{summary.guarantor ? `${summary.guarantor.name} · ${summary.guarantor.phone}` : '—'}</dd></div>
          </dl>
        </CardBody>
      </Card>
      {saved.uploadFailures.length > 0 && (
        <div role="alert" className="mb-4 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          {t('warning.employees.uploadFailuresIntro')}
          <ul className="mt-1 list-disc pl-5">{saved.uploadFailures.map((f) => <li key={f}>{f}</li>)}</ul>
        </div>
      )}
      <Card className="mb-4">
        <CardHeader><h3 className="font-display text-sm font-bold text-ink">{t('option.employees.documents')}</h3><span className="text-xs text-ink-muted">{documentSummary(summary.documents)}</span></CardHeader>
        <CardBody>
          <DocumentGroupsPanel groups={summary.documents} employeeId={summary.id} categoryIdByName={categoryIdByName} staged={{}} stagedErrors={{}} onStage={() => undefined} onUploaded={setSummary} />
        </CardBody>
      </Card>
      <div className="flex gap-3">
        <Button onClick={onAnother}>{t('action.employees.addAnother')}</Button>
        <Link to="/hr/office-staff" className="inline-flex items-center text-sm text-primary hover:underline">{t('action.employees.goToOfficeStaff')}</Link>
      </div>
    </div>
  );
}

