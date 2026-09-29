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
import type { CreateDataIssuePayload, DataIssueModule, DataIssueSeverity, DataIssueType } from '@/types/reconciliation';

interface DataIssueFormDialogProps {
  isOpen: boolean;
  onClose: () => void;
}

const MODULE_OPTIONS: { value: DataIssueModule; label: string }[] = [
  { value: 'hr', label: 'HR' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'finance', label: 'Finance' },
  { value: 'bookings', label: 'Bookings' },
  { value: 'customers', label: 'Customers' },
  { value: 'quotations', label: 'Quotations' },
  { value: 'store', label: 'Store' },
  { value: 'system', label: 'System' },
  { value: 'other', label: 'Other' },
];

const TYPE_OPTIONS: { value: DataIssueType; label: string }[] = [
  { value: 'missing_data', label: 'Missing Data' },
  { value: 'missing_record', label: 'Missing Record' },
  { value: 'duplicate', label: 'Duplicate' },
  { value: 'conflict', label: 'Conflict' },
  { value: 'source_mismatch', label: 'Source Mismatch' },
  { value: 'count_discrepancy', label: 'Count Discrepancy' },
  { value: 'invalid_record', label: 'Invalid Record' },
  { value: 'system_error', label: 'System Error' },
  { value: 'migration_issue', label: 'Migration Issue' },
  { value: 'other', label: 'Other' },
];

const SEVERITY_OPTIONS: { value: DataIssueSeverity; label: string }[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
];

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
      show(`Data issue ${issue.issue_number} recorded.`, 'success');
      resetAndClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation && error.fieldErrors) {
        setFieldErrors(error.fieldErrors);
        return;
      }
      show(error instanceof Error ? error.message : 'Failed to record data issue.', 'error');
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
      title="Record Data Issue"
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={resetAndClose}>
            Cancel
          </Button>
          <Button isLoading={mutation.isPending} onClick={handleSubmit}>
            Record Issue
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-1">
        <FormField label="Title" htmlFor="di-title" required error={fieldErrors.title?.[0]}>
          <input id="di-title" className={inputClasses} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>

        <FormField label="Description" htmlFor="di-description" error={fieldErrors.description?.[0]}>
          <textarea
            id="di-description"
            rows={2}
            className={inputClasses + ' h-auto py-2'}
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
        </FormField>

        <div className="grid grid-cols-3 gap-3">
          <FormField label="Module" htmlFor="di-module" required error={fieldErrors.module?.[0]}>
            <Select id="di-module" options={MODULE_OPTIONS} value={form.module} onChange={(e) => setForm({ ...form, module: e.target.value as DataIssueModule })} />
          </FormField>
          <FormField label="Issue Type" htmlFor="di-type" required error={fieldErrors.issue_type?.[0]}>
            <Select id="di-type" options={TYPE_OPTIONS} value={form.issue_type} onChange={(e) => setForm({ ...form, issue_type: e.target.value as DataIssueType })} />
          </FormField>
          <FormField label="Severity" htmlFor="di-severity" required error={fieldErrors.severity?.[0]}>
            <Select id="di-severity" options={SEVERITY_OPTIONS} value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value as DataIssueSeverity })} />
          </FormField>
        </div>

        <div className="grid grid-cols-3 gap-3">
          <FormField label="Expected" htmlFor="di-expected" hint="e.g. 1,316 employees" error={fieldErrors.expected_value?.[0]}>
            <textarea id="di-expected" rows={2} className={inputClasses + ' h-auto py-2'} value={form.expected_value} onChange={(e) => setForm({ ...form, expected_value: e.target.value })} />
          </FormField>
          <FormField label="Actual" htmlFor="di-actual" hint="e.g. 1,310 employees" error={fieldErrors.actual_value?.[0]}>
            <textarea id="di-actual" rows={2} className={inputClasses + ' h-auto py-2'} value={form.actual_value} onChange={(e) => setForm({ ...form, actual_value: e.target.value })} />
          </FormField>
          <FormField label="Difference" htmlFor="di-difference" hint="e.g. 6 employees" error={fieldErrors.difference_value?.[0]}>
            <textarea id="di-difference" rows={2} className={inputClasses + ' h-auto py-2'} value={form.difference_value} onChange={(e) => setForm({ ...form, difference_value: e.target.value })} />
          </FormField>
        </div>

        <FormField label="Root Cause (if known)" htmlFor="di-root-cause" error={fieldErrors.root_cause?.[0]}>
          <textarea id="di-root-cause" rows={2} className={inputClasses + ' h-auto py-2'} value={form.root_cause} onChange={(e) => setForm({ ...form, root_cause: e.target.value })} />
        </FormField>

        <FormField label="Source / Reference" htmlFor="di-source" error={fieldErrors.source_reference?.[0]}>
          <input id="di-source" className={inputClasses} value={form.source_reference} onChange={(e) => setForm({ ...form, source_reference: e.target.value })} />
        </FormField>

        <FormField label="Notes" htmlFor="di-notes" error={fieldErrors.notes?.[0]}>
          <textarea id="di-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>

        <div className="mb-4 border-t border-border pt-4">
          <label className="mb-1.5 block text-sm font-medium text-ink">Affected Employees</label>
          <div className="relative">
            <input
              className={inputClasses}
              placeholder="Search by name or employee number…"
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
                    <button type="button" onClick={() => removeEmployee(s.id)} className="text-ink-faint hover:text-danger">
                      <Icon name="x" size={14} />
                    </button>
                  </div>
                  <input
                    className={inputClasses + ' mt-2 h-8 text-xs'}
                    placeholder="Issue-specific note for this employee (optional)"
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
