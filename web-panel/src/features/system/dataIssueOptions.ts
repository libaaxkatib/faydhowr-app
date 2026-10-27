import type { DataIssueModule, DataIssueSeverity, DataIssueStatus, DataIssueType } from '@/types/reconciliation';

/** The choices shared by the data-issue list filters and the record/edit dialogs. The English labels are what the screens always showed. */
export const MODULE_OPTIONS: { value: DataIssueModule; label: string }[] = [
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

export const TYPE_OPTIONS: { value: DataIssueType; label: string }[] = [
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

export const SEVERITY_OPTIONS: { value: DataIssueSeverity; label: string }[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
];

export const STATUS_OPTIONS: { value: DataIssueStatus; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'investigating', label: 'Investigating' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'accepted_difference', label: 'Accepted Difference' },
  { value: 'cannot_resolve', label: 'Cannot Resolve' },
];

/** The statuses the edit dialog offers (closing a data issue goes through the Resolve dialog). */
export const EDITABLE_STATUS_OPTIONS = STATUS_OPTIONS.filter((option) => option.value === 'open' || option.value === 'investigating');

/** The same options with the Somali label when Somali is chosen (`tValue` from `useI18n()`); the English label is kept otherwise. */
export function translateOptions<T extends string>(
  options: readonly { value: T; label: string }[],
  prefix: string,
  tValue: (prefix: string, value: string) => string | undefined,
): { value: T; label: string }[] {
  return options.map((option) => ({ value: option.value, label: tValue(prefix, option.value) ?? option.label }));
}
