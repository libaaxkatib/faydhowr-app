// Messages of the HR compliance and accountability report (2026-11-02). All are `proposed` (written for this feature, not reviewed by a native speaker); Super
// Admin can reword any of them in Translations. None of the 161 owner-approved messages is touched. The words for Permanent, Temporary, Replacement, employee,
// company, payroll and status are the ones the approved messages already use. The requirement, explanation and corrective-action sentences of each finding come
// from the server in English.

import { defineMessages, message } from '../catalog.ts';

export const compliance = defineMessages({
  'nav.complianceReport': message('HR Compliance & Accountability', 'Hoggaansanaanta iyo Mas\'uuliyadda HR'),

  'option.compliance.title': message('HR Compliance & Accountability', 'Hoggaansanaanta iyo Mas\'uuliyadda HR'),
  'warning.compliance.intro': message(
    'People who work, or are assigned to work, while a requirement the system already defines is not met. Use it to ask the responsible manager why work started first. Nothing here changes an employee, a status, a salary or payroll.',
    'Dadka shaqeynaya, ama loo qoondeeyey shaqo, iyadoo shuruud nidaamku hore u qeexay aan la buuxin. Waxaad u isticmaali kartaa inaad weydiiso maamulaha masuulka ah sababta shaqada loo bilaabay. Halkan waxba ma beddelo shaqaale, xaalad, mushahar ama mushaharka guud.',
  ),
  'warning.compliance.forbidden': message('This report is reserved for the HR Manager and Super Admin.', 'Warbixintan waxay u gaar tahay Maamulaha HR iyo Super Admin.'),
  'warning.compliance.noFindings': message('No finding matches these filters.', 'Wax natiijo ah oo isku habboon shaxdan ma jiro.'),
  'warning.compliance.responsibleNotRecorded': message('Responsible manager not recorded', 'Maamulaha masuulka ah lama diiwaangelin'),
  'warning.compliance.needsReviewNote': message('Needs HR review: the requirement is not defined as mandatory or cannot be verified, so this is not a confirmed violation.', 'Wuxuu u baahan yahay dib-u-eegis HR: shuruudda mid khasab ah looma qeexin ama lama xaqiijin karo, sidaas darteed ma aha xad-gudub la xaqiijiyey.'),
  'warning.compliance.systemCleared': message('Cleared by the system: the condition is no longer found.', 'Nidaamku wuu nadiifiyey: xaaladdii lama helin mar dambe.'),

  'option.compliance.summaryReviewed': message('Active employees reviewed', 'Shaqaalaha shaqeynaya ee la eegay'),
  'option.compliance.summaryProfiles': message('Incomplete profiles', 'Faylal aan dhammaystirnayn'),
  'option.compliance.summaryRequirements': message('Unmet job requirements', 'Shuruudo shaqo oo aan la buuxin'),
  'option.compliance.summaryWorkflows': message('Incomplete HR workflows', 'Hab-socodyo HR oo aan dhammaystirnayn'),
  'option.compliance.summaryReview': message('Needing HR review', 'U baahan dib-u-eegis HR'),
  'option.compliance.summaryOpen': message('Open findings', 'Natiijooyin furan'),
  'option.compliance.summaryProgress': message('In progress', 'Socda'),
  'option.compliance.summaryResolved': message('Resolved findings', 'Natiijooyin la xaliyey'),

  'option.compliance.filterSearch': message('Employee name or number', 'Magaca ama lambarka shaqaalaha'),
  'option.compliance.allCompanies': message('All companies / workplaces', 'Dhammaan shirkadaha / goobaha'),
  'option.compliance.allResponsible': message('Any responsible manager', 'Maamule masuul kasta'),
  'option.compliance.responsibleNone': message('Not recorded', 'Lama diiwaangelin'),
  'option.compliance.allTypes': message('All employment types', 'Dhammaan noocyada shaqaalaynta'),
  'option.compliance.allCategories': message('All issue categories', 'Dhammaan qaybaha dhibaatada'),
  'option.compliance.allSeverities': message('All severities', 'Dhammaan darajooyinka'),
  'option.compliance.allStatuses': message('All statuses', 'Dhammaan xaaladaha'),
  'option.compliance.allConfidence': message('Confirmed and needs review', 'La xaqiijiyey iyo u baahan dib-u-eegis'),

  'option.compliance.category.profile': message('Incomplete profile', 'Fayl aan dhammaystirnayn'),
  'option.compliance.category.job_requirements': message('Unmet job requirements', 'Shuruudo shaqo oo aan la buuxin'),
  'option.compliance.category.workflow': message('Incorrect HR workflow', 'Hab-socod HR oo qaldan'),
  'option.compliance.severity.high': message('High', 'Sare'),
  'option.compliance.severity.medium': message('Medium', 'Dhexe'),
  'option.compliance.severity.low': message('Low', 'Hoose'),
  'option.compliance.confidence.confirmed': message('Confirmed', 'La xaqiijiyey'),
  'option.compliance.confidence.needs_review': message('Needs HR review', 'U baahan dib-u-eegis HR'),
  'option.compliance.status.open': message('Open', 'Furan'),
  'option.compliance.status.in_progress': message('In progress', 'Socda'),
  'option.compliance.status.resolved': message('Resolved', 'La xaliyey'),

  'option.compliance.colEmployee': message('Employee', 'Shaqaale'),
  'option.compliance.colAssignment': message('Assignment', 'Qoondayn'),
  'option.compliance.colIssue': message('Issue', 'Dhibaato'),
  'option.compliance.colAccountability': message('Responsible and dates', 'Masuulka iyo taariikhaha'),
  'option.compliance.colAction': message('Corrective action', 'Tallaabo hagaajin'),
  'option.compliance.colStatus': message('Resolution', 'Xal'),
  'option.compliance.requirement': message('Missing requirement', 'Shuruudda maqan'),
  'option.compliance.whyFlagged': message('Why it was flagged', 'Sababta loo calaamadeeyey'),
  'option.compliance.responsible': message('Responsible', 'Masuul'),
  'option.compliance.appointedOn': message('Appointed / started', 'La magacaabay / bilaabay'),
  'option.compliance.detectedOn': message('Detected', 'La ogaaday'),
  'option.compliance.shift': message('Shift', 'Wareeg'),
  'option.compliance.position': message('Position', 'Jagada'),
  'option.compliance.employmentStatus': message('Employment status', 'Xaalada shaqaalaynta'),
  'option.compliance.assignmentStatus': message('Assignment status', 'Xaalada qoondaynta'),
  'option.compliance.note': message('Follow-up note', 'Qoraal raacitaan'),
  'option.compliance.history': message('History', 'Taariikh'),
  'option.compliance.noHistory': message('No follow-up has been recorded yet.', 'Weli raacitaan lama diiwaangelin.'),

  'action.compliance.followUp': message('Follow up', 'Raac'),
  'action.compliance.save': message('Save follow-up', 'Keydi raacitaanka'),
  'action.compliance.clear': message('Clear filters', 'Nadiifi shaandhooyinka'),
  'option.compliance.dialogTitle': message('Follow-up of this finding', 'Raacitaanka natiijadan'),
  'option.compliance.statusField': message('Resolution status', 'Xaaladda xalka'),
  'option.compliance.noteField': message('Note or corrective action taken (required to mark Resolved)', 'Qoraal ama tallaabada la qaaday (loo baahan yahay si loo calaamadeeyo La xaliyey)'),
  'option.compliance.noChangesToEmployee': message('Saving this follow-up changes nothing about the employee, the assignment or payroll.', 'Keydinta raacitaankan waxba ma beddesho shaqaalaha, qoondaynta ama mushaharka.'),

  'notify.compliance.saved': message('Follow-up saved and recorded in the audit log.', 'Raacitaanka waa la keydiyey waxaana lagu diiwaangeliyey diiwaanka hubinta.'),
  'notify.compliance.saveFailed': message('The follow-up could not be saved.', 'Raacitaanka lama keydin karin.'),
  'validation.compliance.noteRequired': message('Write a short note about the corrective action before marking the finding Resolved.', 'Qor qoraal gaaban oo ku saabsan tallaabada hagaajinta ka hor intaadan natiijada ku calaamadin La xaliyey.'),
});
