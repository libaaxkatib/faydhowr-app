import { useEffect, useState } from 'react';
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
import type { DataIssue, DataIssueModule, DataIssueSeverity, DataIssueType, UpdateDataIssuePayload } from '@/types/reconciliation';

interface DataIssueEditDialogProps {
  isOpen: boolean;
  onClose: () => void;
  issue: DataIssue;
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

const STATUS_OPTIONS = [
  { value: 'open', label: 'Open' },
  { value: 'investigating', label: 'Investigating' },
];

export function DataIssueEditDialog({ isOpen, onClose, issue }: DataIssueEditDialogProps) {
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [form, setForm] = useState({
    title: issue.title,
    description: issue.description ?? '',
    module: issue.module,
    issue_type: issue.issue_type,
    severity: issue.severity,
    status: (issue.status === 'open' || issue.status === 'investigating' ? issue.status : 'investigating') as 'open' | 'investigating',
    expected_value: issue.expected_value ?? '',
    actual_value: issue.actual_value ?? '',
    difference_value: issue.difference_value ?? '',
    root_cause: issue.root_cause ?? '',
    source_reference: issue.source_reference ?? '',
    notes: issue.notes ?? '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [employeeSearch, setEmployeeSearch] = useState('');
  const [toAdd, setToAdd] = useState<{ id: number; employee_number: string; full_name: string }[]>([]);
  const [toRemove, setToRemove] = useState<number[]>([]);
  const debouncedEmployeeSearch = useDebouncedValue(employeeSearch);

  useEffect(() => {
    if (isOpen) {
      setForm({
        title: issue.title,
        description: issue.description ?? '',
        module: issue.module,
        issue_type: issue.issue_type,
        severity: issue.severity,
        status: (issue.status === 'open' || issue.status === 'investigating' ? issue.status : 'investigating') as 'open' | 'investigating',
        expected_value: issue.expected_value ?? '',
        actual_value: issue.actual_value ?? '',
        difference_value: issue.difference_value ?? '',
        root_cause: issue.root_cause ?? '',
        source_reference: issue.source_reference ?? '',
        notes: issue.notes ?? '',
      });
      setToAdd([]);
      setToRemove([]);
      setFieldErrors({});
    }
  }, [isOpen, issue]);

  const { data: employeeResults } = useQuery({
    queryKey: ['employee-picker', debouncedEmployeeSearch],
    queryFn: () => hrApi.employees.list({ search: debouncedEmployeeSearch, per_page: 8 }),
    enabled: debouncedEmployeeSearch.length >= 2,
  });

  const existingIds = (issue.affected_records ?? []).filter((r) => !toRemove.includes(r.id)).map((r) => r.employee?.id);

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: UpdateDataIssuePayload = {
        title: form.title,
        description: form.description || null,
        module: form.module,
        issue_type: form.issue_type,
        severity: form.severity,
        status: form.status,
        expected_value: form.expected_value || null,
        actual_value: form.actual_value || null,
        difference_value: form.difference_value || null,
        root_cause: form.root_cause || null,
        source_reference: form.source_reference || null,
        notes: form.notes || null,
        add_affected_employees: toAdd.map((e) => ({ employee_id: e.id })),
        remove_affected_record_ids: toRemove,
      };
      return reconciliationApi.dataIssues.update(issue.id, payload);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['data-issue', issue.id] });
      queryClient.invalidateQueries({ queryKey: ['data-issues'] });
      show('Data issue updated.', 'success');
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiClientError && error.isValidation && error.fieldErrors) {
        setFieldErrors(error.fieldErrors);
        return;
      }
      show(error instanceof Error ? error.message : 'Failed to update data issue.', 'error');
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
      onClose={onClose}
      title={`Edit ${issue.issue_number}`}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button isLoading={mutation.isPending} onClick={handleSubmit}>
            Save Changes
          </Button>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-1">
        <FormField label="Title" htmlFor="edi-title" required error={fieldErrors.title?.[0]}>
          <input id="edi-title" className={inputClasses} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>

        <FormField label="Description" htmlFor="edi-description" error={fieldErrors.description?.[0]}>
          <textarea id="edi-description" rows={2} className={inputClasses + ' h-auto py-2'} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>

