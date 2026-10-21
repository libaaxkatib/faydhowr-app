import test from 'node:test';
import assert from 'node:assert/strict';

import {
  blankItems,
  blankUniformIssue,
  COMPLAINT_TRANSITIONS,
  dueStateTone,
  dueText,
  FACILITY_ITEMS,
  itemsPayload,
  percent,
  satisfactionText,
  uniformPayload,
  validateItems,
  validateUniformIssues,
  type ItemFormState,
} from './facility.ts';

test('there are twelve inspection items and a fresh form has none answered', () => {
  assert.equal(FACILITY_ITEMS.length, 12);
  const items = blankItems();
  assert.equal(items.length, 12);
  assert.ok(items.every((item) => item.choice === 'unanswered'));
  assert.equal(Object.keys(validateItems(items)).length, 12, 'an unanswered item is never assumed to be fine');
});

test('an unsatisfactory item needs the issue and the corrective action', () => {
  const items: ItemFormState[] = blankItems().map((item) => ({ ...item, choice: 'satisfactory' }));
  items[0] = { ...items[0], choice: 'unsatisfactory' };
  assert.deepEqual(Object.keys(validateItems(items)), ['windows']);
  items[0] = { ...items[0], issue: 'Cracked glass' };
  assert.match(validateItems(items).windows, /corrective action/);
  items[0] = { ...items[0], action: 'Replace the pane by Friday' };
  assert.deepEqual(validateItems(items), {});
});

test('the payload keeps satisfactory, unsatisfactory and not-applicable apart', () => {
  const items: ItemFormState[] = blankItems().map((item) => ({ ...item, choice: 'satisfactory' }));
  items[1] = { ...items[1], choice: 'not_applicable' };
  items[2] = { ...items[2], choice: 'unsatisfactory', issue: ' Empty ', action: ' Restock ' };
  const payload = itemsPayload(items);
  assert.deepEqual(payload[0], { item_key: 'windows', applicable: true, result: 'satisfactory', issue_description: null, corrective_action: null });
  assert.deepEqual(payload[1], { item_key: 'doors', applicable: false, result: null, issue_description: null, corrective_action: null });
  assert.deepEqual(payload[2], { item_key: 'cleaning_supplies', applicable: true, result: 'unsatisfactory', issue_description: 'Empty', corrective_action: 'Restock' });
});

test('a uniform issue needs an employee and a description', () => {
  assert.deepEqual(validateUniformIssues([blankUniformIssue()]), { 0: 'Choose the employee.' });
  assert.deepEqual(validateUniformIssues([{ ...blankUniformIssue(), employeeId: '4' }]), { 0: 'Describe the uniform issue.' });
  const ok = { ...blankUniformIssue(), employeeId: '4', issue: 'No apron', warningGiven: true, followUpOn: '2026-11-01' };
  assert.deepEqual(validateUniformIssues([ok]), {});
  assert.deepEqual(uniformPayload([ok]), [{ employee_id: 4, issue: 'No apron', warning_given: true, reason_not_worn: null, corrective_action: null, follow_up_on: '2026-11-01' }]);
});

test('due states are never painted as fine unless the company is up to date', () => {
  assert.equal(dueStateTone('overdue'), 'danger');
  assert.equal(dueStateTone('never_inspected'), 'warning');
  assert.equal(dueStateTone('no_schedule'), 'neutral');
  assert.equal(dueStateTone('ok'), 'success');
  assert.equal(dueText('overdue', -3), '3 days overdue');
  assert.equal(dueText('overdue', -1), '1 day overdue');
  assert.equal(dueText('due', 0), 'Due today');
  assert.equal(dueText('due_soon', 5), 'Due in 5 days');
  assert.equal(dueText('never_inspected', null), 'No inspection recorded yet');
  assert.equal(dueText('no_schedule', null), 'No frequency set');
});

test('Answered can lead to Resolved but is not Resolved, and a resolved complaint can only be re-opened', () => {
  assert.ok(COMPLAINT_TRANSITIONS.answered.includes('resolved'));
  assert.ok(!COMPLAINT_TRANSITIONS.open.includes('open'));
  assert.deepEqual(COMPLAINT_TRANSITIONS.resolved, ['open']);
});

