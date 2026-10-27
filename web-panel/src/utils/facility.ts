// Pure helpers for the Facility Inspections screens (no React, no network, no `@/` imports) so Node's test runner can import them
// (facility.test.ts). They mirror the server's rules so the form can say what is missing before the request is sent; the server re-checks
// everything.

import { getActiveLanguage, tNow, tNowCount } from '../i18n/active.ts';
import { SOMALI_MONTHS, SOMALI_MONTHS_SHORT } from '../i18n/dates.ts';
import type {
  DeepCleaningInput,
  FacilityDeepCleaningDetail,
  FacilityDeepCleaningRow,
  FacilityDeepCleaningState,
  FacilityInspectionItemDetail,
  FacilityUniformIssueDetail,
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
    if (item.choice === 'unanswered') problems[item.key] = tNow('validation.facility.answerItem');
    else if (item.choice === 'unsatisfactory' && item.issue.trim() === '') problems[item.key] = tNow('validation.facility.describeIssue');
    else if (item.choice === 'unsatisfactory' && item.action.trim() === '') problems[item.key] = tNow('validation.facility.correctiveAction');
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
    if (row.employeeId === '') problems[index] = tNow('validation.facility.chooseEmployee');
    else if (row.issue.trim() === '') problems[index] = tNow('validation.facility.uniformIssue');
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
  if (state === 'never_inspected') return tNow('status.facility.neverRecorded');
  if (state === 'no_schedule') return tNow('option.facility.state.no_schedule');
  if (daysUntilDue === null) return '-';
  if (daysUntilDue < 0) return tNowCount('status.facility.daysOverdue', -daysUntilDue);
  if (daysUntilDue === 0) return tNow('status.facility.dueToday');

  return tNowCount('status.facility.dueIn', daysUntilDue);
}

/** Status changes the server allows from each status (mirrors FacilityComplaintStatus::nextAllowed). Answered is NOT resolved. */
export const COMPLAINT_TRANSITIONS: Record<FacilityComplaintStatus, FacilityComplaintStatus[]> = {
  open: ['in_progress', 'answered', 'resolved'],
  in_progress: ['open', 'answered', 'resolved'],
  answered: ['in_progress', 'resolved'],
  resolved: ['open'],
};

