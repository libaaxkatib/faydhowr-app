import test from 'node:test';
import assert from 'node:assert/strict';

import { resolveBreadcrumbTarget } from './breadcrumbTarget.ts';

const has = (...keys: string[]) => (key: string) => keys.includes(key);

test('the Human Resources crumb goes to the dashboard only for someone who may open it', () => {
  assert.equal(resolveBreadcrumbTarget('/hr', has('hr.dashboard.view', 'hr.view')), '/hr');
  assert.equal(resolveBreadcrumbTarget('/hr', has('hr.view')), '/hr/employees');
  assert.equal(resolveBreadcrumbTarget('/hr', has()), '/hr/employees');
});

test('every other crumb target is left alone', () => {
  assert.equal(resolveBreadcrumbTarget('/hr/companies', has('hr.view')), '/hr/companies');
  assert.equal(resolveBreadcrumbTarget('/hr/employees', has()), '/hr/employees');
  assert.equal(resolveBreadcrumbTarget('/marketing', has()), '/marketing');
});
