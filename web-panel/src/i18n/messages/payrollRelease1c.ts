// Messages of HR Release 1C (the source-data freshness guard): the notice before Review / Finalize and inside the Generate dialog when the assignments, salaries,
// Temporary covers or absences of a company changed after its payroll was generated, the list of those changes, and the plain sentence for the refusal
// (409 PAYROLL_SOURCES_CHANGED). Every message is `proposed` (written for this implementation; the Somali wording is for the owner to review). None of the
// 161 owner-approved messages is touched or reworded here: the owner-approved Generate label (`action.generateDraft`), "Permanent" / "Replacement"
// (`option.assignmentType.*`), "Monthly" / "Weekly" / "Daily" (`option.frequency.*`) and "Active" (`status.active`) are reused as they are.

import { approved, defineMessages } from '../catalog.ts';

export const payrollRelease1c = defineMessages({
  // ---------------------------------------------------------------- the notice (before Review / Finalize) and its gentler form (Generate dialog)
  'warning.sources.title': approved(
    'OWN-R1C-051',
    'The assignments, salaries or Temporary covers of this company changed since it was generated',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay tan iyo markii la sameeyey',
  ),
  'warning.sources.blocking': approved(
    'OWN-R1C-052',
    'Review and Finalize are refused until this company is generated again; nothing was changed.',
    'Dib-u-eegista iyo Dhammaystirka waa la diidayaa ilaa shirkaddan mar kale la sameeyo; waxba lama beddelin.',
  ),
  'warning.sources.pointer': approved(
    'OWN-R1C-053',
    'Generate this company again from the company list ("{action}"), then review and finalize it.',
    'Mar kale samee shirkaddan liiska shirkadaha ("{action}"), kadibna dib u eeg oo dhammaystir.',
  ),
  'warning.sources.generate': approved(
    'OWN-R1C-054',
    'The assignments, salaries or Temporary covers of this company changed since its last Generate. Generating now brings these changes into the payroll.',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay tan iyo samayntii ugu dambeysay. Haddii hadda la sameeyo, isbeddeladan waxay soo gelayaan mushaharka.',
  ),
  'warning.sources.listTitle': approved('OWN-R1C-055', 'What changed', 'Waxa isbeddelay'),
  'warning.sources.showingFirst': approved('OWN-R1C-056', 'Showing the first {shown} of {total} changes.', 'Waxaa la muujinayaa {shown} ugu horreeya ee {total} isbeddel ah.'),

  // ---------------------------------------------------------------- one change: what kind, which employee / assignment
  'warning.sources.assignment.added': approved('OWN-R1C-057', 'Assignment added: employee {employee}, assignment #{id}', 'Qoondayn lagu daray: shaqaale {employee}, qoondayn #{id}'),
  'warning.sources.assignment.removed': approved(
    'OWN-R1C-058',
    'Assignment no longer counts for this company: employee {employee}, assignment #{id}',
    'Qoondayn aan mar dambe shirkaddan ka tirsanayn: shaqaale {employee}, qoondayn #{id}',
  ),
  'warning.sources.assignment.changed': approved('OWN-R1C-059', 'Assignment changed: employee {employee}, assignment #{id}', 'Qoondayn la beddelay: shaqaale {employee}, qoondayn #{id}'),
  'warning.sources.salary.added': approved('OWN-R1C-060', 'Salary record added: employee {employee}, effective {date}', 'Diiwaan mushahar oo lagu daray: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.salary.removed': approved('OWN-R1C-061', 'Salary record removed: employee {employee}, effective {date}', 'Diiwaan mushahar oo laga saaray: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.salary.changed': approved('OWN-R1C-062', 'Salary record changed: employee {employee}, effective {date}', 'Diiwaan mushahar oo la beddelay: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.replacement.added': approved(
    'OWN-R1C-063',
    'Temporary Replacement added: #{id}, {worker} covers employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo lagu daray: #{id}, {worker} ayaa beddelaya shaqaale {employee}',
  ),
  'warning.sources.replacement.removed': approved(
    'OWN-R1C-064',
    'Temporary Replacement removed: #{id}, {worker} covered employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo laga saaray: #{id}, {worker} ayaa beddelayay shaqaale {employee}',
  ),
  'warning.sources.replacement.changed': approved(
    'OWN-R1C-065',
    'Temporary Replacement changed: #{id}, {worker} covers employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo la beddelay: #{id}, {worker} ayaa beddelaya shaqaale {employee}',
  ),
  'warning.sources.absence.added': approved('OWN-R1C-066', 'Absence added: employee {employee}, assignment #{assignment}', 'Maqnaansho lagu daray: shaqaale {employee}, qoondayn #{assignment}'),
  'warning.sources.absence.removed': approved('OWN-R1C-067', 'Absence removed: employee {employee}, assignment #{assignment}', 'Maqnaansho laga saaray: shaqaale {employee}, qoondayn #{assignment}'),
  'warning.sources.absence.changed': approved('OWN-R1C-068', 'Absence changed: employee {employee}, assignment #{assignment}', 'Maqnaansho la beddelay: shaqaale {employee}, qoondayn #{assignment}'),

  // ---------------------------------------------------------------- the fields of a change
  'option.sources.field.type': approved('OWN-R1C-069', 'Type', 'Nooca'),
  'option.sources.field.status': approved('OWN-R1C-070', 'Status', 'Xaaladda'),
  'option.sources.field.start': approved('OWN-R1C-071', 'Start date', 'Taariikhda bilowga'),
  'option.sources.field.end': approved('OWN-R1C-072', 'End date', 'Taariikhda dhammaadka'),
  'option.sources.field.replaces': approved('OWN-R1C-073', 'Replaces assignment', 'Waxay beddelaysaa qoondayn'),
  'option.sources.field.amount': approved('OWN-R1C-074', 'Amount', 'Qadarka'),
  'option.sources.field.currency': approved('OWN-R1C-075', 'Currency', 'Lacagta'),
  'option.sources.field.frequency': approved('OWN-R1C-076', 'Pay frequency', 'Inta jeer ee lagu bixiyo'),
  'option.sources.field.by': approved('OWN-R1C-077', 'Covering employee', 'Shaqaalaha beddelaya'),
  'option.sources.field.plannedEnd': approved('OWN-R1C-078', 'Planned end date', 'Taariikhda dhammaadka ee la qorsheeyey'),
  'option.sources.field.approved': approved('OWN-R1C-079', 'Cost approved', 'Kharashka waa la ansixiyey'),
  'option.sources.field.lastWorkingDay': approved('OWN-R1C-080', 'Last working day', 'Maalinta ugu dambeysay ee shaqada'),
  'option.sources.field.absentFrom': approved('OWN-R1C-081', 'Absent from', 'Maqan laga bilaabo'),
  'option.sources.field.returnedOn': approved('OWN-R1C-082', 'Returned on', 'Dib ugu soo laabtay'),
  'option.sources.field.terminatedOn': approved('OWN-R1C-083', 'Employment ended on', 'Shaqada laga joojiyey'),

  // ---------------------------------------------------------------- values that are words of the server
  'option.sources.value.ended': approved('OWN-R1C-084', 'Ended', 'Dhammaaday'),
  'option.sources.value.cancelled': approved('OWN-R1C-085', 'Cancelled', 'La joojiyey'),

  // ---------------------------------------------------------------- the refusal
  'notify.error.PAYROLL_SOURCES_CHANGED': approved(
    'OWN-R1C-086',
    'The assignments, salaries or Temporary covers of this company changed after its payroll was generated, so the lines no longer match. Generate this company again, then review and finalize it. Nothing was changed.',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay kadib markii mushaharkeedii la sameeyey, sidaas darteed safafku isma waafaqaan. Mar kale samee shirkaddan, kadibna dib u eeg oo dhammaystir. Waxba lama beddelin.',
  ),
});
