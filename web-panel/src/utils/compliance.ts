import type { ComplianceFilters, ComplianceFinding, ComplianceStatus } from '@/types/compliance';

export const EMPTY_FILTERS: ComplianceFilters = { search: '', client_company_id: '', responsible_admin_id: '', employment_type: '', category: '', severity: '', status: '', confidence: '' };

/** Only the filters that are set are sent. "none" asks for the findings no record attributes to anybody. */
export function complianceQuery(filters: ComplianceFilters): Record<string, string> {
  const out: Record<string, string> = {};

  for (const [key, value] of Object.entries(filters)) {
    const text = value.trim();
    if (text !== '') out[key] = text;
  }

  return out;
}

export function hasActiveFilters(filters: ComplianceFilters): boolean {
  return Object.keys(complianceQuery(filters)).length > 0;
}

/** A finding is closed once HR (or the system, because the condition is gone) resolved it. */
export function isClosed(finding: Pick<ComplianceFinding, 'status'>): boolean {
  return finding.status === 'resolved';
}

/** Resolving needs a note about the corrective action unless one was already written (the server enforces the same). */
export function canSave(status: ComplianceStatus, note: string, existingNote: string | null): boolean {
  if (status !== 'resolved') return true;

  return note.trim().length >= 3 || (existingNote ?? '').trim() !== '';
}

/** The tone of a severity / confidence badge. A needs-review finding is never shown as a confirmed violation. */
export function severityTone(severity: string): 'danger' | 'warning' | 'neutral' {
  return severity === 'high' ? 'danger' : severity === 'medium' ? 'warning' : 'neutral';
}

export function confidenceTone(confidence: string): 'danger' | 'info' {
  return confidence === 'confirmed' ? 'danger' : 'info';
}
