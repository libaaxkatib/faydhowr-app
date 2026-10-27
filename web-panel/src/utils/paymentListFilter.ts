/**
 * Pure helpers of the monthly payment list download (owner decision 2026-10-27): HR chooses All, ONE Company or ONE Guri before downloading. The
 * choices are the Companies and Guryo that really exist (GET reports/monthly-payment-list/filters); nothing is hard-coded and the server validates the
 * record again. These helpers only decide what the form offers and what it sends. Visible wording comes from the language catalogue.
 */

import { tNow } from '../i18n/active.ts';

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

export const PAYMENT_LIST_SCOPES: readonly PaymentListScope[] = ['all', 'company', 'guri'];

export function scopeLabel(scope: PaymentListScope): string {
  switch (scope) {
    case 'all':
      return tNow('option.payroll.scopeAll');
    case 'company':
      return tNow('option.payroll.scopeCompany');
    case 'guri':
      return tNow('option.payroll.scopeGuri');
  }
}

/** The label above the second selector. */
export function recordFieldLabel(scope: PaymentListScope): string {
  return scope === 'guri' ? tNow('option.payroll.fieldGuri') : tNow('option.payroll.fieldCompany');
}

/** The first entry of the second selector. */
export function chooseRecordLabel(scope: PaymentListScope): string {
  return scope === 'guri' ? tNow('option.payroll.chooseGuri') : tNow('option.payroll.chooseCompany');
}

/** The records the second selector lists for a scope (none for All). */
export function optionsFor(scope: PaymentListScope, options: PaymentListFilterOptions | undefined): PaymentListOption[] {
  if (!options || scope === 'all') return [];

  return scope === 'company' ? options.companies : options.guryo;
}

/** Why the selector cannot be used (no such record exists yet), or null. */
export function emptyOptionsMessage(scope: PaymentListScope, options: PaymentListFilterOptions | undefined): string | null {
  if (scope === 'all' || !options) return null;
  if (optionsFor(scope, options).length > 0) return null;

  return scope === 'company' ? tNow('warning.payroll.noCompanyExists') : tNow('warning.payroll.noGuriExists');
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
  if (scope === 'all' || selected === null) return tNow('action.payroll.downloadList');

  return scope === 'guri' ? tNow('action.payroll.downloadGuriListNamed', { name: selected.name }) : tNow('action.payroll.downloadCompanyListNamed', { name: selected.name });
}

export function fileName(period: string, selected: PaymentListOption | null): string {
  const slug = selected ? selected.name.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '') : '';

  return slug ? `payment-list-${period}-${slug}.xlsx` : `payment-list-${period}.xlsx`;
}
