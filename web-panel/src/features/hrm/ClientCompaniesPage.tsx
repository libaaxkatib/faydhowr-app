import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal, ModalCancelButton } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import { MultiCompanyPolicyCard } from '@/features/hrm/companyEmployees/MultiCompanyPolicyCard';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { CATEGORY_FILTERS, categoryCounts, filterByCategory, parseCategoryFilter, visibleAliases } from '@/utils/clientCompanyFilter';
import type { ClientCompany, ClientCompanyAgreement, ClientCompanyCategory, WorkLocation } from '@/types/employee';

const CATEGORY_OPTIONS: { value: ClientCompanyCategory; label: string; labelKey: MessageKey }[] = [
  { value: 'company', label: 'Company', labelKey: 'option.companyCategory.company' },
  { value: 'guri', label: 'Guri (household)', labelKey: 'option.companyCategory.guri' },
];

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function ClientCompaniesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t, tValue } = useI18n();
  const { hasPermission } = useEffectivePermissions();
  const [searchParams, setSearchParams] = useSearchParams();
  const categoryFilter = parseCategoryFilter(searchParams);
  const [companyModal, setCompanyModal] = useState<{ editing: ClientCompany | null } | null>(null);
  const [locationModal, setLocationModal] = useState<{ clientCompanyId: number | null; editing: WorkLocation | null } | null>(null);

  const { data: companies, isLoading, error, refetch } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });

  // The Fayadhowr Office is Fayadhowr's own internal location, not a client: it is kept out of the client list,
  // its filter and its counts. Its capacity is edited on the Office Staff page.
  const counts = categoryCounts(companies ?? []);
  const visibleCompanies = filterByCategory(companies ?? [], categoryFilter);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['client-companies'] });
    queryClient.invalidateQueries({ queryKey: ['work-locations'] });
  };

  const deleteCompanyMutation = useMutation({
    mutationFn: (id: number) => hrApi.clientCompanies.remove(id),
    onSuccess: () => {
      invalidate();
      show(t('notify.company.clientCompanyDeleted'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.company.couldNotDeleteClientCompany'), 'error'),
  });

  const deleteLocationMutation = useMutation({
    mutationFn: (id: number) => hrApi.workLocations.remove(id),
    onSuccess: () => {
      invalidate();
      show(t('notify.company.workLocationDeleted'));
    },
    onError: (err) => show(err instanceof Error ? err.message : t('notify.company.couldNotDeleteWorkLocation'), 'error'),
  });

  if (isLoading) return <LoadingState label={t('option.company.loadingCompanies')} />;
  if (error) return <ErrorState error={error} onRetry={refetch} />;

  return (
    <div>
      <PageHeader
        title={t('nav.companiesWorkplaces')}
        breadcrumb={[{ label: t('nav.hrGroup'), to: '/hr' }, { label: t('nav.companiesWorkplaces') }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {hasPermission('hr.inspections.view') && (
              <Link to="/hr/facility-inspections" className="inline-flex h-10 items-center gap-1.5 rounded-sm border border-border bg-surface px-4 text-sm font-medium text-ink hover:bg-surface-alt">
                <Icon name="eye" size={15} />
                {t('nav.facilityInspections')}
              </Link>
            )}
            <PermissionGate module="hr">
              <Button onClick={() => setCompanyModal({ editing: null })}>
                <Icon name="plus" size={15} />
                {t('action.company.addClientCompany')}
              </Button>
            </PermissionGate>
          </div>
        }
      />

      <MultiCompanyPolicyCard />

      {companies && companies.length > 0 && (
        <div role="group" aria-label={t('option.company.filterByCategory')} className="mb-4 flex flex-wrap items-center gap-2">
          {CATEGORY_FILTERS.map((option) => {
            const isActive = categoryFilter === option.value;

            return (
              <button
                key={option.value}
                type="button"
                aria-pressed={isActive}
                onClick={() => setSearchParams(option.value === 'all' ? {} : { category: option.value }, { replace: true })}
                className={`rounded-full border px-4 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 ${
                  isActive ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-ink-muted hover:border-primary/40 hover:text-primary'
                }`}
              >
                {t(option.labelKey)} <span className={isActive ? 'text-white/80' : 'text-ink-faint'}>({counts[option.value]})</span>
              </button>
            );
          })}
        </div>
      )}

      {companies && companies.length === 0 && <EmptyState icon="box" title={t('option.company.noClientCompanies')} />}
      {companies && companies.length > 0 && visibleCompanies.length === 0 && (
        <EmptyState icon="box" title={t('option.company.noCategoryRecords', { category: categoryFilter === 'guri' ? t('option.company.filterGuri') : t('option.companyCategory.company') })} />
      )}

      <div className="flex flex-col gap-4">
        {visibleCompanies.map((company) => (
          <Card key={company.id}>
            <CardHeader className="flex-wrap">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="font-display text-sm font-bold text-ink">{company.name}</h3>
                  <StatusBadge
                    status={company.category}
                    label={tValue('option.companyCategory', company.category) ?? company.category_label ?? (company.category === 'guri' ? t('option.companyCategory.guri') : t('option.companyCategory.company'))}
                    tone={company.category === 'guri' ? 'info' : 'neutral'}
                  />
                </div>
                <p className="text-xs text-ink-muted">
                  {[company.contact_person, company.phone, company.location].filter(Boolean).join(' · ') || t('option.company.noContactDetails')}
                </p>
                {visibleAliases(company.name, company.aliases).length > 0 && (
                  <p className="text-xs text-ink-faint">{t('option.company.alsoKnownAs')} {visibleAliases(company.name, company.aliases).join(', ')}</p>
                )}
              </div>
              <div className="flex flex-wrap items-center gap-1">
              <Link to={`/hr/companies/${company.id}/employees`} state={{ fromPath: `${window.location.pathname}${window.location.search}` }} className="inline-flex h-8 items-center gap-1.5 rounded-sm border border-border px-2.5 text-xs font-semibold text-ink hover:bg-surface-alt">
                <Icon name="users" size={14} />
                {t('nav.employees')}
              </Link>
              <PermissionGate module="hr">
                <div className="flex flex-wrap items-center gap-1">
                  <Button variant="outline" size="sm" onClick={() => setLocationModal({ clientCompanyId: company.id, editing: null })}>
                    <Icon name="plus" size={14} />
                    {t('action.company.addLocation')}
                  </Button>
                  <button type="button" onClick={() => setCompanyModal({ editing: company })} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary">
                    <Icon name="pencil" size={14} />
                  </button>
                  <button type="button" onClick={() => deleteCompanyMutation.mutate(company.id)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
                    <Icon name="trash" size={14} />
                  </button>
                </div>
              </PermissionGate>
              </div>
            </CardHeader>
            <CardBody>
              {!company.work_locations || company.work_locations.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('option.company.noWorkLocations')}</p>
              ) : (
                <div className="divide-y divide-border">
                  {company.work_locations.map((location) => (
                    <div key={location.id} className="flex items-center justify-between py-2.5">
                      <div>
                        <p className="text-sm font-medium text-ink">{location.name}</p>
                        <p className="text-xs text-ink-muted">
                          {location.location ?? t('option.company.noAddress')}
                          {location.capacity !== null && ` · ${t('option.company.capacityAssigned', { capacity: location.capacity, assigned: location.active_assignments_count })}`}
                        </p>
                      </div>
                      <PermissionGate module="hr">
                        <div className="flex items-center gap-1">
                          <button type="button" onClick={() => setLocationModal({ clientCompanyId: company.id, editing: location })} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt hover:text-primary">
                            <Icon name="pencil" size={14} />
                          </button>
                          <button type="button" onClick={() => deleteLocationMutation.mutate(location.id)} className="flex h-8 w-8 items-center justify-center rounded-sm text-ink-muted hover:bg-danger-soft hover:text-danger">
                            <Icon name="trash" size={14} />
                          </button>
                        </div>
                      </PermissionGate>
                    </div>
                  ))}
                </div>
              )}
              <AgreementSection company={company} onChanged={invalidate} />
            </CardBody>
          </Card>
        ))}
      </div>

      {companyModal && (
        <CompanyFormModal
          editing={companyModal.editing}
          onClose={() => setCompanyModal(null)}
          onSaved={() => {
            invalidate();
            setCompanyModal(null);
          }}
        />
      )}

      {locationModal && (
        <LocationFormModal
          clientCompanyId={locationModal.clientCompanyId}
          editing={locationModal.editing}
          onClose={() => setLocationModal(null)}
          onSaved={() => {
            invalidate();
            setLocationModal(null);
          }}
        />
      )}
    </div>
  );
}

function CompanyFormModal({
  editing,
  onClose,
  onSaved,
}: {
  editing: ClientCompany | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { show } = useToast();
  const { t } = useI18n();
  const [name, setName] = useState(editing?.name ?? '');
  const [contactPerson, setContactPerson] = useState(editing?.contact_person ?? '');
  const [phone, setPhone] = useState(editing?.phone ?? '');
  const [location, setLocation] = useState(editing?.location ?? '');
  const [notes, setNotes] = useState(editing?.notes ?? '');
  const [category, setCategory] = useState<ClientCompanyCategory>(editing?.category ?? 'company');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        name,
        category,
        contact_person: contactPerson || null,
        phone: phone || null,
        location: location || null,
        notes: notes || null,
      };
      return editing ? hrApi.clientCompanies.update(editing.id, payload) : hrApi.clientCompanies.create(payload);
    },
    onSuccess: () => {
      show(editing ? t('notify.company.clientCompanyUpdated') : t('notify.company.clientCompanyCreated'));
      onSaved();
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation && err.fieldErrors) {
        setFieldErrors(err.fieldErrors);
        if (err.fieldErrors.name) return;
      }
      show(friendlyErrorMessage(err, t('notify.company.somethingWentWrong')), 'error');
    },
  });

  return (
    <Modal
      confirmDiscard
      isOpen
      onClose={onClose}
      title={editing ? t('confirm.company.editClientCompany') : t('action.company.addClientCompany')}
      size="sm"
      footer={
        <>
          <ModalCancelButton />
          <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>{t('action.save')}</Button>
        </>
      }
    >
      <FormField
        label={t('option.company.companyName')}
        htmlFor="cc-name"
        required
        error={
          fieldErrors.name?.[0]
            ? /taken|already/i.test(fieldErrors.name[0])
              ? t('validation.company.companyNameExists')
              : fieldErrors.name[0]
            : undefined
        }
      >
        <input id="cc-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
      </FormField>
      <FormField label={t('option.label.type')} htmlFor="cc-category" hint={t('warning.company.categoryHint')}>
        <Select id="cc-category" value={category} onChange={(e) => setCategory(e.target.value as ClientCompanyCategory)} options={CATEGORY_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))} />
      </FormField>
      <FormField label={t('option.company.contactPerson')} htmlFor="cc-contact">
        <input id="cc-contact" className={inputClasses} value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} />
      </FormField>
      <FormField label={t('option.label.phone')} htmlFor="cc-phone">
        <input id="cc-phone" className={inputClasses} value={phone} onChange={(e) => setPhone(e.target.value)} />
      </FormField>
      <FormField label={t('option.label.location')} htmlFor="cc-location">
        <input id="cc-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
      </FormField>
      <FormField label={t('option.label.notes')} htmlFor="cc-notes">
        <textarea id="cc-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}

