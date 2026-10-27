/**
 * Pure helpers of the monthly payment list download (owner decision 2026-10-27): HR chooses All, ONE Company or ONE Guri before downloading. The
 * choices are the Companies and Guryo that really exist (GET reports/monthly-payment-list/filters); nothing is hard-coded and the server validates the
 * record again. These helpers only decide what the form offers and what it sends.
 */

export type PaymentListScope = 'all' | 'company' | 'guri';

export interface PaymentListOption {
  id: number;
  name: string;
  status?: string;
}

export interface PaymentListFilterOptions {
  companies: PaymentListOption[];
  guryo: PaymentListOption[];
}

export const SCOPE_LABELS: Record<PaymentListScope, string> = {
  all: 'All companies and guryo',
  company: 'One company',
  guri: 'One guri',
};

/** The records the second selector lists for a scope (none for All). */
export function optionsFor(scope: PaymentListScope, options: PaymentListFilterOptions | undefined): PaymentListOption[] {
  if (!options || scope === 'all') return [];

  return scope === 'company' ? options.companies : options.guryo;
}

/** Why the selector cannot be used (no such record exists yet), or null. */
export function emptyOptionsMessage(scope: PaymentListScope, options: PaymentListFilterOptions | undefined): string | null {
  if (scope === 'all' || !options) return null;
  if (optionsFor(scope, options).length > 0) return null;

  return scope === 'company' ? 'No company exists yet, so there is nothing to choose.' : 'No guri exists yet, so there is nothing to choose.';
}

/** A download is possible for All, or when one record of the chosen kind is selected. */
export function canDownload(scope: PaymentListScope, selectedId: string, options: PaymentListFilterOptions | undefined): boolean {
  if (scope === 'all') return true;

  return optionsFor(scope, options).some((option) => String(option.id) === selectedId);
}

/** The query sent to the server: the record is named by its id only (never by name). */
export function downloadQuery(period: string, scope: PaymentListScope, selectedId: string): Record<string, string> {
  return scope === 'all' ? { period, scope: 'all' } : { period, scope, client_company_id: selectedId };
}

/** The record the selection points at, or null for All / nothing chosen. */
export function selectedOption(scope: PaymentListScope, selectedId: string, options: PaymentListFilterOptions | undefined): PaymentListOption | null {
  return optionsFor(scope, options).find((option) => String(option.id) === selectedId) ?? null;
}

export function buttonLabel(scope: PaymentListScope, selected: PaymentListOption | null): string {
  if (scope === 'all' || selected === null) return 'Download payment list (Excel)';

  return `Download ${scope === 'guri' ? 'guri' : 'company'} list: ${selected.name} (Excel)`;
}

export function fileName(period: string, selected: PaymentListOption | null): string {
  const slug = selected ? selected.name.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '') : '';

  return slug ? `payment-list-${period}-${slug}.xlsx` : `payment-list-${period}.xlsx`;
}
