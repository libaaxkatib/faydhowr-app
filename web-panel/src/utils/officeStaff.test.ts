import test from 'node:test';
import assert from 'node:assert/strict';

import {
  buildPayload,
  DEFAULT_DOCUMENT_GROUPS,
  documentFileError,
  documentSummary,
  emptyForm,
  formFromSummary,
  missingDocumentGroups,
  needsAdditionalAssignmentConfirmation,
  overwriteConflicts,
  overwriteConflictsFromError,
  phoneError,
  salaryError,
  uploadPlan,
  validateOfficeStaffForm,
  type OfficeStaffDocumentGroup,
  type OfficeStaffForm,
  type OfficeStaffSummary,
} from './officeStaff.ts';

/** Run with: node --test src/utils/officeStaff.test.ts */

const TODAY = '2026-10-14';

const complete = (overrides: Partial<OfficeStaffForm> = {}): OfficeStaffForm => ({
  full_name: 'Amina Office', phone: '0615111222', position_id: '3', monthly_salary: '350.50', start_date: TODAY,
  emergency_contact_name: 'Hodan', emergency_contact_phone: '0615333444', guarantor_name: 'Yusuf', guarantor_phone: '0615555666', guarantor_relationship: '',
  ...overrides,
});

const summary = (overrides: Partial<OfficeStaffSummary> = {}): OfficeStaffSummary => ({
  id: 9, employee_number: 'EMP-000009', full_name: 'Existing Person', phone: '0615000000', status: 'active', employee_category_id: 1, category_name: 'General Cleaning',
  position_id: null, position_name: null, emergency_contact: { name: null, phone: null }, guarantor: null, current_salary: null, is_office_staff: false,
  active_assignments: [], can_add: true, cannot_add_reason: null, documents: [], ...overrides,
});

test('a complete form for a new employee has no errors', () => {
  assert.deepEqual(validateOfficeStaffForm(complete(), 'new'), {});
});

test('a complete form for an existing employee does not ask for name, phone or category', () => {
  assert.deepEqual(validateOfficeStaffForm(complete({ full_name: '', phone: '' }), 'existing'), {});
});

test('every required field is reported when missing (new employee)', () => {
  const errors = validateOfficeStaffForm(emptyForm(TODAY), 'new');
  assert.deepEqual(Object.keys(errors).sort(), [
    'emergency_contact_name', 'emergency_contact_phone', 'full_name', 'guarantor_name', 'guarantor_phone', 'monthly_salary', 'phone', 'position_id',
  ]);
});

test('existing employees still need salary, position, emergency contact and guarantor', () => {
  const errors = validateOfficeStaffForm(emptyForm(TODAY), 'existing');
  assert.deepEqual(Object.keys(errors).sort(), ['emergency_contact_name', 'emergency_contact_phone', 'guarantor_name', 'guarantor_phone', 'monthly_salary', 'position_id']);
});

test('whitespace-only text counts as missing', () => {
  const errors = validateOfficeStaffForm(complete({ full_name: '   ', guarantor_name: ' ', emergency_contact_name: '\t' }), 'new');
  assert.deepEqual(Object.keys(errors).sort(), ['emergency_contact_name', 'full_name', 'guarantor_name']);
});

test('salary: zero and normal amounts are valid; blank, text, negative, three decimals and huge are not', () => {
  for (const ok of ['0', '0.00', '120', '350.5', '350.50', ' 99 ', '9999999.99']) assert.equal(salaryError(ok), null, ok);
  for (const bad of ['', '  ', 'abc', '-5', '1e3', '10.999', '1,000', '$100', '10000000']) assert.notEqual(salaryError(bad), null, bad);
});

test('phones need at least six digits and only phone punctuation', () => {
  for (const ok of ['0615333444', '+252 615 333 444', '615-333-444', '(061) 5333444']) assert.equal(phoneError(ok), null, ok);
  for (const bad of ['', 'abc', '123', 'none', '12345', '0615#333444']) assert.notEqual(phoneError(bad), null, bad);
});

test('the emergency contact and the guarantor are validated and sent as separate sections', () => {
  const errors = validateOfficeStaffForm(complete({ emergency_contact_phone: 'x' }), 'new');
  assert.deepEqual(Object.keys(errors), ['emergency_contact_phone']);
  const payload = buildPayload('new', complete(), null);
  assert.equal(payload.emergency_contact_name, 'Hodan');
  assert.equal(payload.guarantor_name, 'Yusuf');
  assert.ok(!('guarantor_relationship' in payload), 'a blank optional field is left out');
});

test('nothing is flagged for replacement when the existing record has no details yet', () => {
  assert.deepEqual(overwriteConflicts(summary(), complete()), []);
});

test('a differing stored emergency contact, guarantor or position must be confirmed one by one', () => {
  const stored = summary({
    position_id: 7, emergency_contact: { name: 'Old Contact', phone: '0615000001' },
    guarantor: { name: 'Old Guarantor', phone: '0615000002', relationship: null, verified: false },
  });
  assert.deepEqual(overwriteConflicts(stored, complete()), ['emergency_contact', 'guarantor', 'position']);
  assert.deepEqual(overwriteConflicts(stored, complete({ emergency_contact_name: 'Old Contact', emergency_contact_phone: '0615000001' })), ['guarantor', 'position']);
});

test('the same details typed in another format are not a conflict', () => {
  const stored = summary({
    position_id: 3, emergency_contact: { name: 'hodan', phone: '+252 615 333 444' },
    guarantor: { name: 'YUSUF', phone: '615-555-666', relationship: null, verified: true },
  });
  assert.deepEqual(overwriteConflicts(stored, complete()), []);
});

