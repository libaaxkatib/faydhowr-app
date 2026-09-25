import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import type { MarketingRecord } from '@/types/marketing';

interface XarunFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  record?: MarketingRecord;
}

const NEED_OPTIONS = ['General Cleaning', 'Deep Cleaning', 'Pest Control', 'Tree Cutting', 'Other Services'];

const emptyForm = {
  facility_name: '',
  manager_name: '',
  manager_title: '',
  phone: '',
  location: '',
  needs: [] as string[],
  description: '',
  feedback: '',
  assigned_team_id: '',
  assigned_admin_id: '',
};

/** XARUN-only form, per docs/HRM_MARKETING_SRS.md §7-8 — never shared with PROJECT. */
export function XarunFormDialog({ isOpen, onClose, mode, record }: XarunFormDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const { data: teams } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  useEffect(() => {
    if (isOpen) {
      setFieldErrors({});
      setForm(
        record?.xarun
          ? {
              facility_name: record.xarun.facility_name,
              manager_name: record.xarun.manager_name ?? '',
              manager_title: record.xarun.manager_title ?? '',
              phone: record.xarun.phone ?? '',
              location: record.xarun.location ?? '',
              needs: record.xarun.needs ?? [],
              description: record.description ?? '',
              feedback: record.feedback ?? '',
              assigned_team_id: record.assigned_team_id ? String(record.assigned_team_id) : '',
              assigned_admin_id: record.assigned_admin_id ? String(record.assigned_admin_id) : '',
            }
          : emptyForm,
      );
    }
  }, [isOpen, record]);

  const mutation = useMutation({
    mutationFn: async () => {
      const base = {
        facility_name: form.facility_name,
        manager_name: form.manager_name || null,
        manager_title: form.manager_title || null,
        phone: form.phone || null,
        location: form.location || null,
        needs: form.needs.length > 0 ? form.needs : null,
        description: form.description || null,
      };
      if (mode === 'create') {
        return marketingApi.xarun.create({
          ...base,
          assigned_team_id: form.assigned_team_id ? Number(form.assigned_team_id) : null,
          assigned_admin_id: form.assigned_admin_id ? Number(form.assigned_admin_id) : null,
        });
      }
      return marketingApi.xarun.update(record!.id, { ...base, feedback: form.feedback || null });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-records'] });
      if (record) queryClient.invalidateQueries({ queryKey: ['marketing-record', record.id] });
      show(mode === 'create' ? 'XARUN registered.' : 'XARUN updated.');
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : 'Something went wrong.', 'error');
      }
    },
  });

  function toggleNeed(need: string) {
    setForm((prev) => ({
      ...prev,
      needs: prev.needs.includes(need) ? prev.needs.filter((n) => n !== need) : [...prev.needs, need],
    }));
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === 'create' ? 'New XARUN' : 'Edit XARUN'}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>
            {mode === 'create' ? 'Register XARUN' : 'Save Changes'}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <FormField label="Facility / Company name" htmlFor="facility_name" required error={fieldErrors.facility_name?.[0]}>
          <input id="facility_name" required className={inputClasses} value={form.facility_name} onChange={(e) => setForm({ ...form, facility_name: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Manager / contact person" htmlFor="manager_name" error={fieldErrors.manager_name?.[0]}>
            <input id="manager_name" className={inputClasses} value={form.manager_name} onChange={(e) => setForm({ ...form, manager_name: e.target.value })} />
          </FormField>
          <FormField label="Contact title" htmlFor="manager_title" error={fieldErrors.manager_title?.[0]}>
            <input id="manager_title" className={inputClasses} value={form.manager_title} onChange={(e) => setForm({ ...form, manager_title: e.target.value })} />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField label="Phone" htmlFor="phone" error={fieldErrors.phone?.[0]}>
            <input id="phone" className={inputClasses} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          </FormField>
          <FormField label="Location area" htmlFor="location" error={fieldErrors.location?.[0]}>
            <input id="location" className={inputClasses} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          </FormField>
        </div>

        <FormField label="Need / service required" htmlFor="needs">
          <div className="flex flex-wrap gap-2">
            {NEED_OPTIONS.map((need) => (
              <button
                key={need}
                type="button"
                onClick={() => toggleNeed(need)}
                className={`rounded-full border px-3 py-1.5 text-xs font-medium transition ${
                  form.needs.includes(need) ? 'border-primary bg-primary-soft text-primary' : 'border-border text-ink-muted hover:bg-surface-alt'
                }`}
              >
                {need}
              </button>
            ))}
          </div>
        </FormField>

        {mode === 'create' && (
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Assigned team" htmlFor="assigned_team_id">
              <Select
                id="assigned_team_id"
                value={form.assigned_team_id}
                onChange={(e) => setForm({ ...form, assigned_team_id: e.target.value })}
                placeholder="Unassigned"
                options={(teams ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
              />
            </FormField>
            <FormField label="Marketing employee" htmlFor="assigned_admin_id">
              <Select
                id="assigned_admin_id"
                value={form.assigned_admin_id}
                onChange={(e) => setForm({ ...form, assigned_admin_id: e.target.value })}
                placeholder="Unassigned"
                options={(employees ?? []).map((e) => ({ value: String(e.id), label: e.full_name }))}
              />
            </FormField>
          </div>
        )}

        <FormField label="Description" htmlFor="description" error={fieldErrors.description?.[0]}>
          <textarea id="description" rows={3} className={inputClasses + ' h-auto py-2'} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>

        {mode === 'edit' && (
          <FormField label="Feedback" htmlFor="feedback" error={fieldErrors.feedback?.[0]}>
            <textarea id="feedback" rows={3} className={inputClasses + ' h-auto py-2'} value={form.feedback} onChange={(e) => setForm({ ...form, feedback: e.target.value })} />
          </FormField>
        )}
      </form>
    </Modal>
  );
}
