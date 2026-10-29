import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { filterMarketingStaff, parseMarketingStaffFilters, teamsOf } from './marketingStaffFilter.ts';

const A = { id: 1, name: 'Team A' };
const B = { id: 2, name: 'Team B' };
const PEOPLE = [
  { full_name: 'Hodan Mohamed', email: 'hodan@example.test', phone: '0615550001', role: 'marketing_manager', status: 'active' as const, teams: [A, B] },
  { full_name: 'Ahmed Warsame', email: 'ahmed@example.test', phone: '0615550002', role: 'marketing_employee', status: 'active' as const, teams: [A] },
  { full_name: 'Khadra Ali', email: 'khadra@example.test', phone: null, role: 'marketing_employee', status: 'inactive' as const, teams: [B] },
  { full_name: 'No Team Person', email: 'none@example.test', role: 'marketing_employee', teams: [] },
];
const filters = (query: Record<string, string>) => parseMarketingStaffFilters((key) => query[key] ?? '');
const names = (query: Record<string, string>) => filterMarketingStaff(PEOPLE, filters(query)).map((person) => person.full_name);

test('no filter keeps everybody', () => {
  assert.equal(names({}).length, 4);
});

test('the search finds a person by name, email or phone, in any case, and every word must match', () => {
  assert.deepEqual(names({ q: 'WARSAME' }), ['Ahmed Warsame']);
  assert.deepEqual(names({ q: 'khadra@' }), ['Khadra Ali']);
  assert.deepEqual(names({ q: '0615550001' }), ['Hodan Mohamed']);
  assert.deepEqual(names({ q: 'hodan mohamed' }), ['Hodan Mohamed']);
  assert.deepEqual(names({ q: 'hodan ahmed' }), []);
});

test('role, team and status filters combine with each other and with the search', () => {
  assert.deepEqual(names({ role: 'marketing_manager' }), ['Hodan Mohamed']);
  assert.deepEqual(names({ team: '1' }), ['Hodan Mohamed', 'Ahmed Warsame']);
  assert.deepEqual(names({ team: '2', status: 'inactive' }), ['Khadra Ali']);
  assert.deepEqual(names({ team: '1', role: 'marketing_employee' }), ['Ahmed Warsame']);
  assert.deepEqual(names({ team: '1', role: 'marketing_employee', q: 'khadra' }), []);
  assert.deepEqual(names({ status: 'active' }), ['Hodan Mohamed', 'Ahmed Warsame', 'No Team Person'], 'a person without a status counts as active');
});

test('an unknown filter value in the address bar means no filter', () => {
  assert.deepEqual(filters({ role: 'owner', status: 'gone', team: 'x' }), { search: '', role: '', team: '', status: '' });
});

test('the team filter offers only teams somebody listed belongs to, once each', () => {
  assert.deepEqual(teamsOf(PEOPLE), [A, B]);
  assert.deepEqual(teamsOf([PEOPLE[3]]), []);
});

test('the Marketing Employees page uses the filter and keeps its permission-gated management buttons', () => {
  const page = readFileSync(new URL('../features/marketing/MarketingEmployeesPage.tsx', import.meta.url), 'utf8');
  assert.ok(page.includes('filterMarketingStaff(data'));
  assert.ok(page.includes('useUrlFilters()'));
  assert.ok(page.includes('canManage(employee)'));
  assert.ok(page.includes('<NoMatchState'));
});
