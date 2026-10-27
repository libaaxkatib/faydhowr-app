import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { marketingApi } from '@/api/marketing';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { ApiClientError } from '@/api/client';
import { useI18n } from '@/i18n/useI18n';
import type { MessageKey } from '@/i18n/messages';
import type { MarketingRecord, ResponsiblePartyType } from '@/types/marketing';

interface ProjectFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  record?: MarketingRecord;
}

const PARTY_OPTIONS: { value: ResponsiblePartyType; label: string; labelKey: MessageKey }[] = [
  { value: 'company', label: 'Construction Company', labelKey: 'option.marketing.party.company' },
  { value: 'engineer', label: 'Engineer', labelKey: 'option.marketing.party.engineer' },
  { value: 'owner', label: 'Owner / Individual', labelKey: 'option.marketing.party.owner' },
  { value: 'other', label: 'Other Responsible Person', labelKey: 'option.marketing.party.other' },
];

const emptyForm = {
  responsible_party_type: 'engineer' as ResponsiblePartyType,
  company_name: '',
  responsible_person_name: '',
  phone: '',
  location: '',
  project_type: '',
  project_size: '',
  construction_completion_date: '',
  fayadhowr_work_date: '',
  description: '',
  feedback: '',
  assigned_team_id: '',
  assigned_admin_id: '',
};

/**
 * PROJECT-only form, per docs/HRM_MARKETING_SRS.md §9-10 — never shared with
 * XARUN. Company Name is optional unless responsible_party_type is
 * 'company' — the SRS's explicit critical rule.
 */
