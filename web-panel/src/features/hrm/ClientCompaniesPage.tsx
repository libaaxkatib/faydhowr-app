import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { PageHeader } from '@/components/ui/PageHeader';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { EmptyState } from '@/components/ui/EmptyState';
import { PermissionGate } from '@/components/ui/PermissionGate';
import { useToast } from '@/components/ui/useToast';
import type { ClientCompany, WorkLocation } from '@/types/employee';

export function ClientCompaniesPage() {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [companyModal, setCompanyModal] = useState<{ editing: ClientCompany | null } | null>(null);
  const [locationModal, setLocationModal] = useState<{ clientCompanyId: number | null; editing: WorkLocation | null } | null>(null);

  const { data: companies, isLoading, error, refetch } = useQuery({ queryKey: ['client-companies'], queryFn: hrApi.clientCompanies.list });
  const { data: locations } = useQuery({ queryKey: ['work-locations'], queryFn: () => hrApi.workLocations.list() });

  const officeLocations = (locations ?? []).filter((l) => l.location_type === 'office');

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
            <Button onClick={() => setCompanyModal({ editing: null })}>
              <Icon name="plus" size={15} />
              Add Client Company
            </Button>
          </PermissionGate>
        }
      />

      <Card className="mb-4">
        <CardHeader>
          <h3 className="font-display text-sm font-bold text-ink">Fayadhowr Office</h3>
        </CardHeader>
        <CardBody>
          {officeLocations.map((office) => (
            <div key={office.id} className="flex items-center justify-between">
              <div className="flex gap-6 text-sm">
                <div>
                  <p className="text-xs text-ink-faint">Capacity</p>
                  <p className="font-display text-lg font-bold text-ink">{office.capacity ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-ink-faint">Current</p>
                  <p className="font-display text-lg font-bold text-ink">{office.active_assignments_count}</p>
                </div>
                <div>
                  <p className="text-xs text-ink-faint">Available</p>
                  <p className="font-display text-lg font-bold text-ink">{office.available_slots ?? '—'}</p>
                </div>
              </div>
              <PermissionGate module="hr">
                <Button variant="outline" size="sm" onClick={() => setLocationModal({ clientCompanyId: null, editing: office })}>
                  <Icon name="pencil" size={14} />
                  Edit Capacity
                </Button>
              </PermissionGate>
            </div>
          ))}
        </CardBody>
      </Card>

      {companies && companies.length === 0 && <EmptyState icon="box" title="No client companies yet" />}

      <div className="flex flex-col gap-4">
        {companies?.map((company) => (
          <Card key={company.id}>
            <CardHeader>
              <div>
                <h3 className="font-display text-sm font-bold text-ink">{company.name}</h3>
                <p className="text-xs text-ink-muted">
                  {[company.contact_person, company.phone, company.location].filter(Boolean).join(' · ') || 'No contact details'}
                </p>
              </div>
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
            </CardHeader>
            <CardBody>
              {!company.work_locations || company.work_locations.length === 0 ? (
                <p className="text-sm text-ink-muted">No work locations yet.</p>
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

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        name,
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
    onError: (err) => show(err instanceof Error ? err.message : 'Something went wrong.', 'error'),
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
      <FormField label="Company name" htmlFor="cc-name" required>
        <input id="cc-name" className={inputClasses} value={name} onChange={(e) => setName(e.target.value)} />
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
