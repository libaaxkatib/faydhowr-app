// Messages of the Temporary Replacement corrections (2026-10-29): Edit, End Coverage with the planned end date and the extension warning, "Expired - Needs
// Closing", Close, and the history of changes. Every message is `proposed` (written for this implementation); Super Admin can reword any of them in Translations.

import { defineMessages, message } from '../catalog.ts';

export const replacementFixes = defineMessages({
  // ---------------------------------------------------------------- status and card
  'status.pipeline.replacement.expired': message('Expired — Needs Closing', 'Waqtigii wuu dhamaaday — u baahan in la xidho'),
  'status.pipeline.replacement.scheduled': message('Not started yet', 'Weli ma bilaaban'),
  'option.pipeline.plannedEndWas': message('Planned end: {date}', 'Dhammaadka la qorsheeyay: {date}'),
  'option.pipeline.recordedBy': message('Recorded by {name}', 'Waxaa diiwaangeliyey {name}'),
  'warning.pipeline.expiredNotice': message(
    'The end date passed {days} day(s) ago. Days are no longer counted after it, but the replacement is still open. Close it to finish it.',
    'Taariikhda dhammaadku waxay dhaaftay {days} maalin kahor. Maalmaha ka dambeeya lama tirinayo, laakiin beddelaaddu weli waa furan tahay. Xidh si aad u dhammayso.',
  ),
  'option.hr.kpiExpiredReplacements': message('Expired — Needs Closing', 'Waqtigii wuu dhamaaday — u baahan in la xidho'),

  // ---------------------------------------------------------------- End Coverage
  'option.pipeline.plannedEnd': message('Planned end date', 'Taariikhda dhammaadka la qorsheeyay'),
  'option.pipeline.noPlannedEnd': message('No end date set', 'Taariikh dhammaad lama dhigin'),
  'option.pipeline.todayIs': message('Today', 'Maanta'),
  'option.pipeline.lastDayOfCover': message('Last day of the cover', 'Maalinta ugu dambeysay ee beddelaadda'),
  'warning.pipeline.lastDayHint': message(
    'The last day the Temporary employee really worked. The start day and this day are both counted.',
    'Maalinta ugu dambeysay ee shaqaalaha ku-meel-gaarka ah runtii shaqeeyay. Maalinta bilowga iyo maalintan labaduba waa la tirinayaa.',
  ),
  'warning.pipeline.lastDayNotToday': message(
    'Today is {today}. You are recording {day} as the last day.',
    'Maanta waa {today}. Waxaad u diiwaangelinaysaa {day} maalinta ugu dambeysay.',
  ),
  'warning.pipeline.endSameAsPlan': message(
    'This is the planned end date. No day is added or removed.',
    'Tani waa taariikhda dhammaadka la qorsheeyay. Maalin lama daro, lama jarayo.',
  ),
  'warning.pipeline.endEarlier': message(
    'This ends the cover {days} day(s) before its planned end ({planned}). Those days will no longer count.',
    'Tani waxay beddelaadda soo afjartaa {days} maalin ka hor dhammaadkeedii la qorsheeyay ({planned}). Maalmahaas lama tirinayo.',
  ),
  'warning.pipeline.endFirst': message(
    'This cover has no end date yet. It will end on {day}.',
    'Beddelaaddan weli taariikh dhammaad ma laha. Waxay dhammaanaysaa {day}.',
  ),
  'confirm.pipeline.extensionTitle': message('This lengthens the cover', 'Tani waxay dheerayn doontaa beddelaadda'),
  'warning.pipeline.extensionBody': message(
    'The cover now ends on {oldEnd}. You chose {newEnd}: {days} more day(s) will be counted and the cost goes up.',
    'Beddelaaddu hadda waxay dhammaanaysaa {oldEnd}. Waxaad dooratay {newEnd}: {days} maalin oo dheeraad ah ayaa la tirin doonaa, kharashkuna wuu kordhayaa.',
  ),
  'confirm.pipeline.confirmExtension': message(
    'I understand this lengthens the cover and increases the cost.',
    'Waan fahmay in tani dheerayso beddelaadda oo kordhinayso kharashka.',
  ),
  'action.pipeline.endCoverageNow': message('End coverage on this day', 'Soo afjar beddelaadda maalintan'),
  'notify.pipeline.coverageEndedOn': message('Coverage ended on {day}.', 'Beddelaaddu way dhammaatay {day}.'),

  // ---------------------------------------------------------------- the impact of a change (shared by End Coverage and Edit)
  'option.pipeline.oldEnd': message('Old end date', 'Taariikhdii hore ee dhammaadka'),
  'option.pipeline.newEnd': message('New end date', 'Taariikhda cusub ee dhammaadka'),
  'option.pipeline.addedDays': message('Additional days', 'Maalmo dheeraad ah'),
  'option.pipeline.removedDays': message('Days removed', 'Maalmo laga jaray'),
  'option.pipeline.daysCounted': message('Days counted: {before} → {after}', 'Maalmaha la tiriyay: {before} → {after}'),
  'option.pipeline.costBefore': message('Cost before', 'Kharashka hore'),
  'option.pipeline.costAfter': message('Cost after', 'Kharashka cusub'),
  'option.pipeline.costChange': message('Change', 'Isbeddel'),
  'option.pipeline.costByMonth': message('Cost by month', 'Kharashka bil kasta'),
  'option.pipeline.monthColumn': message('Month', 'Bisha'),
  'option.pipeline.daysColumn': message('Days', 'Maalmo'),
  'option.pipeline.monthClosed': message('closed payroll', 'mushahar xiran'),
  'warning.pipeline.lockedMonths': message(
    'This reaches {months}, which is approved or finalized payroll. It is a correction of closed payroll.',
    'Tani waxay gaadhaysaa {months}, oo ah mushahar la ansixiyay ama la xiray. Waa sax-bixin mushahar xiran.',
  ),
  'warning.pipeline.superAdminOnly': message(
    'Only a Super Admin can make this change. Nothing has been changed.',
    'Super Admin keliya ayaa samayn kara isbeddelkan. Waxba lama beddelin.',
  ),
  'option.pipeline.closedPayrollReason': message('Reason for correcting closed payroll', 'Sababta sax-bixinta mushaharka xiran'),
  'validation.pipeline.closedPayrollReasonHint': message(
    'Required, at least 10 characters. It is saved with the correction.',
    'Waa loo baahan yahay, ugu yaraan 10 xaraf. Waxaa lala keydinayaa sax-bixinta.',
  ),
  'warning.pipeline.approvalWillBeCleared': message(
    'This adds days. The approval will be removed and the cost must be approved again.',
    'Tani waxay ku darayaa maalmo. Ansixintu waa baxaysaa, kharashkuna waa in mar kale la ansixiyaa.',
  ),
  'warning.pipeline.previewLoading': message('Checking the change…', 'Isbeddelka waa la hubinayaa…'),

  // ---------------------------------------------------------------- Edit
  'confirm.pipeline.editTitle': message('Correct this replacement', 'Sax beddelaaddan'),
  'warning.pipeline.editNotice': message(
    'You can correct the dates, the Temporary employee, the reason and the notes. The covered employee, the assignment and the payments are never changed here. Every correction is saved with who made it, when and why.',
    'Waxaad saxi kartaa taariikhaha, shaqaalaha ku-meel-gaarka ah, sababta iyo qoraallada. Shaqaalaha la bedelay, qoondaynta iyo lacag-bixinnada halkan lama beddelo. Sax kasta waxaa lala keydiyaa qofka sameeyay, goorta iyo sababta.',
  ),
  'option.pipeline.plannedEndOptional': message('Planned end date (optional)', 'Taariikhda dhammaadka la qorsheeyay (ikhtiyaari)'),
  'option.pipeline.whyCorrecting': message('Why are you correcting this?', 'Maxaad u saxaysaa tan?'),
  'validation.pipeline.minTenCharacters': message('At least 10 characters.', 'Ugu yaraan 10 xaraf.'),
  'warning.pipeline.employeeLocked': message(
    'The Temporary employee cannot be changed because payments or workdays are recorded for this replacement.',
    'Shaqaalaha ku-meel-gaarka ah lama beddeli karo sababtoo ah lacag-bixinno ama maalmo shaqo ayaa loo diiwaangeliyay beddelaaddan.',
  ),
  'action.pipeline.saveCorrection': message('Save correction', 'Keydi sax-bixinta'),
  'notify.pipeline.replacementCorrected': message('Replacement corrected.', 'Beddelaadda waa la saxay.'),
  'notify.pipeline.couldNotCorrect': message('Could not correct the replacement. Nothing was changed.', 'Beddelaadda lama saxi karin. Waxba lama beddelin.'),

  // ---------------------------------------------------------------- Close (expired)
  'action.pipeline.closeExpired': message('Close (keep dates)', 'Xidh (taariikhaha ha la beddelin)'),
  'confirm.pipeline.closeTitle': message('Close this expired replacement?', 'Ma xidhaysaa beddelaadan waqtigeedu dhamaaday?'),
  'confirm.pipeline.closeBody': message(
    'The end date ({end}) has passed. Closing only marks the replacement as ended. The dates, the days counted, the cost and the approval stay exactly as they are.',
    'Taariikhda dhammaadka ({end}) way dhaaftay. Xidhistu waxay kaliya u calaamadaysaa beddelaadda inay dhammaatay. Taariikhaha, maalmaha la tiriyay, kharashka iyo ansixintu sidoodii bay u hadhayaan.',
  ),
  'action.pipeline.closeReplacementConfirm': message('Close replacement', 'Xidh beddelaadda'),
  'notify.pipeline.replacementClosed': message('Replacement closed. Nothing else was changed.', 'Beddelaadda waa la xidhay. Wax kale lama beddelin.'),
  'notify.pipeline.couldNotClose': message('Could not close the replacement.', 'Beddelaadda lama xidhi karin.'),

  // ---------------------------------------------------------------- History
  'action.pipeline.history': message('History', 'Taariikhda isbeddelada'),
  'confirm.pipeline.historyTitle': message('History — {name}', 'Taariikhda — {name}'),
  'option.pipeline.historyEmpty': message('No changes recorded yet.', 'Isbeddel weli lama diiwaangelin.'),
  'option.pipeline.historyBy': message('by {name}', 'waxaa sameeyey {name}'),
  'option.pipeline.historyReason': message('Reason: {reason}', 'Sabab: {reason}'),
  'option.pipeline.historyApprovalRemoved': message('The approval was removed; the cost must be approved again.', 'Ansixintii waa la tirtiray; kharashka waa in mar kale la ansixiyaa.'),
  'option.pipeline.historyEvent.created': message('Recorded', 'La diiwaangeliyey'),
  'option.pipeline.historyEvent.corrected': message('Corrected', 'La saxay'),
  'option.pipeline.historyEvent.ended': message('Coverage ended', 'Beddelaadda waa la soo afjaray'),
  'option.pipeline.historyEvent.closed': message('Closed after the end date', 'La xidhay kadib taariikhda dhammaadka'),
  'option.pipeline.historyEvent.approved': message('Cost approved', 'Kharashka waa la ansixiyay'),
  'option.pipeline.historyEvent.other': message('Updated', 'La cusboonaysiiyay'),
});