/**
 * Optional agreement files for one company. The newest upload is the current
 * agreement; uploading again keeps the older ones as history. Only loads the
 * list when opened, so the companies page doesn't fire one request per company.
 */
function AgreementSection({ company, onChanged }: { company: ClientCompany; onChanged: () => void }) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [notes, setNotes] = useState('');

  const { data: agreements, isLoading, error } = useQuery({
    queryKey: ['client-company-agreements', company.id],
    queryFn: () => hrApi.clientCompanies.agreements.list(company.id),
    enabled: isOpen,
  });

  const uploadMutation = useMutation({
    mutationFn: (file: File) => hrApi.clientCompanies.agreements.upload(company.id, file, notes || null),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['client-company-agreements', company.id] });
      onChanged();
      setNotes('');
      show(t('notify.company.agreementUploaded'));
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.couldNotUploadAgreement')), 'error'),
  });

  const downloadMutation = useMutation({
    mutationFn: (agreement: ClientCompanyAgreement) => hrApi.clientCompanies.agreements.download(company.id, agreement),
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.couldNotDownloadAgreement')), 'error'),
  });

  // Newest first = the current agreement; sort defensively in case the server order changes.
  const sorted = [...(agreements ?? [])].sort((a, b) => b.uploaded_at.localeCompare(a.uploaded_at) || b.id - a.id);
  const count = agreements?.length ?? company.agreements_count ?? 0;

  return (
    <div className="mt-4 border-t border-border pt-3">
      <button type="button" onClick={() => setIsOpen((open) => !open)} className="flex items-center gap-1.5 text-xs font-semibold text-ink-muted hover:text-primary">
        <Icon name={isOpen ? 'chevron-down' : 'chevron-right'} size={14} />
        {count > 0 ? t('option.company.agreementWithCount', { count }) : t('option.company.agreementNone')}
      </button>

      {isOpen && (
        <div className="mt-3 space-y-3">
          {isLoading && <p className="text-xs text-ink-faint">{t('option.company.loadingAgreements')}</p>}
          {error && <p className="text-xs text-danger">{friendlyErrorMessage(error, t('notify.company.couldNotLoadAgreements'))}</p>}
          {agreements && sorted.length === 0 && <p className="text-xs italic text-ink-faint">{t('option.company.noAgreementYet')}</p>}

          {sorted.length > 0 && (
            <div className="space-y-1.5">
              {sorted.map((agreement, index) => (
                <div key={agreement.id} className="flex items-center justify-between gap-3 rounded-sm border border-border px-2.5 py-1.5">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 text-sm text-ink">
                      <span className="truncate">{agreement.file_name}</span>
                      {index === 0 ? <StatusBadge status="current" label={t('status.company.current')} tone="success" /> : <span className="text-xs text-ink-faint">{t('option.company.earlierVersion')}</span>}
                    </p>
                    <p className="text-xs text-ink-faint">
                      {agreement.uploaded_by
                        ? t('option.company.agreementUploadedBy', { size: formatFileSize(agreement.file_size), date: formatDate(agreement.uploaded_at), name: agreement.uploaded_by })
                        : t('option.company.agreementUploaded', { size: formatFileSize(agreement.file_size), date: formatDate(agreement.uploaded_at) })}
                      {agreement.notes && ` · ${agreement.notes}`}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    isLoading={downloadMutation.isPending && downloadMutation.variables?.id === agreement.id}
                    onClick={() => downloadMutation.mutate(agreement)}
                  >
                    {t('action.download')}
                  </Button>
                </div>
              ))}
            </div>
          )}

          <PermissionGate module="hr">
            <div className="flex flex-wrap items-end gap-2">
              <div className="min-w-[10rem] flex-1">
                <label htmlFor={`agreement-notes-${company.id}`} className="mb-1 block text-xs font-medium text-ink-muted">{t('confirm.company.noteOptional')}</label>
                <input
                  id={`agreement-notes-${company.id}`}
                  className={inputClasses}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder={t('option.company.agreementNotePlaceholder')}
                />
              </div>
              <Button size="sm" variant="outline" isLoading={uploadMutation.isPending} onClick={() => fileInputRef.current?.click()}>
                <Icon name="plus" size={14} />
                {sorted.length > 0 ? t('action.company.uploadNewAgreement') : t('action.company.uploadAgreement')}
              </Button>
              <input
                ref={fileInputRef}
                type="file"
                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                hidden
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) uploadMutation.mutate(file);
                  e.target.value = '';
                }}
              />
            </div>
            <p className="text-xs text-ink-faint">{t('confirm.company.agreementHelp')}</p>
          </PermissionGate>
        </div>
      )}
    </div>
  );
}

