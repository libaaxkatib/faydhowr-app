// Pure helpers for the Facility Inspections screens (no React, no network, no `@/` imports) so Node's test runner can import them
// (facility.test.ts). They mirror the server's rules so the form can say what is missing before the request is sent; the server re-checks
// everything.

import type {
  FacilityComplaintStatus,
  FacilityComplaintType,
  FacilityDueState,
  FacilityInspectionItemInput,
  FacilitySatisfaction,
  FacilityUniformIssueInput,
  InspectionComplaintInput,
} from '../types/facility.ts';

/** The twelve things an inspector checks, in the order they are shown. Same keys as the server's FacilityItemKey. */
export const FACILITY_ITEMS: { key: string; label: string }[] = [
  { key: 'windows', label: 'Windows' },
  { key: 'doors', label: 'Doors' },
  { key: 'cleaning_supplies', label: 'Cleaning supplies' },
  { key: 'mirrors', label: 'Mirrors' },
  { key: 'stairs', label: 'Stairs' },
  { key: 'walls', label: 'Walls' },
  { key: 'toilets', label: 'Toilets' },
  { key: 'beds', label: 'Beds' },
  { key: 'plants', label: 'Plants' },
  { key: 'employee_uniforms', label: 'Employee uniforms' },
  { key: 'electrical_sockets', label: 'Electrical sockets' },
  { key: 'fans', label: 'Fans' },
];

/** What the inspector chose for one item. "unanswered" is the starting point and blocks saving: an item is never silently "fine". */
export type ItemChoice = 'unanswered' | 'satisfactory' | 'unsatisfactory' | 'not_applicable';

export interface ItemFormState {
  key: string;
  label: string;
  choice: ItemChoice;
  issue: string;
  action: string;
}

export function blankItems(): ItemFormState[] {
  return FACILITY_ITEMS.map((item) => ({ key: item.key, label: item.label, choice: 'unanswered', issue: '', action: '' }));
}

/** The payload rows for the server. Only meaningful after validateItems() returned no problems. */
export function itemsPayload(items: readonly ItemFormState[]): FacilityInspectionItemInput[] {
  return items.map((item) => {
    if (item.choice === 'not_applicable') {
      return { item_key: item.key, applicable: false, result: null, issue_description: null, corrective_action: null };
    }
    if (item.choice === 'unsatisfactory') {
      return { item_key: item.key, applicable: true, result: 'unsatisfactory', issue_description: item.issue.trim(), corrective_action: item.action.trim() };
    }

    return { item_key: item.key, applicable: true, result: 'satisfactory', issue_description: null, corrective_action: null };
  });
}

/** Problems keyed by item key. Every item must be answered; an unsatisfactory one must say what is wrong and what will be done. */
export function validateItems(items: readonly ItemFormState[]): Record<string, string> {
  const problems: Record<string, string> = {};

  for (const item of items) {
    if (item.choice === 'unanswered') problems[item.key] = 'Answer this item: satisfactory, unsatisfactory or not applicable.';
    else if (item.choice === 'unsatisfactory' && item.issue.trim() === '') problems[item.key] = 'Describe the issue found.';
    else if (item.choice === 'unsatisfactory' && item.action.trim() === '') problems[item.key] = 'Write the corrective action.';
  }

  return problems;
}

export interface UniformIssueFormState {
  employeeId: string;
  issue: string;
  warningGiven: boolean;
  reasonNotWorn: string;
  action: string;
  followUpOn: string;
}

export function blankUniformIssue(): UniformIssueFormState {
  return { employeeId: '', issue: '', warningGiven: false, reasonNotWorn: '', action: '', followUpOn: '' };
}

export function validateUniformIssues(rows: readonly UniformIssueFormState[]): Record<number, string> {
  const problems: Record<number, string> = {};

  rows.forEach((row, index) => {
    if (row.employeeId === '') problems[index] = 'Choose the employee.';
    else if (row.issue.trim() === '') problems[index] = 'Describe the uniform issue.';
  });

  return problems;
}