test('an existing employee form is prefilled with what is already recorded, including the current salary', () => {
  const form = formFromSummary(summary({
    position_id: 4, current_salary: { amount: '275.00', currency: 'USD', frequency: 'monthly' }, emergency_contact: { name: 'E', phone: '0615000009' },
    guarantor: { name: 'G', phone: '0615000010', relationship: 'Uncle', verified: false },
  }), TODAY);
  assert.deepEqual([form.position_id, form.monthly_salary, form.emergency_contact_name, form.guarantor_phone, form.guarantor_relationship, form.start_date], ['4', '275.00', 'E', '0615000010', 'Uncle', TODAY]);
});

test('the payload for an existing employee carries the employee id and never creates a second employee', () => {
  const payload = buildPayload('existing', complete(), 9, { confirmOverwrite: ['guarantor'], confirmAdditionalAssignment: true });
  assert.equal(payload.employee_id, 9);
  assert.ok(!('full_name' in payload) && !('phone' in payload) && !('employee_category_id' in payload), 'an existing employee keeps their own category');
  assert.deepEqual(payload.confirm_overwrite, ['guarantor']);
  assert.equal(payload.confirm_additional_assignment, true);
});

test('the payload for a new employee carries identity fields, and the duplicate confirmation only when given', () => {
  const plain = buildPayload('new', complete(), null);
  assert.deepEqual([plain.full_name, plain.phone, plain.position_id, plain.monthly_salary], ['Amina Office', '0615111222', 3, '350.50']);
  assert.ok(!('employee_category_id' in plain), 'the category is never sent: the server assigns Office Staff');
  assert.ok(!('employee_id' in plain) && !('confirm_duplicate' in plain) && !('confirm_overwrite' in plain));
  const confirmed = buildPayload('new', complete(), null, { confirmDuplicate: { reason: ' Different person, shared phone. ' } });
  assert.deepEqual([confirmed.confirm_duplicate, confirmed.duplicate_reason], [true, 'Different person, shared phone.']);
});

test('the salary is sent as typed text so no float rounding can change it', () => {
  assert.equal(buildPayload('new', complete({ monthly_salary: ' 350.50 ' }), null).monthly_salary, '350.50');
});

const groups = (statuses: Record<string, 'on_file' | 'missing'>): OfficeStaffDocumentGroup[] =>
  DEFAULT_DOCUMENT_GROUPS.map((g) => ({ ...g, status: statuses[g.key] ?? 'missing', files: [] }));

test('document status counts only required groups and lists what is missing', () => {
  const g = groups({ identity: 'on_file', other: 'on_file' });
  assert.equal(documentSummary(g), '1 of 3 required documents on file');
  assert.deepEqual(missingDocumentGroups(g).map((x) => x.key), ['employment', 'guarantor']);
  assert.deepEqual(missingDocumentGroups(groups({ identity: 'on_file', employment: 'on_file', guarantor: 'on_file' })), []);
});

test('the upload plan sends one file per chosen group and nothing for groups without a file', () => {
  const file = (name: string) => ({ name }) as File;
  const ids = { 'Identity Documents': 11, 'Guarantor Documents': 13, 'Employment Documents': 12, 'Other HR Documents': 14 };
  const plan = uploadPlan({ identity: file('id.pdf'), guarantor: file('g.pdf'), employment: null }, ids, DEFAULT_DOCUMENT_GROUPS);
  assert.deepEqual(plan.map((p) => [p.key, p.categoryId]), [['identity', 11], ['guarantor', 13]]);
  assert.deepEqual(uploadPlan({}, ids, DEFAULT_DOCUMENT_GROUPS), []);
  assert.deepEqual(uploadPlan({ identity: file('id.pdf') }, {}, DEFAULT_DOCUMENT_GROUPS), [], 'an unknown category never uploads into the wrong place');
});

test('file checks mirror the server: allowed types, at most 10 MB, not empty', () => {
  assert.equal(documentFileError({ name: 'id.PDF', size: 1000 }), null);
  assert.equal(documentFileError({ name: 'scan.jpeg', size: 10 * 1024 * 1024 }), null);
  assert.match(documentFileError({ name: 'virus.exe', size: 10 }) ?? '', /not an allowed file type/);
  assert.match(documentFileError({ name: 'page.php', size: 10 }) ?? '', /not an allowed file type/);
  assert.match(documentFileError({ name: 'noextension', size: 10 }) ?? '', /not an allowed file type/);
  assert.match(documentFileError({ name: 'big.pdf', size: 10 * 1024 * 1024 + 1 }) ?? '', /larger than 10 MB/);
  assert.match(documentFileError({ name: 'empty.pdf', size: 0 }) ?? '', /empty/);
});

test('server answers asking for confirmation are recognised, anything else is not', () => {
  const conflict = { code: 'OVERWRITE_NOT_CONFIRMED', fieldErrors: { conflicts: { guarantor: { name: 'x' }, position: { name: 'y' } } } };
  assert.deepEqual(overwriteConflictsFromError(conflict), ['guarantor', 'position']);
  assert.equal(overwriteConflictsFromError({ code: 'VALIDATION_ERROR' }), null);
  assert.equal(overwriteConflictsFromError(new Error('x')), null);
  assert.deepEqual(overwriteConflictsFromError({ code: 'OVERWRITE_NOT_CONFIRMED' }), []);
  assert.equal(needsAdditionalAssignmentConfirmation({ code: 'ADDITIONAL_ASSIGNMENT_NOT_CONFIRMED' }), true);
  assert.equal(needsAdditionalAssignmentConfirmation({ code: 'EMPLOYEE_NOT_ACTIVE' }), false);
  assert.equal(needsAdditionalAssignmentConfirmation(null), false);
});