test('satisfaction is only what was explicitly documented; shares of nothing are not shown as 0%', () => {
  assert.equal(satisfactionText(null), 'Not recorded');
  assert.equal(satisfactionText('satisfied'), 'Satisfied (documented)');
  assert.equal(percent(1, 4), '25%');
  assert.equal(percent(0, 0), '-');
});

test('both complaint questions must be answered; "no complaint" needs nothing else', async () => {
  const { validateComplaintSection, complaintsRequest } = await import('./facility.ts');
  const unanswered = { answer: 'unanswered' as const, rows: [] };
  assert.match(validateComplaintSection(unanswered, 'staff').answer, /Say whether there was a staff complaint/);
  assert.match(validateComplaintSection(unanswered, 'customer').answer, /customer complaint/);
  const none = { answer: 'none' as const, rows: [] };
  assert.deepEqual(validateComplaintSection(none, 'staff'), {});
  assert.deepEqual(complaintsRequest(none, none), { staff_complaints_reported: false, customer_complaints_reported: false, complaints: [] });
  assert.match(validateComplaintSection({ answer: 'yes', rows: [] }, 'staff').answer, /Add the staff complaint/);
});

test('a staff complaint needs an employee, a description and a responsible person; a customer one needs a customer name instead', async () => {
  const { blankComplaint, validateComplaintSection } = await import('./facility.ts');
  const staff = { answer: 'yes' as const, rows: [blankComplaint('staff', '2026-10-10')] };
  assert.deepEqual(Object.keys(validateComplaintSection(staff, 'staff')).sort(), ['0.details', '0.employeeId', '0.responsiblePerson']);
  const customer = { answer: 'yes' as const, rows: [blankComplaint('customer', '2026-10-10')] };
  assert.deepEqual(Object.keys(validateComplaintSection(customer, 'customer')).sort(), ['0.details', '0.reporterName', '0.responsiblePerson']);
  const ok = { answer: 'yes' as const, rows: [{ ...customer.rows[0], reporterName: 'Mr Ahmed', details: 'Floor was wet', responsiblePerson: 'Cleaning lead' }] };
  assert.deepEqual(validateComplaintSection(ok, 'customer'), {});
});

test('answered, resolved and satisfaction ask for what they need and answered is never treated as resolved', async () => {
  const { blankComplaint, validateComplaintSection } = await import('./facility.ts');
  const base = { ...blankComplaint('customer', '2026-10-10'), reporterName: 'Mr Ahmed', details: 'Floor was wet', responsiblePerson: 'Lead' };
  const check = (patch: Partial<typeof base>) => Object.keys(validateComplaintSection({ answer: 'yes', rows: [{ ...base, ...patch }] }, 'customer')).sort();
  assert.deepEqual(check({ status: 'answered' }), ['0.correctiveAction', '0.response']);
  assert.deepEqual(check({ status: 'answered', response: 'We will mop twice a day', correctiveAction: 'Mop twice a day' }), []);
  assert.deepEqual(check({ status: 'resolved', response: 'Done', correctiveAction: 'Done' }), ['0.statusNote']);
  assert.deepEqual(check({ satisfaction: 'satisfied' }), ['0.satisfactionEvidence']);
  assert.deepEqual(check({ satisfactionEvidence: 'Client said it was fine' }), ['0.satisfaction']);
  assert.deepEqual(check({ satisfaction: 'satisfied', satisfactionEvidence: 'Client phoned and said it was fine' }), []);
  assert.deepEqual(check({ followUpOn: '2026-10-01' }), ['0.followUpOn']);
});

