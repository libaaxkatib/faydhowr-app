import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = fileURLToPath(new URL('../../', import.meta.url));
const read = (path: string) => readFileSync(join(SRC, path), 'utf8');

test('the compliance report needs the HR report permission (the server also limits it to the HR Manager and Super Admin)', () => {
  assert.match(read('App.tsx'), /path="\/hr\/reports\/compliance" element=\{<RequirePermission permission="hr\.reports\.view"><RequirePermission permission="hr\.view"><ComplianceReportPage \/>/);
  assert.match(read('layouts/nav.config.ts'), /to: '\/hr\/reports\/compliance'[^}]*permission: 'hr\.reports\.view'[^}]*alsoPermission: 'hr\.view'/);
});

test('the client calls the routes the server defines, and only the follow-up writes', () => {
  const api = read('api/compliance.ts');
  const routes = readFileSync(join(SRC, '../../backend/routes/api.php'), 'utf8');

  assert.ok(api.includes('admin/hr/compliance/findings'));
  assert.ok(routes.includes("Route::prefix('v1/admin/hr/compliance')"));
  assert.ok(routes.includes("Route::get('findings', [AdminComplianceController::class, 'index'])"));
  assert.ok(routes.includes("Route::patch('findings/{finding}', [AdminComplianceController::class, 'update'])"));
  assert.equal((api.match(/method: '(POST|PATCH|PUT|DELETE)'/g) ?? []).length, 1, 'one write: the follow-up (status and note)');
  assert.match(api, /method: 'PATCH'/);
});

test('the page never offers to change an employee, a status, a salary or payroll', () => {
  const page = read('features/hrm/ComplianceReportPage.tsx');

  assert.doesNotMatch(page, /hrApi|payrollApi|statusApi|deactivate\(|terminate\(|suspend\(/i);
  assert.match(page, /complianceApi\.update\(finding\.id, \{ status, note \}\)/);
});

test('a finding that no record attributes to anybody says so instead of naming a manager', () => {
  const page = read('features/hrm/ComplianceReportPage.tsx');

  assert.match(page, /f\.responsible \? \(/);
  assert.match(page, /warning\.compliance\.responsibleNotRecorded/);
});
