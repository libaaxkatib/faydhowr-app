// Messages of HR Release 1C (the source-data freshness guard): the notice before Review / Finalize and inside the Generate dialog when the assignments, salaries,
// Temporary covers or absences of a company changed after its payroll was generated, the list of those changes, and the plain sentence for the refusal
// (409 PAYROLL_SOURCES_CHANGED). Every message is `proposed` (written for this implementation; the Somali wording is for the owner to review). None of the
// 161 owner-approved messages is touched or reworded here: the owner-approved Generate label (`action.generateDraft`), "Permanent" / "Replacement"
// (`option.assignmentType.*`), "Monthly" / "Weekly" / "Daily" (`option.frequency.*`) and "Active" (`status.active`) are reused as they are.

import { defineMessages, message } from '../catalog.ts';

export const payrollRelease1c = defineMessages({
  // ---------------------------------------------------------------- the notice (before Review / Finalize) and its gentler form (Generate dialog)
  'warning.sources.title': message(
    'The assignments, salaries or Temporary covers of this company changed since it was generated',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay tan iyo markii la sameeyey',
  ),
  'warning.sources.blocking': message(
    'Review and Finalize are refused until this company is generated again; nothing was changed.',
    'Dib-u-eegista iyo Dhammaystirka waa la diidayaa ilaa shirkaddan mar kale la sameeyo; waxba lama beddelin.',
  ),
  'warning.sources.pointer': message(
    'Generate this company again from the company list ("{action}"), then review and finalize it.',
    'Mar kale samee shirkaddan liiska shirkadaha ("{action}"), kadibna dib u eeg oo dhammaystir.',
  ),
  'warning.sources.generate': message(
    'The assignments, salaries or Temporary covers of this company changed since its last Generate. Generating now brings these changes into the payroll.',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay tan iyo samayntii ugu dambeysay. Haddii hadda la sameeyo, isbeddeladan waxay soo gelayaan mushaharka.',
  ),
  'warning.sources.listTitle': message('What changed', 'Waxa isbeddelay'),
  'warning.sources.showingFirst': message('Showing the first {shown} of {total} changes.', 'Waxaa la muujinayaa {shown} ugu horreeya ee {total} isbeddel ah.'),

  // ---------------------------------------------------------------- one change: what kind, which employee / assignment
  'warning.sources.assignment.added': message('Assignment added: employee {employee}, assignment #{id}', 'Qoondayn lagu daray: shaqaale {employee}, qoondayn #{id}'),
  'warning.sources.assignment.removed': message(
    'Assignment no longer counts for this company: employee {employee}, assignment #{id}',
    'Qoondayn aan mar dambe shirkaddan ka tirsanayn: shaqaale {employee}, qoondayn #{id}',
  ),
  'warning.sources.assignment.changed': message('Assignment changed: employee {employee}, assignment #{id}', 'Qoondayn la beddelay: shaqaale {employee}, qoondayn #{id}'),
  'warning.sources.salary.added': message('Salary record added: employee {employee}, effective {date}', 'Diiwaan mushahar oo lagu daray: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.salary.removed': message('Salary record removed: employee {employee}, effective {date}', 'Diiwaan mushahar oo laga saaray: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.salary.changed': message('Salary record changed: employee {employee}, effective {date}', 'Diiwaan mushahar oo la beddelay: shaqaale {employee}, ka bilaabma {date}'),
  'warning.sources.replacement.added': message(
    'Temporary Replacement added: #{id}, {worker} covers employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo lagu daray: #{id}, {worker} ayaa beddelaya shaqaale {employee}',
  ),
  'warning.sources.replacement.removed': message(
    'Temporary Replacement removed: #{id}, {worker} covered employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo laga saaray: #{id}, {worker} ayaa beddelayay shaqaale {employee}',
  ),
  'warning.sources.replacement.changed': message(
    'Temporary Replacement changed: #{id}, {worker} covers employee {employee}',
    'Beddelaad Ku-meel-gaar ah oo la beddelay: #{id}, {worker} ayaa beddelaya shaqaale {employee}',
  ),
  'warning.sources.absence.added': message('Absence added: employee {employee}, assignment #{assignment}', 'Maqnaansho lagu daray: shaqaale {employee}, qoondayn #{assignment}'),
  'warning.sources.absence.removed': message('Absence removed: employee {employee}, assignment #{assignment}', 'Maqnaansho laga saaray: shaqaale {employee}, qoondayn #{assignment}'),
  'warning.sources.absence.changed': message('Absence changed: employee {employee}, assignment #{assignment}', 'Maqnaansho la beddelay: shaqaale {employee}, qoondayn #{assignment}'),

  // ---------------------------------------------------------------- the fields of a change
  'option.sources.field.type': message('Type', 'Nooca'),
  'option.sources.field.status': message('Status', 'Xaaladda'),
  'option.sources.field.start': message('Start date', 'Taariikhda bilowga'),
  'option.sources.field.end': message('End date', 'Taariikhda dhammaadka'),
  'option.sources.field.replaces': message('Replaces assignment', 'Waxay beddelaysaa qoondayn'),
  'option.sources.field.amount': message('Amount', 'Qadarka'),
  'option.sources.field.currency': message('Currency', 'Lacagta'),
  'option.sources.field.frequency': message('Pay frequency', 'Inta jeer ee lagu bixiyo'),
  'option.sources.field.by': message('Covering employee', 'Shaqaalaha beddelaya'),
  'option.sources.field.plannedEnd': message('Planned end date', 'Taariikhda dhammaadka ee la qorsheeyey'),
  'option.sources.field.approved': message('Cost approved', 'Kharashka waa la ansixiyey'),
  'option.sources.field.lastWorkingDay': message('Last working day', 'Maalinta ugu dambeysay ee shaqada'),
  'option.sources.field.absentFrom': message('Absent from', 'Maqan laga bilaabo'),
  'option.sources.field.returnedOn': message('Returned on', 'Dib ugu soo laabtay'),
  'option.sources.field.terminatedOn': message('Employment ended on', 'Shaqada laga joojiyey'),

  // ---------------------------------------------------------------- values that are words of the server
  'option.sources.value.ended': message('Ended', 'Dhammaaday'),
  'option.sources.value.cancelled': message('Cancelled', 'La joojiyey'),

  // ---------------------------------------------------------------- the refusal
  'notify.error.PAYROLL_SOURCES_CHANGED': message(
    'The assignments, salaries or Temporary covers of this company changed after its payroll was generated, so the lines no longer match. Generate this company again, then review and finalize it. Nothing was changed.',
    'Qoondaynaha, mushaharyada ama Beddelaadyada Ku-meel-gaarka ah ee shirkaddan ayaa isbeddelay kadib markii mushaharkeedii la sameeyey, sidaas darteed safafku isma waafaqaan. Mar kale samee shirkaddan, kadibna dib u eeg oo dhammaystir. Waxba lama beddelin.',
  ),
});
