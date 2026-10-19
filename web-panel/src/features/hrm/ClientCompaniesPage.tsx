import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';

import { hrApi } from '@/api/hr';
import { ApiClientError } from '@/api/client';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import { MultiCompanyPolicyCard } from '@/features/hrm/companyEmployees/MultiCompanyPolicyCard';
import { formatDate } from '@/utils/formatters';
import { friendlyErrorMessage } from '@/utils/hrHelpers';
import { CATEGORY_FILTERS, categoryCounts, filterByCategory, isInternalOffice, parseCategoryFilter } from '@/utils/clientCompanyFilter';
import type { ClientCompany, ClientCompanyAgreement, ClientCompanyCategory, WorkLocation } from '@/types/employee';

const CATEGORY_OPTIONS: { value: ClientCompanyCategory; label: string }[] = [
  { value: 'company', label: 'Company' },
  { value: 'guri', label: 'Guri (household)' },
];

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function ClientCompaniesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const categoryFilter = parseCategoryFilter(searchParams);
  const [companyModal, setCompanyModal] = useState<{ editing: ClientCompany | null } | null>(null);
  const [locationModal, setLocationModal] = useState<{ clientCompanyId: number | null; editing: WorkLocation | null } | null>(null);

  const { data: companies, isLoading, error, refetch } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });

  // The Fayadhowr Office is Fayadhowr's own internal location, not a client: it is kept out of the client list,
  // its filter and its counts. Its capacity stays editable from the button in the page header.
  const officeLocations = (locations ?? []).filter(isInternalOffice);
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
      show('Client company deleted.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete client company.', 'error'),
  });

  const deleteLocationMutation = useMutation({
    mutationFn: (id: number) => hrApi.workLocations.remove(id),
    onSuccess: () => {
      invalidate();
      show('Work location deleted.');
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Could not delete work location.', 'error'),
  });

  if (isLoading) return <LoadingState label="Loading companies…" />;
  if (error) return <ErrorState error={error} onRetry={refetch} />;

  return (
    <div>
      <PageHeader
        title="Companies & Locations"
        breadcrumb={[{ label: 'Human Resources', to: '/hr' }, { label: 'Companies & Locations' }]}
        actions={
          <PermissionGate module="hr">
            <div className="flex flex-wrap items-center gap-2">
              {officeLocations.map((office) => (
                <Button key={office.id} variant="outline" onClick={() => setLocationModal({ clientCompanyId: null, editing: office })}>
                  <Icon name="pencil" size={15} />
                  Office capacity
                </Button>
              ))}
              <Button onClick={() => setCompanyModal({ editing: null })}>
                <Icon name="plus" size={15} />
                Add Client Company
              </Button>
            </div>
          </PermissionGate>
        }
      />

      <MultiCompanyPolicyCard />

      {companies && companies.length > 0 && (
        <div role="group" aria-label="Filter by category" className="mb-4 flex flex-wrap items-center gap-2">
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
                {option.label} <span className={isActive ? 'text-white/80' : 'text-ink-faint'}>({counts[option.value]})</span>
              </button>
            );
          })}
        </div>
      )}

      {companies && companies.length === 0 && <EmptyState icon="box" title="No client companies yet" />}
      {companies && companies.length > 0 && visibleCompanies.length === 0 && (
        <EmptyState icon="box" title={`No ${categoryFilter === 'guri' ? 'Guri' : 'Company'} records`} />
      )}

      <div className="flex flex-col gap-4">
        {visibleCompanies.map((company) => (
          <Card key={company.id}>
            <CardHeader>
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="font-display text-sm font-bold text-ink">{company.name}</h3>
                  <StatusBadge
                    status={company.category}
                    label={company.category_label ?? (company.category === 'guri' ? 'Guri (household)' : 'Company')}
                    tone={company.category === 'guri' ? 'info' : 'neutral'}
                  />
                </div>
                <p className="text-xs text-ink-muted">
                  {[company.contact_person, company.phone, company.location].filter(Boolean).join(' · ') || 'No contact details'}
                </p>
                {company.aliases && company.aliases.length > 0 && (
                  <p className="text-xs text-ink-faint">Also known as: {company.aliases.map((a) => a.alias).join(', ')}</p>
                )}
              </div>
              <div className="flex items-center gap-1">
              <Link to={`/hr/companies/${company.id}/employees`} className="inline-flex h-8 items-center gap-1.5 rounded-sm border border-border px-2.5 text-xs font-semibold text-ink hover:bg-surface-alt">
                <Icon name="users" size={14} />
                Employees
              </Link>
              <PermissionGate module="hr">
                <div className="flex items-center gap-1">
                  <Button variant="outline" size="sm" onClick={() => setLocationModal({ clientCompanyId: company.id, editing: null })}>
                    <Icon name="plus" size={14} />
                    Add Location
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
                <p className="text-sm text-ink-muted">No work locations yet. A workplace is optional - employees can be assigned to the company first.</p>
              ) : (
                <div className="divide-y divide-border">
                  {company.work_locations.map((location) => (
                    <div key={location.id} className="flex items-center justify-between py-2.5">
                      <div>
                        <p className="text-sm font-medium text-ink">{location.name}</p>
                        <p className="text-xs text-ink-muted">
                          {location.location ?? 'No address'}
                          {location.capacity !== null && ` · Capacity ${location.capacity} (${location.active_assignments_count} assigned)`}
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
      show(editing ? 'Client company updated.' : 'Client company created.');
      onSaved();
    },
    onError: (err) => {
      if (err instanceof ApiClientError && err.isValidation && err.fieldErrors) {
        setFieldErrors(err.fieldErrors);
        if (err.fieldErrors.name) return;
      }
      show(friendlyErrorMessage(err, 'Something went wrong.'), 'error');
    },
  });

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={editing ? 'Edit Client Company' : 'Add Client Company'}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save</Button>
        </>
      }
    >
      <FormField
        label="Company name"
        htmlFor="cc-name"
        required
        error={
          fieldErrors.name?.[0]
            ? /taken|already/i.test(fieldErrors.name[0])
              ? 'A company with this name already exists. Names are the same even if the capital letters differ.'
              : fieldErrors.name[0]
            : undefined
        }
      >
        <input id="cc-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
      </FormField>
      <FormField label="Type" htmlFor="cc-category" hint="Choose Guri for a household. You can change this later.">
        <Select id="cc-category" value={category} onChange={(e) => setCategory(e.target.value as ClientCompanyCategory)} options={CATEGORY_OPTIONS} />
      </FormField>
      <FormField label="Contact person" htmlFor="cc-contact">
        <input id="cc-contact" className={inputClasses} value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} />
      </FormField>
      <FormField label="Phone" htmlFor="cc-phone">
        <input id="cc-phone" className={inputClasses} value={phone} onChange={(e) => setPhone(e.target.value)} />
      </FormField>
      <FormField label="Location" htmlFor="cc-location">
        <input id="cc-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
      </FormField>
      <FormField label="Notes" htmlFor="cc-notes">
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
      show('Agreement uploaded. Earlier agreements are kept as history.');
    },
    onError: (err) => show(friendlyErrorMessage(err, 'Could not upload the agreement.'), 'error'),
  });

  const downloadMutation = useMutation({
    mutationFn: (agreement: ClientCompanyAgreement) => hrApi.clientCompanies.agreements.download(company.id, agreement),
    onError: (err) => show(friendlyErrorMessage(err, 'Could not download the agreement.'), 'error'),
  });

  // Newest first = the current agreement; sort defensively in case the server order changes.
  const sorted = [...(agreements ?? [])].sort((a, b) => b.uploaded_at.localeCompare(a.uploaded_at) || b.id - a.id);
  const count = agreements?.length ?? company.agreements_count ?? 0;

  return (
    <div className="mt-4 border-t border-border pt-3">
      <button type="button" onClick={() => setIsOpen((open) => !open)} className="flex items-center gap-1.5 text-xs font-semibold text-ink-muted hover:text-primary">
        <Icon name={isOpen ? 'chevron-down' : 'chevron-right'} size={14} />
        Agreement {count > 0 ? `(${count} on file)` : '(none yet)'}
      </button>

      {isOpen && (
        <div className="mt-3 space-y-3">
          {isLoading && <p className="text-xs text-ink-faint">Loading agreements…</p>}
          {error && <p className="text-xs text-danger">{friendlyErrorMessage(error, 'Could not load agreements.')}</p>}
          {agreements && sorted.length === 0 && <p className="text-xs italic text-ink-faint">No agreement uploaded yet. This is optional.</p>}

          {sorted.length > 0 && (
            <div className="space-y-1.5">
              {sorted.map((agreement, index) => (
                <div key={agreement.id} className="flex items-center justify-between gap-3 rounded-sm border border-border px-2.5 py-1.5">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 text-sm text-ink">
                      <span className="truncate">{agreement.file_name}</span>
                      {index === 0 ? <StatusBadge status="current" label="Current" tone="success" /> : <span className="text-xs text-ink-faint">Earlier version</span>}
                    </p>
                    <p className="text-xs text-ink-faint">
                      {formatFileSize(agreement.file_size)} · uploaded {formatDate(agreement.uploaded_at)}
                      {agreement.uploaded_by && ` by ${agreement.uploaded_by}`}
                      {agreement.notes && ` · ${agreement.notes}`}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    isLoading={downloadMutation.isPending && downloadMutation.variables?.id === agreement.id}
                    onClick={() => downloadMutation.mutate(agreement)}
                  >
                    Download
                  </Button>
                </div>
              ))}
            </div>
          )}

          <PermissionGate module="hr">
            <div className="flex flex-wrap items-end gap-2">
              <div className="min-w-[10rem] flex-1">
                <label htmlFor={`agreement-notes-${company.id}`} className="mb-1 block text-xs font-medium text-ink-muted">Note (optional)</label>
                <input
                  id={`agreement-notes-${company.id}`}
                  className={inputClasses}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="e.g. Renewed for 2026"
                />
              </div>
              <Button size="sm" variant="outline" isLoading={uploadMutation.isPending} onClick={() => fileInputRef.current?.click()}>
                <Icon name="plus" size={14} />
                {sorted.length > 0 ? 'Upload new agreement' : 'Upload agreement'}
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
            <p className="text-xs text-ink-faint">PDF, Word or picture files, up to 10 MB. A new upload becomes the current agreement; the old one stays in the list.</p>
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
      show(editing ? 'Work location updated.' : 'Work location created.');
      onSaved();
    },
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
  });

  const isOfficeEdit = editing?.location_type === 'office';

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={editing ? `Edit ${isOfficeEdit ? 'Fayadhowr Office' : 'Work Location'}` : 'Add Work Location'}
      size="sm"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" isLoading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save</Button>
        </>
      }
    >
      <FormField label="Name" htmlFor="wl-name" required>
        <input id="wl-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} disabled={isOfficeEdit} />
      </FormField>
      <FormField label="Address / location" htmlFor="wl-location">
        <input id="wl-location" className={inputClasses} value={location} onChange={(e) => setLocation(e.target.value)} />
      </FormField>
      <FormField label="Contact person" htmlFor="wl-contact">
        <input id="wl-contact" className={inputClasses} value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} />
      </FormField>
      <FormField label="Phone" htmlFor="wl-phone">
        <input id="wl-phone" className={inputClasses} value={phone} onChange={(e) => setPhone(e.target.value)} />
      </FormField>
      <FormField label="Approved employee capacity" htmlFor="wl-capacity">
        <input id="wl-capacity" type="number" min={0} className={inputClasses} value={capacity} onChange={(e) => setCapacity(e.target.value)} placeholder="No limit" />
      </FormField>
      <FormField label="Notes" htmlFor="wl-notes">
        <textarea id="wl-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </FormField>
    </Modal>
  );
}