function LocationFormModal({
  clientCompanyId,
  editing,
  onClose,
  onSaved,
}: {
  clientCompanyId: number | null;
  editing: WorkLocation | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { show } = useToast();
  const { t } = useI18n();
  const [name, setName] = useState(editing?.name ?? '');
  const [location, setLocation] = useState(editing?.location ?? '');
  const [contactPerson, setContactPerson] = useState(editing?.contact_person ?? '');
  const [phone, setPhone] = useState(editing?.phone ?? '');
  const [capacity, setCapacity] = useState(editing?.capacity != null ? String(editing.capacity) : '');
  const [notes, setNotes] = useState(editing?.notes ?? '');

  const saveMutation = useMutation({
    mutationFn: () => {
      const capacityValue = capacity === '' ? null : Number(capacity);
      if (editing) {
        return hrApi.workLocations.update(editing.id, {
          name,
          location: location || null,
          contact_person: contactPerson || null,
          phone: phone || null,
          capacity: capacityValue,
          notes: notes || null,
        });
      }
      return hrApi.workLocations.create({
        location_type: 'client',
        client_company_id: clientCompanyId,
        name,
        location: location || null,
        contact_person: contactPerson || null,
        phone: phone || null,
        capacity: capacityValue,
        notes: notes || null,
      });
    },
    onSuccess: () => {
      show(editing ? t('notify.company.workLocationUpdated') : t('notify.company.workLocationCreated'));
      onSaved();
    },
    onError: (err) => show(friendlyErrorMessage(err, t('notify.company.somethingWentWrong')), 'error'),
  });

  const isOfficeEdit = editing?.location_type === 'office';

  return (
    <Modal
      confirmDiscard
      isOpen
      onClose={onClose}
      title={editing ? (isOfficeEdit ? t('confirm.company.editOfficeTitle') : t('confirm.company.editWorkLocation')) : t('confirm.company.addWorkLocation')}
      size="sm"
      footer={
        <>
          <ModalCancelButton />
          <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>{t('action.save')}</Button>
        </>
      }
    >
      <FormField label={t('option.label.name')} htmlFor="wl-name" required>
        <input id="wl-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
      </FormField>
      <FormField label={t('option.company.addressLocation')} htmlFor="wl-location">
        <input id="wl-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
      </FormField>
      <FormField label={t('option.company.contactPerson')} htmlFor="wl-contact">
        <input id="wl-contact" className={inputClasses} value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} />
      </FormField>
      <FormField label={t('option.label.phone')} htmlFor="wl-phone">
        <input id="wl-phone" className={inputClasses} value={phone} onChange={(e) => setPhone(e.target.value)} />
      </FormField>
      <FormField label={t('option.company.approvedCapacity')} htmlFor="wl-capacity">
        <input id="wl-capacity" type="number" min={0} className={inputClasses} value={capacity} onChange={(e) => setCapacity(e.target.value)} placeholder={t('option.label.noLimit')} />
      </FormField>
      <FormField label={t('option.label.notes')} htmlFor="wl-notes">
        <textarea id="wl-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}
