// Selectable options and prompts the owner approved (Batches 2C and 2D). Keys carry the option set, then the stored value where there is one;
// the stored values (`resigned`, `valid`, `finalized`, ...) never change: only the label shown for them does.

import { approved, defineMessages } from '../catalog.ts';

export const option = defineMessages({
  // Separation reason (Mark Inactive)
  'option.separation.resigned': approved('HR-OPT-001', 'Resigned', 'Iska casilay'),
  'option.separation.terminated': approved('HR-OPT-002', 'Terminated', 'La eryay'),
  'option.separation.contract_ended': approved('HR-OPT-003', 'Contract Ended', 'Heshiiskii wuu dhammaaday'),
  'option.separation.other': approved('HR-OPT-004', 'Other', 'Kale'),
  'option.rehireEligible': approved('HR-OPT-005', 'Eligible for rehire in the future', 'Mustaqbalka dib loogu shaqaalaysiin karo'),

  // Employment type, gender
  'option.employmentType.permanent': approved('HR-OPT-006', 'Permanent', 'Joogto'),
  'option.employmentType.temporary': approved('HR-OPT-007', 'Temporary', 'Ku-meel-gaar'),
  'option.gender.female': approved('HR-OPT-008', 'Female', 'Dheddig'),
  'option.gender.male': approved('HR-OPT-009', 'Male', 'Lab'),

  // Work assignment: type, shift, salary frequency
  'option.assignmentType.permanent': approved('HR-OPT-010', 'Permanent', 'Joogto'),
  'option.assignmentType.replacement': approved('HR-OPT-011', 'Replacement', 'Beddelaad'),
  'option.shift.day': approved('HR-OPT-012', 'Day shift', 'Shaqada Maalinta'),
  'option.shift.night': approved('HR-OPT-013', 'Night shift', 'Shaqada Habeenka'),
  'option.frequency.monthly': approved('HR-OPT-014', 'Monthly', 'Bille'),
  'option.frequency.weekly': approved('HR-OPT-015', 'Weekly', 'Toddobaadle'),
  'option.frequency.daily': approved('HR-OPT-016', 'Daily', 'Maalinle'),

  // Client company category
  'option.companyCategory.company': approved('HR-OPT-017', 'Company', 'Shirkad'),
  'option.companyCategory.guri': approved('HR-OPT-018', 'Guri (household)', 'Guri'),

  // Leave type
  'option.leaveType.annual': approved('HR-OPT-019', 'Annual', 'Fasax sannadle'),
  'option.leaveType.sick': approved('HR-OPT-020', 'Sick', 'Fasax jirro'),
  'option.leaveType.unpaid': approved('HR-OPT-021', 'Unpaid', 'Fasax aan mushahar lahayn'),
  'option.leaveType.other': approved('HR-OPT-022', 'Other', 'Fasax kale'),

  // Attendance
  'option.attendance.present': approved('HR-OPT-023', 'Present', 'Joogay'),
  'option.attendance.late': approved('HR-OPT-024', 'Late', 'Daahay'),
  'option.attendance.absent': approved('HR-OPT-025', 'Absent', 'Maqnaa'),

  // Excel import row filter / row status
  'option.importRow.all': approved('HR-OPT-026', 'All rows', 'Dhammaan safafka'),
  'option.importRow.valid': approved('HR-OPT-027', 'Ready', 'Diyaar'),
  'option.importRow.review': approved('HR-OPT-028', 'Needs review', 'U baahan dib-u-eegis'),
  'option.importRow.duplicate': approved('HR-OPT-029', 'Already exist', 'Hore ayay u jiraan'),
  'option.importRow.duplicateRow': approved('HR-OPT-029', 'Already exists', 'Hore ayay u jiraan', 'approved', 'The row-status badge says "Already exists"; the filter says "Already exist".'),
  'option.importRow.invalid': approved('HR-OPT-030', 'Invalid', 'Aan sax ahayn'),
  'option.importRow.imported': approved('HR-OPT-031', 'Imported', 'La soo geliyey'),
  'option.importRow.skipped': approved('HR-OPT-032', 'Skipped', 'La dhaafay'),
  'option.importRow.failed': approved('HR-OPT-033', 'Failed', 'Fashilmay'),

  // Payroll run status
  'option.payrollStatus.draft': approved('HR-OPT-034', 'Draft', 'Qabyo'),
  'option.payrollStatus.reviewed': approved('HR-OPT-035', 'Reviewed', 'Dib u eegay', 'approved', 'Owner correction of the earlier draft.'),
  'option.payrollStatus.approved': approved('HR-OPT-036', 'Approved', 'La ansixiyey'),
  'option.payrollStatus.finalized': approved('HR-OPT-037', 'Finalized', 'La dhammaystiray'),

  // Prompts and decision-form fields
  'option.chooseStage': approved('HR-OPT-038', 'Choose a stage', 'Dooro marxalad'),
  'option.chooseStatus': approved('HR-OPT-039', 'Choose a status', 'Dooro xaalad'),
  'option.selectCompany': approved('HR-OPT-040', 'Select a company', 'Dooro shirkad'),
  'option.workplaceNotSet': approved('HR-OPT-041', 'Workplace not set yet', 'Goobta shaqada weli lama dooran'),
  'option.practicalBatchOptional': approved('HR-OPT-042', 'Practical batch (optional)', 'Koox Baraatiko (ikhtiyaari ah)'),
  'option.addEligibleEmployee': approved('HR-OPT-043', 'Add eligible employee…', 'Ku dar shaqaale u qalma…'),
  'option.currentStage': approved('HR-OPT-044', 'Current stage', 'Marxaladda hadda'),
  'option.moveTo': approved('HR-OPT-045', 'Move to', 'U wareeji'),
  'option.reasonRequired': approved('HR-OPT-046', 'Reason (required)', 'Sabab (waajib)'),
  'option.changeFrom': approved('HR-OPT-047', 'Change from', 'Ka beddel'),
  'option.changeTo': approved('HR-OPT-047', 'Change to', 'U beddel'),
});