export function ProjectFormDialog({ isOpen, onClose, mode, record }: ProjectFormDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const { t } = useI18n();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const { data: teams } = useQuery({ queryKey: ['marketing-teams'], queryFn: marketingApi.teams.list });
  const { data: employees } = useQuery({ queryKey: ['marketing-employees'], queryFn: marketingApi.employees.list });

  useEffect(() => {
    if (isOpen) {
      setFieldErrors({});
      setForm(
        record?.project
          ? {
              responsible_party_type: record.project.responsible_party_type,
              company_name: record.project.company_name ?? '',
              responsible_person_name: record.project.responsible_person_name ?? '',
              phone: record.project.phone ?? '',
              location: record.project.location ?? '',
              project_type: record.project.project_type ?? '',
              project_size: record.project.project_size ?? '',
              construction_completion_date: record.project.construction_completion_date ?? '',
              fayadhowr_work_date: record.project.fayadhowr_work_date ?? '',
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
        responsible_party_type: form.responsible_party_type,
        company_name: form.responsible_party_type === 'company' ? form.company_name || null : null,
        responsible_person_name: form.responsible_person_name || null,
        phone: form.phone || null,
        location: form.location || null,
        project_type: form.project_type || null,
        project_size: form.project_size || null,
        construction_completion_date: form.construction_completion_date || null,
        fayadhowr_work_date: form.fayadhowr_work_date || null,
        description: form.description || null,
      };
      if (mode === 'create') {
        return marketingApi.project.create({
          ...base,
          assigned_team_id: form.assigned_team_id ? Number(form.assigned_team_id) : null,
          assigned_admin_id: form.assigned_admin_id ? Number(form.assigned_admin_id) : null,
        });
      }
      return marketingApi.project.update(record!.id, { ...base, feedback: form.feedback || null });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['marketing-records'] });
      if (record) queryClient.invalidateQueries({ queryKey: ['marketing-record', record.id] });
      show(mode === 'create' ? t('notify.marketing.recordRegistered', { type: 'PROJECT' }) : t('notify.marketing.recordUpdated', { type: 'PROJECT' }));
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation) {
        setFieldErrors(error.fieldErrors ?? {});
      } else {
        show(error instanceof Error ? error.message : t('notify.marketing.somethingWentWrong'), 'error');
      }
    },
  });

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === 'create' ? t('action.marketing.newRecord', { type: 'PROJECT' }) : t('confirm.marketing.editRecord', { type: 'PROJECT' })}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>{t('action.cancel')}</Button>
          <Button size="sm" onClick={handleSubmit} isLoading={mutation.isPending}>
            {mode === 'create' ? t('action.marketing.registerRecord', { type: 'PROJECT' }) : t('action.saveChanges')}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit}>
        <FormField label={t('option.marketing.responsiblePartyType')} htmlFor="responsible_party_type" required error={fieldErrors.responsible_party_type?.[0]}>
          <Select
            id="responsible_party_type"
            value={form.responsible_party_type}
            onChange={(e) => setForm({ ...form, responsible_party_type: e.target.value as ResponsiblePartyType })}
            options={PARTY_OPTIONS.map((option) => ({ value: option.value, label: t(option.labelKey) }))}
          />
        </FormField>

        {form.responsible_party_type === 'company' && (
          <FormField label={t('option.marketing.companyName')} htmlFor="company_name" required hint={t('warning.marketing.companyNameHint')} error={fieldErrors.company_name?.[0]}>
            <input id="company_name" className={inputClasses} value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} />
          </FormField>
        )}

        <div className="grid grid-cols-2 gap-3">
          <FormField label={t('option.marketing.engineerResponsible')} htmlFor="responsible_person_name" error={fieldErrors.responsible_person_name?.[0]}>
            <input id="responsible_person_name" className={inputClasses} value={form.responsible_person_name} onChange={(e) => setForm({ ...form, responsible_person_name: e.target.value })} />
          </FormField>
          <FormField label={t('option.label.phone')} htmlFor="phone" error={fieldErrors.phone?.[0]}>
            <input id="phone" className={inputClasses} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <FormField label={t('option.label.location')} htmlFor="location" error={fieldErrors.location?.[0]}>
            <input id="location" className={inputClasses} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          </FormField>
          <FormField label={t('option.marketing.projectBuildingType')} htmlFor="project_type" error={fieldErrors.project_type?.[0]}>
            <input id="project_type" placeholder={t('option.marketing.projectTypeExample')} className={inputClasses} value={form.project_type} onChange={(e) => setForm({ ...form, project_type: e.target.value })} />
          </FormField>
        </div>

        <FormField label={t('option.marketing.projectSize')} htmlFor="project_size" error={fieldErrors.project_size?.[0]}>
          <input id="project_size" className={inputClasses} value={form.project_size} onChange={(e) => setForm({ ...form, project_size: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-2 gap-3">
          <FormField label={t('option.marketing.constructionCompletionDate')} htmlFor="construction_completion_date" error={fieldErrors.construction_completion_date?.[0]}>
            <input id="construction_completion_date" type="date" className={inputClasses} value={form.construction_completion_date} onChange={(e) => setForm({ ...form, construction_completion_date: e.target.value })} />
          </FormField>
          <FormField label={t('option.marketing.fayadhowrWorkDate')} htmlFor="fayadhowr_work_date" error={fieldErrors.fayadhowr_work_date?.[0]}>
            <input id="fayadhowr_work_date" type="date" className={inputClasses} value={form.fayadhowr_work_date} onChange={(e) => setForm({ ...form, fayadhowr_work_date: e.target.value })} />
          </FormField>
        </div>

        {mode === 'create' && (
          <div className="grid grid-cols-2 gap-3">
            <FormField label={t('option.marketing.assignedTeam')} htmlFor="assigned_team_id">
              <Select
                id="assigned_team_id"
                value={form.assigned_team_id}
                onChange={(e) => setForm({ ...form, assigned_team_id: e.target.value })}
                placeholder={t('option.employees.unassigned')}
                options={(teams ?? []).map((team) => ({ value: String(team.id), label: team.name }))}
              />
            </FormField>
            <FormField label={t('option.marketing.marketingEmployee')} htmlFor="assigned_admin_id">
              <Select
                id="assigned_admin_id"
                value={form.assigned_admin_id}
                onChange={(e) => setForm({ ...form, assigned_admin_id: e.target.value })}
                placeholder={t('option.employees.unassigned')}
                options={(employees ?? []).map((e) => ({ value: String(e.id), label: e.full_name }))}
              />
            </FormField>
          </div>
        )}

        <FormField label={t('option.label.description')} htmlFor="description" error={fieldErrors.description?.[0]}>
          <textarea id="description" rows={3} className={inputClasses + ' h-auto py-2'} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>

        {mode === 'edit' && (
          <FormField label={t('option.marketing.feedback')} htmlFor="feedback" error={fieldErrors.feedback?.[0]}>
            <textarea id="feedback" rows={3} className={inputClasses + ' h-auto py-2'} value={form.feedback} onChange={(e) => setForm({ ...form, feedback: e.target.value })} />
          </FormField>
        )}
      </form>
    </Modal>
  );
}
