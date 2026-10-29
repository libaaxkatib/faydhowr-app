// The whole catalogue: every area's messages in one object, with a typed key. Each area lives in its own file so they can be reviewed separately;
// a test requires that no key appears in two areas (a duplicate would silently override the earlier one here).

import { action } from './action.ts';
import { common } from './common.ts';
import { company } from './company.ts';
import { employeeDetail } from './employeeDetail.ts';
import { employees } from './employees.ts';
import { errors } from './errors.ts';
import { customers } from './customers.ts';
import { facility } from './facility.ts';
import { hrDelta } from './hrDelta.ts';
import { hrMore } from './hrMore.ts';
import { imports } from './imports.ts';
import { labels } from './labels.ts';
import { marketing } from './marketing.ts';
import { nav } from './nav.ts';
import { option } from './option.ts';
import { ownerWarnings } from './ownerWarnings.ts';
import { payroll } from './payroll.ts';
import { pipeline } from './pipeline.ts';
import { reports } from './reports.ts';
import { serverErrors } from './serverErrors.ts';
import { shell } from './shell.ts';
import { theme } from './theme.ts';
import { reportsVisual } from './reportsVisual.ts';
import { replacementFixes } from './replacementFixes.ts';
import { uxAudit } from './uxAudit.ts';
import { status } from './status.ts';
import { system } from './system.ts';
import { effectiveCatalogue } from '../overrides.ts';
import { translate, translatePlural, translateValue, type Params } from '../translate.ts';
import type { Language } from '../language.ts';

export const AREAS = { nav, status, action, option, common, errors, employeeDetail, employees, pipeline, payroll, company, imports, facility, labels, reports, hrDelta, hrMore, ownerWarnings, system, marketing, customers, shell, serverErrors, theme, uxAudit, reportsVisual, replacementFixes } as const;

export const CATALOGUE = {
  ...nav,
  ...status,
  ...action,
  ...option,
  ...common,
  ...errors,
  ...employeeDetail,
  ...employees,
  ...pipeline,
  ...payroll,
  ...company,
  ...imports,
  ...facility,
  ...labels,
  ...reports,
  ...hrDelta,
  ...hrMore,
  ...ownerWarnings,
  ...system,
  ...marketing,
  ...customers,
  ...shell,
  ...serverErrors,
  ...theme,
  ...uxAudit,
  ...reportsVisual,
  ...replacementFixes,
} as const;

export type MessageKey = keyof typeof CATALOGUE;

/** The base of every message that has `.one` / `.other` forms, such as `action.importRows`. */
export type PluralBase = { [K in MessageKey]: K extends `${infer B}.one` ? B : never }[MessageKey];

export type Translate = (key: MessageKey, params?: Params) => string;
export type TranslatePlural = (base: PluralBase, count: number, params?: Params) => string;

export function translateKey(language: Language, key: MessageKey, params?: Params): string {
  return translate(effectiveCatalogue(CATALOGUE), language, key, params);
}

export function translateStoredValue(language: Language, prefix: string, value: string): string | undefined {
  return translateValue(effectiveCatalogue(CATALOGUE), language, prefix, value);
}

export function translateCount(language: Language, base: PluralBase, count: number, params?: Params): string {
  return translatePlural(effectiveCatalogue(CATALOGUE), language, base, count, params);
}
