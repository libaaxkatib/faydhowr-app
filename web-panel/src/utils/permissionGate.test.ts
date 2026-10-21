import test from 'node:test';
import assert from 'node:assert/strict';

import { gateAllows } from './permissionGate.ts';

const has = (...keys: string[]) => (key: string) => keys.includes(key);
const modules = (...names: string[]) => (name: string) => names.includes(name);

test('HR action buttons need hr.manage, whatever the dashboard shows', () => {
  // An HR Manager without dashboard.view: no visible modules, but hr.manage -> still sees Edit/Add.
  assert.equal(gateAllows('hr', has('hr.view', 'hr.manage'), modules()), true);
  // A viewer with dashboard access sees the HR module but must not be offered write buttons.
  assert.equal(gateAllows('hr', has('hr.view'), modules('hr')), false);
  assert.equal(gateAllows('hr', has(), modules('hr')), false);
});

test('Marketing action buttons need marketing.manage (assignment needs marketing.assign), whatever the dashboard shows', () => {
  // A Marketing Manager without dashboard.view: no visible modules, but marketing.manage -> still sees Add/Edit.
  assert.equal(gateAllows('marketing', has('marketing.view', 'marketing.manage'), modules()), true);
  // A viewer with dashboard access must not be offered write buttons.
  assert.equal(gateAllows('marketing', has('marketing.view'), modules('marketing')), false);
  assert.equal(gateAllows('marketing', has(), modules('marketing')), false);
  // The assignment card needs its own key: manage alone is not enough, assign alone is.
  assert.equal(gateAllows('marketing', has('marketing.manage'), modules('marketing'), 'marketing.assign'), false);
  assert.equal(gateAllows('marketing', has('marketing.assign'), modules(), 'marketing.assign'), true);
});

test('modules without a management key keep the module-visibility rule', () => {
  assert.equal(gateAllows('customers', has(), modules('customers')), true);
  assert.equal(gateAllows('customers', has('customers.view'), modules()), false);
});