        <div className="grid grid-cols-4 gap-3">
          <FormField label="Module" htmlFor="edi-module" error={fieldErrors.module?.[0]}>
            <Select id="edi-module" options={MODULE_OPTIONS} value={form.module} onChange={(e) => setForm({ ...form, module: e.target.value as DataIssueModule })} />
          </FormField>
          <FormField label="Type" htmlFor="edi-type" error={fieldErrors.issue_type?.[0]}>
            <Select id="edi-type" options={TYPE_OPTIONS} value={form.issue_type} onChange={(e) => setForm({ ...form, issue_type: e.target.value as DataIssueType })} />
          </FormField>
          <FormField label="Severity" htmlFor="edi-severity" error={fieldErrors.severity?.[0]}>
            <Select id="edi-severity" options={SEVERITY_OPTIONS} value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value as DataIssueSeverity })} />
          </FormField>
          <FormField label="Status" htmlFor="edi-status" hint="Resolve via the Resolve button" error={fieldErrors.status?.[0]}>
            <Select id="edi-status" options={STATUS_OPTIONS} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as 'open' | 'investigating' })} />
          </FormField>
        </div>

        <div className="grid grid-cols-3 gap-3">
          <FormField label="Expected" htmlFor="edi-expected" error={fieldErrors.expected_value?.[0]}>
            <textarea id="edi-expected" rows={2} className={inputClasses + ' h-auto py-2'} value={form.expected_value} onChange={(e) => setForm({ ...form, expected_value: e.target.value })} />
          </FormField>
          <FormField label="Actual" htmlFor="edi-actual" error={fieldErrors.actual_value?.[0]}>
            <textarea id="edi-actual" rows={2} className={inputClasses + ' h-auto py-2'} value={form.actual_value} onChange={(e) => setForm({ ...form, actual_value: e.target.value })} />
          </FormField>
          <FormField label="Difference" htmlFor="edi-difference" error={fieldErrors.difference_value?.[0]}>
            <textarea id="edi-difference" rows={2} className={inputClasses + ' h-auto py-2'} value={form.difference_value} onChange={(e) => setForm({ ...form, difference_value: e.target.value })} />
          </FormField>
        </div>

        <FormField label="Root Cause" htmlFor="edi-root-cause" error={fieldErrors.root_cause?.[0]}>
          <textarea id="edi-root-cause" rows={2} className={inputClasses + ' h-auto py-2'} value={form.root_cause} onChange={(e) => setForm({ ...form, root_cause: e.target.value })} />
        </FormField>

        <FormField label="Source / Reference" htmlFor="edi-source" error={fieldErrors.source_reference?.[0]}>
          <input id="edi-source" className={inputClasses} value={form.source_reference} onChange={(e) => setForm({ ...form, source_reference: e.target.value })} />
        </FormField>

        <FormField label="Notes" htmlFor="edi-notes" error={fieldErrors.notes?.[0]}>
          <textarea id="edi-notes" rows={2} className={inputClasses + ' h-auto py-2'} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>

        <div className="mb-2 border-t border-border pt-4">
          <label className="mb-1.5 block text-sm font-medium text-ink">Affected Employees</label>

          {(issue.affected_records ?? []).map((record) => {
            const marked = toRemove.includes(record.id);
            return (
              <div key={record.id} className={`mb-1.5 flex items-center justify-between rounded-sm border border-border p-2 text-sm ${marked ? 'opacity-50 line-through' : ''}`}>
                <span>
                  {record.employee?.full_name} <span className="font-mono text-xs text-ink-muted">({record.employee?.employee_number})</span>
                </span>
                <button
                  type="button"
                  onClick={() => setToRemove((prev) => (marked ? prev.filter((id) => id !== record.id) : [...prev, record.id]))}
                  className="text-xs text-ink-muted hover:text-danger"
                >
                  {marked ? 'Undo' : 'Remove'}
                </button>
              </div>
            );
          })}

          {toAdd.map((e) => (
            <div key={e.id} className="mb-1.5 flex items-center justify-between rounded-sm border border-success/30 bg-success-soft/40 p-2 text-sm">
              <span>
                {e.full_name} <span className="font-mono text-xs text-ink-muted">({e.employee_number})</span>
              </span>
              <button type="button" onClick={() => setToAdd((prev) => prev.filter((x) => x.id !== e.id))} className="text-ink-muted hover:text-danger">
                <Icon name="x" size={14} />
              </button>
            </div>
          ))}

          <div className="relative mt-2">
            <input
              className={inputClasses}
              placeholder="Search to add another affected employee…"
              value={employeeSearch}
              onChange={(e) => setEmployeeSearch(e.target.value)}
            />
            {employeeResults && employeeResults.data.length > 0 && employeeSearch.length >= 2 && (
              <div className="absolute z-10 mt-1 w-full rounded-sm border border-border bg-surface shadow-card">
                {employeeResults.data
                  .filter((emp) => !existingIds.includes(emp.id) && !toAdd.some((a) => a.id === emp.id))
                  .map((emp) => (
                    <button
                      type="button"
                      key={emp.id}
                      className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-surface-alt"
                      onClick={() => {
                        setToAdd((prev) => [...prev, { id: emp.id, employee_number: emp.employee_number, full_name: emp.full_name }]);
                        setEmployeeSearch('');
                      }}
                    >
                      <span className="text-ink">{emp.full_name}</span>
                      <span className="font-mono text-xs text-ink-muted">{emp.employee_number}</span>
                    </button>
                  ))}
              </div>
            )}
          </div>
        </div>
      </form>
    </Modal>
  );
}
