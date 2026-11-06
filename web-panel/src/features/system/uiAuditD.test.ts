import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { permissionsUnavailable, roleAllowed, sessionOutcomeAfterFailure } from '../../utils/accessRules.ts';
import { AUDIT_ACTIONS, entityTypeChoices, entityTypeLabel } from '../../utils/auditLog.ts';
import { isClosedStatus, isReopening, statusChoices, statusToSend } from '../../utils/dataIssueEdit.ts';
import { financialLedgerLink, parsePayrollMonthParam, payrollLink } from '../../utils/hrDashboardLinks.ts';

// Regression tests of the UI audit remediation (package D). Pure rules are tested directly; where a rule lives in a React screen (no DOM here), the screen's
// source is checked for the exact wiring, the way the other screen tests of this panel do.

const SRC = fileURLToPath(new URL('../../', import.meta.url));
const read = (path: string) => readFileSync(join(SRC, path), 'utf8');
const BACKSLASH = String.fromCharCode(92);

// ---------------------------------------------------------------- D-10: a failed check is not "signed out" and not "Access restricted"

test('D-10: only 401 and 403 end a session; a network failure or a server error keeps the sign-in and offers a retry', () => {
  assert.equal(sessionOutcomeAfterFailure({ status: 401 }), 'signed_out');
  assert.equal(sessionOutcomeAfterFailure({ status: 403 }), 'signed_out');
  assert.equal(sessionOutcomeAfterFailure({ status: 500 }), 'unavailable');
  assert.equal(sessionOutcomeAfterFailure({ status: 503 }), 'unavailable');
  assert.equal(sessionOutcomeAfterFailure(new TypeError('Failed to fetch')), 'unavailable');
  assert.equal(sessionOutcomeAfterFailure(null), 'unavailable');

  const provider = read('features/auth/AuthProvider.tsx');
  assert.match(provider, /sessionOutcomeAfterFailure\(error\) === 'signed_out'/);
  assert.match(provider, /setStatus\('unavailable'\)/);
  assert.doesNotMatch(provider, /\.catch\(\(\) => \{\s*\/\/ apiRequest already cleared/, 'the old catch-all that signed everybody out is gone');
  assert.match(read('features/auth/ProtectedRoute.tsx'), /status === 'unavailable'[\s\S]*PermissionsUnavailable onRetry=\{retrySession\}/);
});

test('D-10: an unfetchable permission list is "could not check", never "no permissions"', () => {
  assert.equal(permissionsUnavailable({ isError: true, hasData: false, isSuperAdmin: false }), true);
  assert.equal(permissionsUnavailable({ isError: true, hasData: true, isSuperAdmin: false }), false, 'a failed refresh keeps the list already loaded');
  assert.equal(permissionsUnavailable({ isError: false, hasData: false, isSuperAdmin: false }), false);
  assert.equal(permissionsUnavailable({ isError: true, hasData: false, isSuperAdmin: true }), false, 'the Super Admin needs no list');

  const guard = read('features/auth/RequirePermission.tsx');
  assert.match(guard, /if \(isLoading\) return <LoadingState \/>;/, 'a spinner, not a blank page');
  assert.match(guard, /if \(isError\) return <PermissionsUnavailable onRetry=\{\(\) => void refetch\(\)\} \/>;/);
  assert.match(read('features/dashboard/DashboardGate.tsx'), /isError\) return <PermissionsUnavailable/);
});

// ---------------------------------------------------------------- UG-4 / D-11 guards

test('UG-4: Admin Users and Roles & Permissions are Super-Admin-only routes; the audit log is a role route; the compliance report needs permission and role', () => {
  const app = read('App.tsx');
  assert.match(app, /path="\/system\/admins" element=\{<RequireSuperAdmin><AdminUsersPage \/>/);
  assert.match(app, /path="\/system\/roles" element=\{<RequireSuperAdmin><RolesPermissionsPage \/>/);
  assert.match(app, /path="\/system\/audit-log" element=\{<RequireRole roles=\{\['hr_manager'\]\}><AuditLogPage \/>/);

  assert.equal(roleAllowed(['hr_manager'], 'hr_manager', false), true);
  assert.equal(roleAllowed(['hr_manager'], 'sales', false), false);
  assert.equal(roleAllowed(['hr_manager'], undefined, false), false);
  assert.equal(roleAllowed(['hr_manager'], 'super_admin', true), true);
});

// ---------------------------------------------------------------- D-5

test('D-5: the permission checkboxes are disabled and a view-only notice is shown to anybody who is not the Super Admin', () => {
  const page = read('features/system/RolesPermissionsPage.tsx');

  assert.equal((page.match(/<PermissionChecklist groups=\{groups\} selected=\{draft\} disabled=\{!actorIsSuperAdmin\}/g) ?? []).length, 2, 'both tabs');
  assert.match(page, /data-testid="roles-view-only"/);
  assert.match(page, /data-testid="overrides-view-only"/);
  assert.match(page, /warning\.system\.rolesViewOnly/);
});

// ---------------------------------------------------------------- D-3 / D-4

test('D-4: the audit action filter lists every action of the backend enum, including export', () => {
  const enumSource = readFileSync(join(SRC, '../../backend/app/Enums/AuditAction.php'), 'utf8');
  const cases = [...enumSource.matchAll(/case \w+ = '([a-z_]+)';/g)].map((match) => match[1]);

  assert.deepEqual([...AUDIT_ACTIONS], cases);
  assert.ok(AUDIT_ACTIONS.includes('export'));
});

test('D-3: the entity-type filter is a list of the stored values, labelled the way the table shows them', () => {
  assert.equal(entityTypeLabel(`App${BACKSLASH}Models${BACKSLASH}HrComplianceFinding`), 'HrComplianceFinding');
  assert.equal(entityTypeLabel('role'), 'role');

  const listed = [{ value: `App${BACKSLASH}Models${BACKSLASH}Admin`, label: 'Admin' }];
  assert.deepEqual(entityTypeChoices(listed, ''), listed);
  assert.equal(entityTypeChoices(listed, `App${BACKSLASH}Models${BACKSLASH}Admin`).length, 1);
  assert.deepEqual(entityTypeChoices(listed, 'purchase_order').at(-1), { value: 'purchase_order', label: 'purchase_order' }, 'a shared link with a value the list lacks still shows it');

  const page = read('features/system/AuditLogPage.tsx');
  assert.doesNotMatch(page, /adminsApi/, 'the Audit Log no longer needs the Super-Admin-only Admin Users API');
  assert.match(page, /auditLogsApi\.filters\(\)/);
  assert.match(page, /options=\{entityTypeChoices\(filterOptions\.data\?\.entity_types \?\? \[\], entityType\)\}/);
  assert.doesNotMatch(page, /value=\{entityType\}\s*onChange=\{[^}]*\}\s*placeholder=\{t\('option\.system\.entityTypePlaceholder'\)\}/);
  assert.match(read('api/auditLogs.ts'), /admin\/audit-logs\/filters/);
});

// ---------------------------------------------------------------- D-2

test('D-2: editing a closed Data Issue does not send a status, so it is not silently reopened', () => {
  assert.equal(isClosedStatus('resolved'), true);
  assert.equal(isClosedStatus('investigating'), false);

  for (const closed of ['resolved', 'accepted_difference', 'cannot_resolve'] as const) {
    assert.equal(statusToSend(closed, closed), undefined, `${closed} left as it is: nothing sent`);
    assert.equal(statusToSend(closed, 'investigating'), 'investigating', 'a deliberate reopen is sent');
    assert.equal(isReopening(closed, 'open'), true);
    assert.equal(isReopening(closed, closed), false);
  }

  assert.equal(statusToSend('open', 'open'), undefined);
  assert.equal(statusToSend('open', 'investigating'), 'investigating');
  assert.equal(statusToSend('investigating', 'resolved'), undefined, 'closing goes through Resolve, never through Edit');

  const all = [{ value: 'open' as const }, { value: 'investigating' as const }, { value: 'resolved' as const }, { value: 'cannot_resolve' as const }];
  assert.deepEqual(statusChoices(all, 'resolved').map((o) => o.value), ['open', 'investigating', 'resolved'], 'the closed status stays selectable so it can be kept');
  assert.deepEqual(statusChoices(all, 'open').map((o) => o.value), ['open', 'investigating']);

  const dialog = read('features/system/DataIssueEditDialog.tsx');
  assert.match(dialog, /status: statusToSend\(issue\.status as IssueStatus, form\.status\)/);
  assert.doesNotMatch(dialog, /: 'investigating'\) as 'open' \| 'investigating'/, 'the "closed becomes investigating" mapping is gone');
});

// ---------------------------------------------------------------- D-13

test('D-13: the Data Issues "System" crumb leads to a page the viewer may open, and an affected employee is a link only with hr.view', () => {
  for (const file of ['features/system/ReconciliationListPage.tsx', 'features/system/ReconciliationDetailPage.tsx']) {
    assert.doesNotMatch(read(file), /to: '\/system\/settings'/, file);
    assert.match(read(file), /to: '\/system\/data-issues'/, file);
  }
  const detail = read('features/system/ReconciliationDetailPage.tsx');
  assert.match(detail, /const canOpenEmployee = hasPermission\('hr\.view'\)/);
  assert.match(detail, /record\.employee && canOpenEmployee &&/);
});

// ---------------------------------------------------------------- D-6

test('D-6: Inspections and Complaints can show the voided records the server keeps', () => {
  for (const file of ['features/hrm/FacilityInspectionsPage.tsx', 'features/hrm/FacilityComplaintsPage.tsx']) {
    const page = read(file);
    assert.match(page, /const showVoided = searchParams\.get\('voided'\) === '1';/, file);
    assert.match(page, /include_voided: showVoided \? 1 : undefined/, file);
    assert.match(page, /data-testid="show-voided"/, file);
    assert.match(page, /showVoided\]/, `${file}: part of the query key`);
  }
  const api = read('api/facility.ts');
  assert.equal((api.match(/include_voided\?: 1/g) ?? []).length, 2);
});

// ---------------------------------------------------------------- D-7

test('D-7: every Facility Report inspection tile counts exactly the list its link opens', () => {
  const page = read('features/hrm/FacilityReportPage.tsx');

  assert.doesNotMatch(page, /inspections\.overdue \+ data\.inspections\.due/, 'the sum of two states behind a link to one is gone');
  assert.match(page, /value=\{data\.inspections\.overdue\} to=\{drillLink\('\/hr\/facility-inspections', \{ state: 'overdue' \}\)\}/);
  assert.match(page, /value=\{data\.inspections\.due\}[^\n]*to=\{drillLink\('\/hr\/facility-inspections', \{ state: 'due' \}\)\}/);
});

// ---------------------------------------------------------------- D-8 / D-9

test('D-8: the dashboard Penalties / Advances cards open the ledger for that kind and that calendar month', () => {
  assert.equal(
    financialLedgerLink('penalty', '2026-11'),
    '/hr/reports/financial-ledger?type=penalty&preset=custom&from=2026-11-01&to=2026-11-30',
  );
  assert.equal(financialLedgerLink('advance', '2028-02'), '/hr/reports/financial-ledger?type=advance&preset=custom&from=2028-02-01&to=2028-02-29');
  assert.equal(financialLedgerLink('advance', '2026-12'), '/hr/reports/financial-ledger?type=advance&preset=custom&from=2026-12-01&to=2026-12-31');
  assert.equal(financialLedgerLink('penalty', 'nonsense'), '/hr/reports/financial-ledger');

  const page = read('features/hrm/HrDashboardPage.tsx');
  assert.match(page, /financialLedgerLink\('penalty', data\.calendar_month\)/);
  assert.match(page, /financialLedgerLink\('advance', data\.calendar_month\)/);
});

test('D-8: the Company payroll cards open the payroll screen on the month they describe, and the payroll screen reads ?month=', () => {
  assert.equal(payrollLink('2026-09'), '/hr/payroll?month=2026-09');
  assert.equal(payrollLink(''), '/hr/payroll');
  assert.equal(parsePayrollMonthParam('?month=2026-09'), '2026-09');
  assert.equal(parsePayrollMonthParam('?month=2026-13'), null);
  assert.equal(parsePayrollMonthParam('?month=oct'), null);
  assert.equal(parsePayrollMonthParam(''), null);

  assert.match(read('features/hrm/HrDashboardPage.tsx'), /to=\{payrollLink\(data\.payroll\?\.period \?\? ''\)\}/);
  assert.match(read('features/hrm/PayrollRunsPage.tsx'), /useState\(\(\) => parsePayrollMonthParam\(window\.location\.search\) \?\? defaultPayrollMonth\(\)\)/);
});

test('D-9: while another month loads, the figures are marked as the old ones', () => {
  const page = read('features/hrm/HrDashboardPage.tsx');

  assert.match(page, /isFetching && isPlaceholderData \? 'opacity-60'/);
  assert.match(page, /aria-busy=\{isFetching && isPlaceholderData\}/);
});

// ---------------------------------------------------------------- UG-1: the page

test('UG-1: the Compliance page has an explicit "Run scan" button (hr.manage), says when the last scan ran, and never scans by itself', () => {
  const page = read('features/hrm/ComplianceReportPage.tsx');

  assert.match(page, /const canManage = hasPermission\('hr\.manage'\)/);
  assert.match(page, /mutationFn: \(\) => complianceApi\.scan\(\)/);
  assert.match(page, /actions=\{canManage \? <Button onClick=\{\(\) => scan\.mutate\(\)\}/);
  assert.match(page, /data-testid="compliance-last-scan"/);
  assert.match(page, /warning\.compliance\.neverScanned/);
  assert.equal((page.match(/complianceApi\.scan\(\)/g) ?? []).length, 1, 'the scan is called from the button only');
  assert.doesNotMatch(page, /useEffect\([^)]*scan/);
  assert.doesNotMatch(page, /Opening the report only reads;/, 'the contradicting old comment is gone');
  assert.match(page, /canManage=|canFollowUp=\{canManage\}/, 'the follow-up button needs hr.manage too');
});

// ---------------------------------------------------------------- D-14

test('D-14: the Reports hub lists the Compliance and Facility reports and only the reports the viewer may open', () => {
  const page = read('features/hrm/HrReportsPage.tsx');

  assert.match(page, /to: '\/hr\/reports\/compliance'/);
  assert.match(page, /to: '\/hr\/reports\/facility'/);
  assert.match(page, /isRealItemVisible\(item, hasPermission, isSuperAdmin, role\)/);
  assert.match(page, /visibleLinks\.map/);
  assert.doesNotMatch(page, /REPORT_LINKS\.map/);
  assert.doesNotMatch(page, /useState\(''\)/, 'company / location / status live in the address bar');
  assert.match(page, /url\.get\('company'\)/);
});

test('D-14: the Follow-up button sits in the first column of the compliance table, which is always in view', () => {
  const page = read('features/hrm/ComplianceReportPage.tsx');
  const firstColumn = page.indexOf('{f.employee_number}');
  const followUp = page.indexOf("t('action.compliance.followUp')");

  assert.ok(firstColumn !== -1 && followUp !== -1 && followUp - firstColumn < 400, 'right under the employee number');
});
