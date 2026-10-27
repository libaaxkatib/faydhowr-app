import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// R1: on a 390 px phone the company card header (name + "Employees" / "Add Location" / edit / delete) was 426 px wide and the whole page scrolled sideways.
// The real width check runs in the browser QA (qa_p2_release.mjs, R1 and R1b); this guards the classes that make the header wrap, so they cannot be dropped by accident.
const source = readFileSync(new URL('../features/hrm/ClientCompaniesPage.tsx', import.meta.url), 'utf8');
const cardHeader = source.slice(source.indexOf('<CardHeader'), source.indexOf('</CardHeader>'));

test('the company card header may wrap onto a second line on a narrow screen', () => {
  assert.match(cardHeader, /<CardHeader className="flex-wrap">/);
  assert.match(cardHeader, /<div className="min-w-0">/);
});

test('both button rows of the company card wrap instead of overflowing, and keep every action', () => {
  assert.equal((cardHeader.match(/className="flex flex-wrap items-center gap-1"/g) ?? []).length, 2);
  assert.doesNotMatch(cardHeader, /className="flex items-center gap-1"/);
  for (const key of ['nav.employees', 'action.company.addLocation']) assert.ok(cardHeader.includes(`t('${key}')`), `${key} is still offered`);
  assert.ok(cardHeader.includes('setCompanyModal({ editing: company })'), 'edit is still offered');
  assert.ok(cardHeader.includes('deleteCompanyMutation.mutate(company.id)'), 'delete is still offered');
  assert.ok(cardHeader.includes('<PermissionGate module="hr">'), 'the HR permission gate is kept');
});