export function uniformPayload(rows: readonly UniformIssueFormState[]): FacilityUniformIssueInput[] {
  return rows.map((row) => ({
    employee_id: Number(row.employeeId),
    issue: row.issue.trim(),
    warning_given: row.warningGiven,
    reason_not_worn: row.reasonNotWorn.trim() === '' ? null : row.reasonNotWorn.trim(),
    corrective_action: row.action.trim() === '' ? null : row.action.trim(),
    follow_up_on: row.followUpOn === '' ? null : row.followUpOn,
  }));
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/** Colour of a due state. "Not scheduled" and "never inspected" are never shown as fine. */
export function dueStateTone(state: FacilityDueState): Tone {
  switch (state) {
    case 'overdue':
      return 'danger';
    case 'due':
    case 'never_inspected':
      return 'warning';
    case 'due_soon':
      return 'info';
    case 'ok':
      return 'success';
    default:
      return 'neutral';
  }
}

/** Plain wording of how far away the next inspection is ("3 days overdue", "due in 5 days", "due today"). */
export function dueText(state: FacilityDueState, daysUntilDue: number | null): string {
  if (state === 'never_inspected') return 'No inspection recorded yet';
  if (state === 'no_schedule') return 'No frequency set';
  if (daysUntilDue === null) return '-';
  if (daysUntilDue < 0) return `${-daysUntilDue} day${daysUntilDue === -1 ? '' : 's'} overdue`;
  if (daysUntilDue === 0) return 'Due today';

  return `Due in ${daysUntilDue} day${daysUntilDue === 1 ? '' : 's'}`;
}

/** Status changes the server allows from each status (mirrors FacilityComplaintStatus::nextAllowed). Answered is NOT resolved. */
export const COMPLAINT_TRANSITIONS: Record<FacilityComplaintStatus, FacilityComplaintStatus[]> = {
  open: ['in_progress', 'answered', 'resolved'],
  in_progress: ['open', 'answered', 'resolved'],
  answered: ['in_progress', 'resolved'],
  resolved: ['open'],
};

export const COMPLAINT_STATUS_LABELS: Record<FacilityComplaintStatus, string> = {
  open: 'New / open',
  in_progress: 'In progress',
  answered: 'Answered',
  resolved: 'Resolved',
};

export function complaintStatusTone(status: FacilityComplaintStatus): Tone {
  switch (status) {
    case 'open':
      return 'danger';
    case 'in_progress':
      return 'warning';
    case 'answered':
      return 'info';
    default:
      return 'success';
  }
}

/** Satisfaction exists only as explicit documented feedback; anything else reads "Not recorded", never positive or negative. */
export function satisfactionText(satisfaction: 'satisfied' | 'not_satisfied' | null): string {
  if (satisfaction === 'satisfied') return 'Satisfied (documented)';
  if (satisfaction === 'not_satisfied') return 'Not satisfied (documented)';

  return 'Not recorded';
}

/** A share as a whole-number percentage, or "-" when there is nothing to divide by (never 0% of nothing). */
export function percent(part: number, whole: number): string {
  return whole <= 0 ? '-' : `${Math.round((part / whole) * 100)}%`;
}

// ---------------------------------------------------------------------------------------------------------------------
// Staff and customer complaints recorded inside the inspection form
// ---------------------------------------------------------------------------------------------------------------------

/** The inspector's answer to "was there a staff / customer complaint?". "unanswered" blocks saving: nothing is assumed. */
export type ComplaintAnswer = 'unanswered' | 'none' | 'yes';

export interface ComplaintFormState {
  type: FacilityComplaintType;
  employeeId: string;
  reporterName: string;
  reporterType: string;
  reporterPhone: string;
  details: string;
  responsiblePerson: string;
  response: string;
  reportedOn: string;
  respondedOn: string;
  correctiveAction: string;
  followUpOn: string;
  status: FacilityComplaintStatus;
  statusNote: string;
  satisfaction: '' | FacilitySatisfaction;
  satisfactionEvidence: string;
}

export function blankComplaint(type: FacilityComplaintType, reportedOn: string): ComplaintFormState {
  return {
    type,
    employeeId: '',
    reporterName: '',
    reporterType: '',
    reporterPhone: '',
    details: '',
    responsiblePerson: '',
    response: '',
    reportedOn,
    respondedOn: '',
    correctiveAction: '',
    followUpOn: '',
    status: 'open',
    statusNote: '',
    satisfaction: '',
    satisfactionEvidence: '',
  };
}

export interface ComplaintSection {
  answer: ComplaintAnswer;
  rows: ComplaintFormState[];
}

export type ComplaintProblems = Record<string, string>;

/** Problems of one section, keyed "answer" or "<row>.<field>". Mirrors the server's rules so the inspector sees them before saving. */
export function validateComplaintSection(section: ComplaintSection, type: FacilityComplaintType): ComplaintProblems {
  const noun = type === 'staff' ? 'staff' : 'customer';
  const problems: ComplaintProblems = {};

  if (section.answer === 'unanswered') {
    problems.answer = `Say whether there was a ${noun} complaint.`;

    return problems;
  }
  if (section.answer === 'none') return problems;
  if (section.rows.length === 0) {
    problems.answer = `Add the ${noun} complaint, or choose "No ${noun} complaint".`;

    return problems;
  }

  section.rows.forEach((row, i) => {
    const set = (field: string, message: string) => {
      problems[`${i}.${field}`] = message;
    };
    const filled = (value: string) => value.trim() !== '';

    if (type === 'staff' && row.employeeId === '') set('employeeId', 'Choose the employee who made the complaint.');
    if (type === 'customer' && !filled(row.reporterName)) set('reporterName', 'Enter the name of the customer who complained.');
    if (row.details.trim().length < 5) set('details', 'Describe the complaint (at least 5 characters).');
    if (!filled(row.responsiblePerson)) set('responsiblePerson', 'Enter the person or team responsible.');
    if ((row.status === 'answered' || row.status === 'resolved') && !filled(row.response)) set('response', 'A complaint that is answered or resolved needs the response that was given.');
    if ((row.status === 'answered' || row.status === 'resolved') && !filled(row.correctiveAction)) set('correctiveAction', 'Write the corrective action taken or agreed.');
    if (row.status === 'resolved' && row.statusNote.trim().length < 5) set('statusNote', 'Say how the complaint was resolved (at least a few words).');
    if (filled(row.respondedOn) && filled(row.reportedOn) && row.respondedOn < row.reportedOn) set('respondedOn', 'The response date cannot be before the complaint date.');
    if (filled(row.followUpOn) && filled(row.reportedOn) && row.followUpOn < row.reportedOn) set('followUpOn', 'The follow-up date cannot be before the complaint date.');
    if (row.satisfaction !== '' && row.satisfactionEvidence.trim().length < 10) set('satisfactionEvidence', 'Describe the documented feedback (who said it, how and when) - at least 10 characters.');
    if (row.satisfaction === '' && filled(row.satisfactionEvidence)) set('satisfaction', 'Choose what the feedback was, or clear the evidence text.');
  });

  return problems;
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

export function complaintPayload(row: ComplaintFormState): InspectionComplaintInput {
  return {
    type: row.type,
    details: row.details.trim(),
    reporter_employee_id: row.type === 'staff' && row.employeeId !== '' ? Number(row.employeeId) : null,
    reporter_name: row.type === 'customer' ? orNull(row.reporterName) : null,
    reporter_type: row.type === 'customer' ? orNull(row.reporterType) : null,
    reporter_phone: row.type === 'customer' ? orNull(row.reporterPhone) : null,
    responsible_person: row.responsiblePerson.trim(),
    response: orNull(row.response),
    reported_on: orNull(row.reportedOn),
    responded_on: orNull(row.respondedOn),
    corrective_action: orNull(row.correctiveAction),
    follow_up_on: orNull(row.followUpOn),
    status: row.status,
    status_note: orNull(row.statusNote),
    satisfaction: row.satisfaction === '' ? null : row.satisfaction,
    satisfaction_evidence: row.satisfaction === '' ? null : orNull(row.satisfactionEvidence),
  };
}

/** The two answers and the complaints, in the shape the server expects. Only meaningful once both sections validate. */
export function complaintsRequest(staff: ComplaintSection, customer: ComplaintSection) {
  return {
    staff_complaints_reported: staff.answer === 'yes',
    customer_complaints_reported: customer.answer === 'yes',
    complaints: [...(staff.answer === 'yes' ? staff.rows : []), ...(customer.answer === 'yes' ? customer.rows : [])].map(complaintPayload),
  };
}

/** How an inspection's recorded answer reads in lists: older inspections have no answer and say so instead of "no complaint". */
export function complaintAnswerText(reported: boolean | null, count: number, noun: 'staff' | 'customer'): string {
  if (reported === null) return `${noun === 'staff' ? 'Staff' : 'Customer'} complaints: not recorded`;
  if (!reported) return `No ${noun} complaint`;

  return `${count} ${noun} complaint${count === 1 ? '' : 's'}`;
}
