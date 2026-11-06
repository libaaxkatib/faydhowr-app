// Messages added by the UI audit remediation (package D, 2026-11-05): the compliance "Run scan" action and its read-only wording (owner decision UG-1), the
// view-only notice on Roles & Permissions, the "reopen" hint of the Data Issue edit dialog, the audit-log entity-type filter, the "show voided" switch of Facility
// Inspections and Complaints, and the message shown when the permission list cannot be fetched. Every message is `proposed` (written for this change, not reviewed
// by a native speaker); Super Admin can reword any of them in Translations. No existing message is changed.

import { defineMessages, message } from '../catalog.ts';

export const uiAuditD = defineMessages({
  // ---------------------------------------------------------------- compliance report: explicit scan only
  'action.compliance.runScan': message('Run scan', 'Samee baaritaan'),
  'option.compliance.lastScan': message(
    'Last scan: {when} by {name} - {new} new, {reopened} reopened, {cleared} cleared by the system.',
    'Baaritaankii ugu dambeeyay: {when} oo uu sameeyay {name} - {new} cusub, {reopened} dib loo furay, {cleared} nidaamku nadiifiyey.',
  ),
  'warning.compliance.neverScanned': message(
    'No scan has been run yet, so no findings are saved. Press "Run scan" to check the records.',
    'Weli baaritaan lama samayn, sidaas darteed natiijo lama keydin. Taabo "Samee baaritaan" si loo hubiyo diiwaannada.',
  ),
  'warning.compliance.readOnlyNote': message(
    'Opening this page, searching and filtering only read what the last scan saved. The records are checked only when you press "Run scan".',
    'Furista bogga, raadinta iyo shaandhaynta waxay oo kaliya akhrinayaan wixii baaritaankii ugu dambeeyay keydiyey. Diiwaannada waa la hubiyaa marka aad taabato "Samee baaritaan" oo keliya.',
  ),
  'notify.compliance.scanDone': message(
    'Scan finished: {new} new, {reopened} reopened, {cleared} cleared by the system.',
    'Baaritaanku wuu dhamaaday: {new} cusub, {reopened} dib loo furay, {cleared} nidaamku nadiifiyey.',
  ),
  'notify.compliance.scanFailed': message('The scan could not be run. Try again.', 'Baaritaanka lama samayn karin. Mar kale isku day.'),
  'notify.compliance.scanRunning': message('A scan is already running. Wait a moment and open the report again.', 'Baaritaan ayaa hore u socda. Sug wax yar, kadibna mar kale fur warbixinta.'),

  // ---------------------------------------------------------------- roles and permissions: view only
  'warning.system.rolesViewOnly': message('View only. Only the Super Admin can change roles and permissions.', 'Eeg oo kaliya. Super Admin keliya ayaa beddeli kara doorarka iyo ogolaanshaha.'),

  // ---------------------------------------------------------------- data issues
  'warning.system.reopenIssueHint': message(
    'This issue is closed. Choosing Open or Investigating reopens it and clears who resolved it. Keep the current status to leave it closed.',
    'Dhibaatadan waa xidhan tahay. Doorashada Furan ama Baaritaan waxay dib u furaysaa oo tirtirayaa cidda xallisay. Ka dhig xaaladda hadda jirta si ay xidhan u sii ahaato.',
  ),

  // ---------------------------------------------------------------- audit log
  'option.system.allEntityTypes': message('All entity types', 'Dhammaan noocyada shayga'),

  // ---------------------------------------------------------------- facility inspections and complaints
  'option.facility.showVoided': message('Show voided', 'Tus kuwa la burburiyey'),

  // ---------------------------------------------------------------- the permission list could not be fetched
  'warning.shell.permissionsUnavailableTitle': message('Could not check your access', 'Lama hubin karo gelitaankaaga'),
  'warning.shell.permissionsUnavailableText': message(
    'The server did not answer, so we could not check your sign-in or what you may open. You are still signed in. Check your connection and try again.',
    'Server-ku ma jawaabin, sidaas darteed lama hubin karo galitaankaaga ama waxa aad furi karto. Weli waad gashan tahay. Hubi xidhiidhkaaga, kadibna mar kale isku day.',
  ),
});
