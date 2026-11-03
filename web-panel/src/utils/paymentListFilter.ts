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

/**
 * What the read-only preview (GET reports/monthly-payment-list/preview) returns: totals only, as the download would contain them. Amounts are
 * decimal strings from the server ("406.45"), never calculated here.
 */
export interface PaymentListPreview {
  period: string;
  scope: PaymentListScope;
  record: { id: number; name: string; kind: 'company' | 'guri' } | null;
  is_estimate: boolean;
  row_count: number;
  permanent_count: number;
  temporary_count: number;
  permanent_total: string;
  temporary_total: string;
  combined_total: string;
  missing_payment_number_count: number;
  currency: string;
}

/**
 * A cell of a previewed worksheet: null (empty) or [the text exactly as Excel shows it, the index of its look in the sheet's `styles`].
 * Style 0 is the plain look.
 */
export type PreviewCell = [string, number] | null;

export interface PreviewCellStyle {
  bold?: boolean;
  /** Hex colours of the workbook (a header, a highlighted missing number, a Temporary row, a red MISSING). */
  fill?: string;
  color?: string;
  number?: boolean;
  indent?: number;
}

/** One worksheet of the workbook, as the download will contain it. */
export interface PreviewSheet {
  name: string;
  row_count: number;
  column_count: number;
  /** The row holding the column titles (1-based), or null for a sheet without a table (the Summary). */
  header_row: number | null;
  headers: string[];
  frozen_at: string | null;
  filter_range: string | null;
  column_widths: (number | null)[];
  styles: PreviewCellStyle[];
  rows: PreviewCell[][];
}

/**
 * The whole workbook read back from the file the download would send, plus the totals. `content_hash` and `generated_at` go back with the download,
 * which refuses to send a file that differs from what was previewed.
 */
export interface PaymentListWorkbookPreview extends PaymentListPreview {
  generated_at: string;
  content_hash: string;
  sheets: PreviewSheet[];
}

/** The theme classes for a cell look. The workbook's own colours are mapped to theme tokens, so Light and Dark Mode both stay readable. */
export function cellClasses(style: PreviewCellStyle | undefined): string {
  if (!style) return '';
  const classes: string[] = [];

  if (style.fill === '1F3864') classes.push('bg-primary', 'text-white');
  else if (style.fill === 'FFF2CC') classes.push('bg-warning-soft');
  else if (style.fill === 'EAF1DD') classes.push('bg-success-soft');
  else if (style.fill) classes.push('bg-surface-muted');

  if (style.color === 'C00000') classes.push('text-danger');
  if (style.bold) classes.push('font-semibold');
  if (style.number) classes.push('text-right', 'tabular-nums');
  if (style.indent) classes.push('pl-5');

  return classes.join(' ');
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