export const COMPLAINT_STATUS_LABELS: Record<FacilityComplaintStatus, string> = {
  get open() {
    return tNow('option.facility.status.open');
  },
  get in_progress() {
    return tNow('option.facility.status.in_progress');
  },
  get answered() {
    return tNow('option.facility.status.answered');
  },
  get resolved() {
    return tNow('option.facility.status.resolved');
  },
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
  if (satisfaction === 'satisfied') return tNow('option.facility.satisfaction.satisfied');
  if (satisfaction === 'not_satisfied') return tNow('option.facility.satisfaction.not_satisfied');

  return tNow('option.facility.satisfaction.not_recorded');
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
  const problems: ComplaintProblems = {};

  if (section.answer === 'unanswered') {
    problems.answer = tNow(type === 'staff' ? 'validation.facility.staffAnswer' : 'validation.facility.customerAnswer');

    return problems;
  }
  if (section.answer === 'none') return problems;
  if (section.rows.length === 0) {
    problems.answer = tNow(type === 'staff' ? 'validation.facility.staffAdd' : 'validation.facility.customerAdd');

    return problems;
  }

  section.rows.forEach((row, i) => {
    const set = (field: string, message: string) => {
      problems[`${i}.${field}`] = message;
    };
    const filled = (value: string) => value.trim() !== '';

    if (type === 'staff' && row.employeeId === '') set('employeeId', tNow('validation.facility.complainantEmployee'));
    if (type === 'customer' && !filled(row.reporterName)) set('reporterName', tNow('validation.facility.complainantCustomer'));
    if (row.details.trim().length < 5) set('details', tNow('validation.facility.complaintDetails'));
    if (!filled(row.responsiblePerson)) set('responsiblePerson', tNow('validation.facility.responsible'));
    if ((row.status === 'answered' || row.status === 'resolved') && !filled(row.response)) set('response', tNow('validation.facility.needsResponse'));
    if ((row.status === 'answered' || row.status === 'resolved') && !filled(row.correctiveAction)) set('correctiveAction', tNow('validation.facility.needsCorrectiveAction'));
    if (row.status === 'resolved' && row.statusNote.trim().length < 5) set('statusNote', tNow('validation.facility.needsResolution'));
    if (filled(row.respondedOn) && filled(row.reportedOn) && row.respondedOn < row.reportedOn) set('respondedOn', tNow('validation.facility.responseBeforeComplaint'));
    if (filled(row.followUpOn) && filled(row.reportedOn) && row.followUpOn < row.reportedOn) set('followUpOn', tNow('validation.facility.followUpBeforeComplaint'));
    if (row.satisfaction !== '' && row.satisfactionEvidence.trim().length < 10) set('satisfactionEvidence', tNow('validation.facility.feedbackEvidence'));
    if (row.satisfaction === '' && filled(row.satisfactionEvidence)) set('satisfaction', tNow('validation.facility.feedbackChoice'));
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
  if (reported === null) return tNow(noun === 'staff' ? 'status.facility.staffComplaintsNotRecorded' : 'status.facility.customerComplaintsNotRecorded');
  if (!reported) return tNow(noun === 'staff' ? 'option.facility.noStaffComplaint' : 'option.facility.noCustomerComplaint');

  return tNowCount(noun === 'staff' ? 'status.facility.staffComplaints' : 'status.facility.customerComplaints', count);
}

/** The saved checklist as the form's state, so an inspection can be opened for correction exactly as it was recorded. */
export function itemsFromDetail(saved: readonly FacilityInspectionItemDetail[]): ItemFormState[] {
  return blankItems().map((blank) => {
    const row = saved.find((item) => item.item_key === blank.key);
    if (!row || !row.applicable) return { ...blank, choice: row ? 'not_applicable' : 'unanswered' };
    if (row.result === 'unsatisfactory') return { ...blank, choice: 'unsatisfactory', issue: row.issue_description ?? '', action: row.corrective_action ?? '' };

    return { ...blank, choice: row.result === 'satisfactory' ? 'satisfactory' : 'unanswered' };
  });
}

export function issuesFromDetail(saved: readonly FacilityUniformIssueDetail[]): UniformIssueFormState[] {
  return saved.map((row) => ({
    employeeId: String(row.employee_id),
    issue: row.issue,
    warningGiven: row.warning_given,
    reasonNotWorn: row.reason_not_worn ?? '',
    action: row.corrective_action ?? '',
    followUpOn: row.follow_up_on ?? '',
  }));
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** A date taken from the address bar, or '' when it is missing or malformed (a bad link never half-applies a filter). */
export function dateParam(value: string | null): string {
  return value !== null && ISO_DATE.test(value) ? value : '';
}

/**
 * The address a Facility Report figure drills down to. It carries the report's own period (and complaint type) so the list that opens shows
 * exactly the records behind the number, not everything ever recorded.
 */
export function drillLink(path: string, params: Record<string, string | number | undefined | null>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
  }
  const text = query.toString();

  return text === '' ? path : `${path}?${text}`;
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Monthly Deep Cleaning. A routine inspection says nothing about it: the section only produces a payload when the user explicitly records,
// corrects or withdraws a completion, so saving an ordinary inspection never marks (or unmarks) a Deep Cleaning.

/** 'none' = this inspection records no Deep Cleaning (the starting point); 'completed' = the user states it was completed, on `completedOn`. */
export interface DeepCleaningFormState {
  choice: 'none' | 'completed';
  completedOn: string;
  notes: string;
  /** Needed only when an already recorded completion is changed or withdrawn. */
  changeReason: string;
}

export const DEEP_CLEANING_REASON_MIN = 5;

export function blankDeepCleaning(): DeepCleaningFormState {
  return { choice: 'none', completedOn: '', notes: '', changeReason: '' };
}

/** The form for a saved inspection: its counted completion, or the blank "none" when it has none (or the one it had was withdrawn). */
export function deepCleaningFromDetail(saved: FacilityDeepCleaningDetail | null): DeepCleaningFormState {
  return saved && !saved.withdrawn ? { choice: 'completed', completedOn: saved.completed_on, notes: saved.notes ?? '', changeReason: '' } : blankDeepCleaning();
}

function isCounted(saved: FacilityDeepCleaningDetail | null): saved is FacilityDeepCleaningDetail {
  return saved !== null && !saved.withdrawn;
}

/** True when the form differs from what is saved in a way the server treats as a correction (needs a reason). */
function changesSaved(form: DeepCleaningFormState, saved: FacilityDeepCleaningDetail | null): boolean {
  if (saved === null) return false;
  if (saved.withdrawn) return form.choice === 'completed';
  if (form.choice === 'none') return true;

  return form.completedOn !== saved.completed_on || form.notes.trim() !== (saved.notes ?? '').trim();
}

/** What is wrong with the section, in words. `today` is YYYY-MM-DD in the user's own time zone. */
export function validateDeepCleaning(form: DeepCleaningFormState, saved: FacilityDeepCleaningDetail | null, today: string): string[] {
  const problems: string[] = [];

  if (form.choice === 'completed') {
    if (form.completedOn === '') problems.push(tNow('validation.hr.dcEnterDate'));
    else if (form.completedOn > today) problems.push(tNow('validation.hr.dcDateFuture'));
  }
  if (changesSaved(form, saved) && form.changeReason.trim().length < DEEP_CLEANING_REASON_MIN) {
    problems.push(
      form.choice === 'none'
        ? tNow('validation.hr.dcReasonWithdraw')
        : tNow('validation.hr.dcReasonChange'),
    );
  }

  return problems;
}

/** The `deep_cleaning` object to send, or undefined when the inspection should say nothing about Deep Cleaning (and any saved record stays as it is). */
export function deepCleaningPayload(form: DeepCleaningFormState, saved: FacilityDeepCleaningDetail | null): DeepCleaningInput | undefined {
  const reason = form.changeReason.trim() === '' ? null : form.changeReason.trim();

  if (form.choice === 'none') {
    return isCounted(saved) ? { completed: false, change_reason: reason } : undefined;
  }
  if (isCounted(saved) && !changesSaved(form, saved)) return undefined;

  return { completed: true, completed_on: form.completedOn, notes: form.notes.trim() === '' ? null : form.notes.trim(), change_reason: saved === null ? null : reason };
}

export function deepCleaningStateTone(state: FacilityDeepCleaningState): Tone {
  switch (state) {
    case 'completed':
      return 'success';
    case 'not_completed':
      return 'warning';
    case 'overdue_multiple':
      return 'danger';
    default:
      return 'neutral';
  }
}

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** '2026-10' -> 'October 2026'; anything else is returned unchanged. */
export function monthLabel(month: string): string {
  const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(month);

  if (!match) return month;
  const names = getActiveLanguage() === 'so' ? SOMALI_MONTHS : MONTH_NAMES;

  return `${names[Number(match[2]) - 1]} ${match[1]}`;
}

/** The sentence under a facility's status: how long it has gone without a recorded Deep Cleaning. Words, not colour, carry the meaning. */
export function deepCleaningGapText(row: Pick<FacilityDeepCleaningRow, 'state' | 'months_without'>): string {
  switch (row.state) {
    case 'completed':
      return tNow('status.hr.dcGapDone');
    case 'not_completed':
      return tNow('status.hr.dcGapNotYet');
    case 'overdue_multiple':
      return tNow('status.hr.dcGapMonthsInARow', { n: row.months_without });
    default:
      return tNow('status.hr.dcNeverRecorded');
  }
}

/** '2026-08' -> 'Aug' (for the six-month strip). */
export function shortMonth(month: string): string {
  const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(month);

  if (!match) return month;

  return getActiveLanguage() === 'so' ? SOMALI_MONTHS_SHORT[Number(match[2]) - 1] : MONTH_NAMES[Number(match[2]) - 1].slice(0, 3);
}

// ---------------------------------------------------------------------------------------------------------------------------------
// The business calendar of Deep Cleaning: Africa/Mogadishu (UTC+3), the same zone the server uses (FacilityCalendar::TIMEZONE). The browser's own
// timezone is never used for a Deep Cleaning date or month, so the form, the month picker and the server always agree on "today".

export const FACILITY_TIME_ZONE = 'Africa/Mogadishu';

function calendarParts(now: Date): { year: string; month: string; day: string } {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: FACILITY_TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? '';

  return { year: pick('year'), month: pick('month'), day: pick('day') };
}

/** Today's date in Mogadishu as YYYY-MM-DD, whatever timezone the browser is set to. */
export function facilityToday(now: Date = new Date()): string {
  const { year, month, day } = calendarParts(now);

  return `${year}-${month}-${day}`;
}

/** The current month in Mogadishu as YYYY-MM. */
export function facilityMonth(now: Date = new Date()): string {
  return facilityToday(now).slice(0, 7);
}

/**
 * A date-only value (YYYY-MM-DD) as text, on the SAME calendar day it was recorded. `new Date('2026-10-31')` is midnight UTC, so formatting it in a
 * browser behind UTC would show 30 October; this reads the date as a calendar day and formats it without any zone shift. Anything that is not a
 * plain date is returned unchanged. (The shared formatDate is deliberately left as it is; this is used on the Deep Cleaning screens only.)
 */
export function formatCalendarDate(value: string | null | undefined): string {
  if (!value) return '—';
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return value;
  if (getActiveLanguage() === 'so') return `${Number(match[3])} ${SOMALI_MONTHS_SHORT[Number(match[2]) - 1]} ${match[1]}`;

  return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))));
}

/**
 * The date an inspection form opens on: the saved inspection's own date when it is being edited, otherwise TODAY IN MOGADISHU (the same calendar
 * the server validates against), never the browser's own local date.
 */
export function defaultInspectionDate(saved?: string | null, now: Date = new Date()): string {
  return saved ?? facilityToday(now);
}