test('the request carries both answers and only the complaints of a section answered "yes"', async () => {
  const { blankComplaint, complaintsRequest } = await import('./facility.ts');
  const staffRow = { ...blankComplaint('staff', '2026-10-10'), employeeId: '7', details: 'No gloves', responsiblePerson: 'Supervisor' };
  const customerRow = { ...blankComplaint('customer', '2026-10-10'), reporterName: 'Mr Ahmed', reporterPhone: '0612345678', details: 'Wet floor', responsiblePerson: 'Lead', satisfaction: 'not_satisfied' as const, satisfactionEvidence: 'Phoned the office, still dirty' };
  const request = complaintsRequest({ answer: 'yes', rows: [staffRow] }, { answer: 'yes', rows: [customerRow] });
  assert.equal(request.complaints.length, 2);
  assert.equal(request.complaints[0].reporter_employee_id, 7);
  assert.equal(request.complaints[0].reporter_name, null, 'a staff complaint never carries customer details');
  assert.equal(request.complaints[1].reporter_employee_id, null, 'a customer is never an employee');
  assert.equal(request.complaints[1].satisfaction, 'not_satisfied');
  assert.deepEqual(complaintsRequest({ answer: 'none', rows: [staffRow] }, { answer: 'yes', rows: [customerRow] }).complaints.map((c) => c.type), ['customer']);
});

test('lists say "not recorded" for inspections saved before complaints were asked, never "no complaint"', async () => {
  const { complaintAnswerText } = await import('./facility.ts');
  assert.equal(complaintAnswerText(null, 0, 'staff'), 'Staff complaints: not recorded');
  assert.equal(complaintAnswerText(false, 0, 'customer'), 'No customer complaint');
  assert.equal(complaintAnswerText(true, 2, 'staff'), '2 staff complaints');
  assert.equal(complaintAnswerText(true, 1, 'customer'), '1 customer complaint');
});

test('a saved inspection opens for correction exactly as it was recorded', async () => {
  const { itemsFromDetail, issuesFromDetail, itemsPayload } = await import('./facility.ts');
  const saved = [
    { item_key: 'windows', label: 'Windows', applicable: true, result: 'unsatisfactory' as const, issue_description: 'Cracked', corrective_action: 'Replace' },
    { item_key: 'doors', label: 'Doors', applicable: false, result: null, issue_description: null, corrective_action: null },
    { item_key: 'mirrors', label: 'Mirrors', applicable: true, result: 'satisfactory' as const, issue_description: null, corrective_action: null },
  ];
  const items = itemsFromDetail(saved);
  assert.equal(items.length, 12);
  assert.deepEqual(items.slice(0, 2).map((i) => [i.key, i.choice, i.issue, i.action]), [['windows', 'unsatisfactory', 'Cracked', 'Replace'], ['doors', 'not_applicable', '', '']]);
  assert.equal(items.find((i) => i.key === 'mirrors')?.choice, 'satisfactory');
  assert.equal(items.find((i) => i.key === 'fans')?.choice, 'unanswered', 'an item the record does not have is never assumed fine');
  const payload = itemsPayload(items.map((i) => (i.choice === 'unanswered' ? { ...i, choice: 'satisfactory' as const } : i)));
  assert.deepEqual(payload[0], { item_key: 'windows', applicable: true, result: 'unsatisfactory', issue_description: 'Cracked', corrective_action: 'Replace' });
  assert.deepEqual(issuesFromDetail([{ id: 1, employee_id: 7, employee_number: 'EMP-7', employee_name: 'A', issue: 'No apron', warning_given: true, reason_not_worn: null, corrective_action: null, follow_up_on: '2026-11-01' }]), [{ employeeId: '7', issue: 'No apron', warningGiven: true, reasonNotWorn: '', action: '', followUpOn: '2026-11-01' }]);
});

test('report drill-down links carry the report period and ignore empty or malformed values', async () => {
  const { dateParam, drillLink } = await import('./facility.ts');
  assert.equal(dateParam('2026-10-01'), '2026-10-01');
  assert.equal(dateParam('yesterday'), '');
  assert.equal(dateParam(null), '');
  assert.equal(drillLink('/hr/facility-complaints', { company: 3, status: 'unresolved', from: '2026-04-23', to: '2026-10-21', type: '' }), '/hr/facility-complaints?company=3&status=unresolved&from=2026-04-23&to=2026-10-21');
  assert.equal(drillLink('/hr/facility-inspections', {}), '/hr/facility-inspections');
});
