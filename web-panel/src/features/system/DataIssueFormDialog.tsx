import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { reconciliationApi } from '@/api/reconciliation';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { FormField, inputClasses } from '@/components/ui/FormField';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/useToast';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { ApiClientError } from '@/api/client';
import { MODULE_OPTIONS, SEVERITY_OPTIONS, TYPE_OPTIONS, translateOptions } from '@/features/system/dataIssueOptions';
import { useI18n } from '@/i18n/useI18n';
import type { CreateDataIssuePayload, DataIssueModule, DataIssueSeverity, DataIssueType } from '@/types/reconciliation';

interface DataIssueFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
}

const emptyForm = {
  title: '',
  description: '',
  module: 'hr' as DataIssueModule,
  issue_type: 'count_discrepancy' as DataIssueType,
  severity: 'medium' as DataIssueSeverity,
  expected_value: '',
  actual_value: '',
  difference_value: '',
  root_cause: '',
  source_reference: '',
  notes: '',
};

interface SelectedEmployee {
  id: number;
  employee_number: string;
  full_name: string;
  context_note: string;
}

export function DataIssueFormDialog({ isOpen, onClose }: DataIssueFormDialogProps) {
  const { t, tValue } = useI18n();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [employeeSearch, setEmployeeSearch] = useState('');
  const [selected, setSelected] = useState<SelectedEmployee[]>([]);
  const debouncedEmployeeSearch = useDebouncedValue(employeeSearch);

  const { data: employeeResults } = useQuery({
    queryKey: ['employee-picker', debouncedEmployeeSearch],
    queryFn: () => hrApi.employees.list({ search: debouncedEmployeeSearch, per_page: 8 }),
    enabled: debouncedEmployeeSearch.length >= 2,
  });

  function addEmployee(id: number, employee_number: string, full_name: string) {
    if (selected.some((s) => s.id === id)) return;
    setSelected((prev) => [...prev, { id, employee_number, full_name, context_note: '' }]);
    setEmployeeSearch('');
  }

  function removeEmployee(id: number) {
    setSelected((prev) => prev.filter((s) => s.id !== id));
  }

  function updateNote(id: number, note: string) {
    setSelected((prev) => prev.map((s) => (s.id === id ? { ...s, context_note: note } : s)));
  }

  function resetAndClose() {
    setForm(emptyForm);
    setSelected([]);
    setEmployeeSearch('');
    setFieldErrors({});
    onClose();
  }

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: CreateDataIssuePayload = {
        title: form.title,
        description: form.description || null,
        module: form.module,
        issue_type: form.issue_type,
        severity: form.severity,
        expected_value: form.expected_value || null,
        actual_value: form.actual_value || null,
        difference_value: form.difference_value || null,
        root_cause: form.root_cause || null,
        source_reference: form.source_reference || null,
        notes: form.notes || null,
        affected_employees: selected.map((s) => ({ employee_id: s.id, context_note: s.context_note || null })),
      };
      return reconciliationApi.dataIssues.create(payload);
    },
    onSuccess: (issue) => {
      queryClient.invalidateQueries({ queryKey: ['data-issues'] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-summary'] });
      show(t('notify.system.issueRecorded', { number: issue.issue_number }), 'success');
      resetAndClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation && error.fieldErrors) {
        setFieldErrors(error.fieldErrors);
        return;
      }
      show(error instanceof Error ? error.message : t('notify.system.issueRecordFailed'), 'error');
    },
  });

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFieldErrors({});
    mutation.mutate();
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={resetAndClose}
      title={t('confirm.system.recordIssueTitle')}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={resetAndClose}>
            {t('action.cancel')}
          </Button>
          <Button isLoading={mutation.isPending} onClick={handleSubmit}>
            {t('action.system.recordIssue')}
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-1">
        <FormField label={t('option.system.colTitle')} htmlFor="di-title" required error={fieldErrors.title?.[0]}>
          <input id="di-title" className={inputClasses} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>

        <FormField label={t('option.label.description')} htmlFor="di-description" error={fieldErrors.description?.[0]}>
          <textarea
            id="di-description"
            rows={2}
            className={inputClasses + ' h-auto py-2'}
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
        </FormField>

        <div className="grid grid-cols-3 gap-3">
          <FormField label={t('option.system.module')} htmlFor="di-module" required error={fieldErrors.module?.[0]}>
            <Select id="di-module" options={translateOptions(MODULE_OPTIONS, 'option.system.issueModule', tValue)} value={form.module} onChange={(e) => setForm({ ...form, module: e.target.value as DataIssueModule })} />
          </FormField>
          <FormField label={t('option.system.issueType')} htmlFor="di-type" required error={fieldErrors.issue_type?.[0]}>
            <Select id="di-type" options={translateOptions(TYPE_OPTIONS, 'option.system.issueType', tValue)} value={form.issue_type} onChange={(e) => setForm({ ...form, issue_type: e.target.value as DataIssueType })} />
          </FormField>
          <FormField label={t('option.system.severity')} htmlFor="di-severity" required error={fieldErrors.severity?.[0]}>
            <Select id="di-severity" options={translateOptions(SEVERITY_OPTIONS, 'option.system.issueSeverity', tValue)} value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value as DataIssueSeverity })} />
          </FormField>
        </div>

        <div className="grid grid-cols-3 gap-3">
          <FormField label={t('option.system.expected')} htmlFor="di-expected" hint={t('warning.system.expectedHint')} error={fieldErrors.expected_value?.[0]}>
            <textarea id="di-expected" rows={2} className={inputClasses + ' h-auto py-2'} value={form.expected_value} onChange={(e) => setForm({ ...form, expected_value: e.target.value })} />
          </FormField>
          <FormField label={t('option.system.actual')} htmlFor="di-actual" hint={t('warning.system.actualHint')} error={fieldErrors.actual_value?.[0]}>
            <textarea id="di-actual" rows={2} className={inputClasses + ' h-auto py-2'} value={form.actual_value} onChange={(e) => setForm({ ...form, actual_value: e.target.value })} />
          </FormField>
          <FormField label={t('option.system.difference')} htmlFor="di-difference" hint={t('warning.system.differenceHint')} error={fieldErrors.difference_value?.[0]}>
            <textarea id="di-difference" rows={2} className={inputClasses + ' h-auto py-2'} value={form.difference_value} onChange={(e) => setForm({ ...form, difference_value: e.target.value })} />
          </FormField>
        </div>

        <FormField label={t('option.system.rootCauseIfKnown')} htmlFor="di-root-cause" error={fieldErrors.root_cause?.[0]}>
          <textarea id="di-root-cause" rows={2} className={inputClasses + ' h-auto py-2'} value={form.root_cause} onChange={(e) => setForm({ ...form, root_cause: e.target.value })} />
        </FormField>

        <FormField label={t('option.system.sourceReference')} htmlFor="di-source" error={fieldErrors.source_reference?.[0]}>
          <input id="di-source" className={inputClasses} value={form.source_reference} onChange={(e) => setForm({ ...form, source_reference: e.target.value })} />
        </FormField>

        <FormField label={t('option.label.notes')} htmlFor="di-notes" error={fieldErrors.notes?.[0]}>
          <textarea id="di-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>

        <div className="mb-4 border-t border-border pt-4">
          <label className="mb-1.5 block text-sm font-medium text-ink">{t('option.system.affectedEmployees')}</label>
          <div className="relative">
            <input
              className={inputClasses}
              placeholder={t('option.system.searchEmployeePicker')}
              value={employeeSearch}
              onChange={(e) => setEmployeeSearch(e.target.value)}
            />
            {employeeResults && employeeResults.data.length > 0 && employeeSearch.length >= 2 && (
              <div className="absolute z-10 mt-1 w-full rounded-sm border border-border bg-surface shadow-card">
                {employeeResults.data.map((emp) => (
                  <button
                    type="button"
                    key={emp.id}
                    className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-surface-alt"
                    onClick={() => addEmployee(emp.id, emp.employee_number, emp.full_name)}
                  >
                    <span className="text-ink">{emp.full_name}</span>
                    <span className="font-mono text-xs text-ink-muted">{emp.employee_number}</span>
                  </button>
                ))}
              </div>
            )}
          </div>

          {selected.length > 0 && (
            <div className="mt-3 space-y-2">
              {selected.map((s) => (
                <div key={s.id} className="rounded-sm border border-border p-2.5">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">
                      {s.full_name} <span className="font-mono text-xs text-ink-muted">({s.employee_number})</span>
                    </span>
                    <button type="button" aria-label={t('action.dataIssue.removeEmployeeNamed', { name: s.full_name })} title={t('action.dataIssue.removeEmployeeNamed', { name: s.full_name })} onClick={() => removeEmployee(s.id)} className="text-ink-faint hover:text-danger">
                      <Icon name="x" size={14} />
                    </button>
                  </div>
                  <input
                    className={inputClasses + ' mt-2 h-8 text-xs'}
                    placeholder={t('option.system.employeeNotePlaceholder')}
                    value={s.context_note}
                    onChange={(e) => updateNote(s.id, e.target.value)}
                  />
                </div>
              ))}
            </div>
          )}
        </div>
      </form>
    </Modal>
  );
}
