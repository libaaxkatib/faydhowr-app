// Messages of HR Release 1B (penalties and one-month salary advances): voiding, correcting and waiving a record, the amounts already deducted and still owed,
// the identical-record confirmation, the Outstanding balances list, the "penalties or advances changed" notice before Review / Finalize, and the note on a
// payroll line that carried a deduction forward. Every message is `proposed` (written for this implementation; the Somali wording is for the owner to
// review). None of the 161 owner-approved messages is touched or reworded here; the owner-approved "Void" (`action.void`) is reused as it is.

import { defineMessages, message } from '../catalog.ts';

export const payrollRelease1b = defineMessages({
  // ---------------------------------------------------------------- navigation
  'nav.outstandingBalances': message('Outstanding Balances', 'Haraagyada Lagu Leeyahay'),

  // ---------------------------------------------------------------- a record on the employee's penalty / advance card
  'status.deduction.voided': message('Voided', 'La buriyey'),
  'option.deduction.voidedReason': message('Voided: {reason}', 'La buriyey: {reason}'),
  'option.deduction.replacesNote': message('Corrected version of record #{id}.', 'Nuqulka la saxay ee diiwaanka #{id}.'),
  'option.deduction.replacedByNote': message('Replaced by the corrected record #{id}.', 'Waxaa lagu beddelay diiwaanka la saxay #{id}.'),
  'option.deduction.deductedSoFar': message('Deducted so far', 'Ilaa hadda la jaray'),
  'option.deduction.stillOwed': message('Still owed', 'Weli lagu leeyahay'),
  'option.deduction.waivedAmount': message('Waived', 'La cafiyey'),
  'option.deduction.waivedReason': message('Waived: {reason}', 'La cafiyey: {reason}'),
  'warning.deduction.alreadyApplied': message(
    "A finalized payroll already deducted part of this. To change it, reopen that company's payroll, or ask a Super Admin to waive what is still owed.",
    'Mushahar la dhammaystiray ayaa hore qayb ka mid ah uga jaray. Si aad wax u beddesho, dib u fur mushaharka shirkaddaas, ama weydiiso Super Admin inuu cafiyo wixii weli lagu leeyahay.',
  ),
  'action.deduction.correct': message('Correct', 'Saxo'),
  'action.deduction.waive': message('Waive balance', 'Ka cafi haraaga'),
  'option.deduction.currencyHint': message('Amounts are in US dollars (USD).', 'Qadarku waa doollar Mareykan ah (USD).'),
  'option.deduction.reasonHint': message('At least 3 characters.', 'Ugu yaraan 3 xaraf.'),

  // ---------------------------------------------------------------- void
  'confirm.deduction.voidPenaltyTitle': message('Void this penalty', 'Ka noqo ganaaxan'),
  'confirm.deduction.voidAdvanceTitle': message('Void this advance', 'Ka noqo lacag hormaristan'),
  'confirm.deduction.voidBody': message(
    'The record stays in the history, marked Voided, and is no longer counted in payroll.',
    'Diiwaanku wuxuu ku hadhayaa taariikhda isagoo lagu calaamadeeyey La buriyey, mushaharkana lagama xisaabiyo.',
  ),
  'option.deduction.voidReason': message('Reason for voiding', 'Sababta looga noqonayo'),

  // ---------------------------------------------------------------- correct
  'confirm.deduction.correctPenaltyTitle': message('Correct this penalty', 'Saxo ganaaxan'),
  'confirm.deduction.correctAdvanceTitle': message('Correct this advance', 'Saxo lacag hormaristan'),
  'confirm.deduction.correctBody': message(
    'The old record is voided and the corrected one replaces it. Change only what is wrong.',
    'Diiwaanka hore waa la burinayaa, kan la saxayna wuu beddelayaa. Beddel waxa khaldan oo keliya.',
  ),
  'option.deduction.correctionReason': message('Reason for the correction', 'Sababta sixitaanka'),
  'warning.deduction.nothingChanged': message('Change at least one field to correct this record.', 'Beddel ugu yaraan hal goob si aad u saxdo diiwaankan.'),

  // ---------------------------------------------------------------- waive
  'confirm.deduction.waivePenaltyTitle': message('Waive the balance of this penalty', 'Ka cafi haraaga ganaaxan'),
  'confirm.deduction.waiveAdvanceTitle': message('Waive the balance of this advance', 'Ka cafi haraaga lacag hormaristan'),
  'confirm.deduction.waiveBody': message(
    'Only a Super Admin can do this. What payroll already deducted stays as it is; the amount you waive is no longer taken from future pay. Still owed: {owed}.',
    'Super Admin keliya ayaa tan samayn kara. Wixii mushaharku hore u jaray isma beddelayo; qadarka aad cafiso mushahar dambe lagama jarayo. Weli lagu leeyahay: {owed}.',
  ),
  'option.deduction.waiveAmount': message('Amount to waive (optional)', 'Qadarka la cafinayo (ikhtiyaari ah)'),
  'option.deduction.waiveAmountHint': message('Leave empty to waive everything still owed.', 'Ka daa madhan si aad u cafiso wax kasta oo weli lagu leeyahay.'),
  'option.deduction.waiveReason': message('Reason for waiving', 'Sababta cafinta'),
  'action.deduction.waiveConfirm': message('Waive', 'Cafi'),

  // ---------------------------------------------------------------- shared by the three dialogs
  'warning.deduction.regenerateNote': message(
    "If this company's payroll for that month was already generated, it must be generated again before it can be reviewed or finalized.",
    'Haddii mushaharka shirkaddan ee bishaas hore loo sameeyey, waa in mar kale la sameeyaa ka hor inta aan dib loo eegin ama la dhammaystirin.',
  ),
  'notify.deduction.voided': message('Voided. It stays in the history and is no longer counted.', 'La buriyey. Waxay ku hadhay taariikhda, mana la xisaabinayo.'),
  'notify.deduction.corrected': message('Corrected. The old record stays in the history as voided.', 'La saxay. Diiwaankii hore wuxuu ku hadhay taariikhda isagoo la buriyey.'),
  'notify.deduction.waived': message('The balance was waived. What was already deducted is unchanged.', 'Haraaga waa la cafiyey. Wixii hore loo jaray isma beddelin.'),
  'notify.deduction.voidFailed': message('Could not void this record.', 'Lama burin karin diiwaankan.'),
  'notify.deduction.correctFailed': message('Could not correct this record.', 'Lama saxi karin diiwaankan.'),
  'notify.deduction.waiveFailed': message('Could not waive the balance.', 'Lama cafin karin haraaga.'),
  'notify.deduction.recordFailed': message('Could not record this.', 'Lama diiwaangelin karin tan.'),
  'notify.deduction.employeeNotActive': message(
    'This employee is no longer Active and had no assignment in that month, so nothing can be recorded for that month. Choose a month the employee was assigned in. Nothing was changed.',
    'Shaqaalahan mar dambe ma shaqeynayo, bishaas qoondayn shaqo ma lahayn, sidaas darteed bishaas waxba lagu diiwaangelin karin. Dooro bil uu shaqaaluhu qoondayn shaqo ku lahaa. Waxba lama beddelin.',
  ),

  // ---------------------------------------------------------------- amount, date and month checks
  'validation.deduction.amountPositive': message('Enter an amount greater than 0, such as 25 or 25.50.', 'Geli qadar ka weyn 0, tusaale ahaan 25 ama 25.50.'),
  'validation.deduction.amountDecimals': message('Use at most 2 decimals, for example 25.50.', 'Isticmaal ugu badnaan 2 lambar oo jajab ah, tusaale ahaan 25.50.'),
  'validation.deduction.amountTooMuch': message('This is more than what is still owed.', 'Tani way ka badan tahay wixii weli lagu leeyahay.'),
  'validation.deduction.reasonShort': message('Write a reason of at least 3 characters.', 'Qor sabab ugu yaraan 3 xaraf ah.'),
  'validation.deduction.periodRange': message('Choose a month from 2020-01 up to next month.', 'Dooro bil laga bilaabo 2020-01 ilaa bisha soo socota.'),
  'validation.deduction.dateInvalid': message('Choose a valid date.', 'Dooro taariikh sax ah.'),
  'validation.deduction.dateFuture': message('The date cannot be in the future.', 'Taariikhdu ma noqon karto mid ka dambeysa maanta.'),

  // ---------------------------------------------------------------- an identical record already exists
  'confirm.deduction.duplicateTitle': message('An identical record already exists', 'Diiwaan isku mid ah ayaa hore u jira'),
  'confirm.deduction.duplicateBody': message('An identical record already exists. Record it again anyway?', 'Diiwaan isku mid ah ayaa hore u jira. Ma doonaysaa inaad si kastaba mar kale u diiwaangeliso?'),
  'action.deduction.recordAgain': message('Record it again', 'Mar kale diiwaangeli'),

  // ---------------------------------------------------------------- the Outstanding balances list
  'option.deduction.balancesHelp': message(
    "Amounts payroll could not deduct stay owed and are taken from the next months' pay. Nothing is forgiven automatically and no debt is created.",
    'Qadarrada mushaharku awoodi waayay inuu jaro waa lagu leeyahay weli, waxaana laga jarayaa mushaharka bilaha soo socda. Waxba si toos ah looma cafiyo, deyn cusubna lama abuurayo.',
  ),
  'option.deduction.kindPenalty': message('Penalty', 'Ganaax'),
  'option.deduction.kindAdvance': message('Salary advance', 'Lacag hormaris'),
  'option.deduction.allKinds': message('All kinds', 'Dhammaan noocyada'),
  'option.deduction.leaversOnly': message('Left the company only', 'Kuwa shirkadda ka baxay oo keliya'),
  'option.deduction.colRegisteredMonth': message('Registered month', 'Bisha la diiwaangeliyey'),
  'option.deduction.colRegistered': message('Registered', 'La diiwaangeliyey'),
  'option.deduction.colDeducted': message('Deducted', 'La jaray'),
  'option.deduction.colWaived': message('Waived', 'La cafiyey'),
  'option.deduction.colOwed': message('Still owed', 'Weli lagu leeyahay'),
  'option.deduction.colState': message('State', 'Xaalad'),
  'status.deduction.statePending': message('Not deducted yet', 'Weli lama jarin'),
  'status.deduction.statePartly': message('Partly deducted', 'Qayb ayaa la jaray'),
  'status.deduction.leaver': message('Left the company', 'Shirkadda ka baxay'),
  'option.deduction.totalsRow': message('Total: {count} record(s) · still owed {amount}', 'Wadar: {count} diiwaan · weli lagu leeyahay {amount}'),
  'option.deduction.leaverTotals': message('Left the company: {count} record(s) · still owed {amount}', 'Shirkadda ka baxay: {count} diiwaan · weli lagu leeyahay {amount}'),
  'option.deduction.emptyTitle': message('Nothing is owed', 'Waxba lagu leeyahay ma jiro'),
  'option.deduction.emptyBody': message(
    'Payroll deducted everything it could. No penalty or advance has a balance left.',
    'Mushaharku wuxuu jaray wixii uu awoodi karay oo dhan. Ganaax ama lacag hormaris haraag ma leh.',
  ),

  // ---------------------------------------------------------------- before Review / Finalize: the draft no longer matches
  'warning.deduction.inputsChangedTitle': message('Penalties or advances changed since this company was generated', 'Ganaax ama lacag hormaris ayaa isbeddelay tan iyo markii shirkaddan la sameeyey'),
  'warning.deduction.inputsChangedBlocking': message(
    'A penalty or an advance of this company was registered, corrected, voided or waived after its payroll was generated, so the lines no longer match. Review and Finalize are refused until this company is generated again.',
    'Ganaax ama lacag hormaris shirkaddan ah ayaa la diiwaangeliyey, la saxay, la buriyey ama la cafiyey kadib markii mushaharkeedii la sameeyey, sidaas darteed safafku isma waafaqaan. Dib-u-eegista iyo Dhammaystirka waa la diidayaa ilaa shirkaddan mar kale la sameeyo.',
  ),
  'warning.deduction.inputsChangedPointer': message(
    'Generate this company again from the company list ("Generate / refresh draft"), then review and finalize it.',
    'Mar kale samee shirkaddan liiska shirkadaha ("Samee / Cusboonaysii Qabyo"), kadibna dib u eeg oo dhammaystir.',
  ),
  'warning.deduction.inputsChangedGenerate': message(
    'Penalties or advances of this company changed since its last Generate. Generating now brings them into the payroll.',
    'Ganaax ama lacag hormaris shirkaddan ah ayaa isbeddelay tan iyo samayntii ugu dambeysay. Haddii hadda la sameeyo, waxay soo gelayaan mushaharka.',
  ),

  // ---------------------------------------------------------------- a payroll line that carried a deduction forward
  'status.deduction.carriedForward': message('Carried forward', 'La soo wareejiyey'),
  'status.deduction.notFullyDeducted': message('Not fully deducted this month', 'Gebi ahaanba lama jarin bishan'),
  'warning.deduction.carriedFrom': message('Carried forward from {period}.', 'Waxaa laga soo wareejiyey {period}.'),
  'warning.deduction.lineFigures': message(
    'Owed {owed} · deducted this month {deducted} · stays owed {left}',
    'Lagu leeyahay {owed} · bishan la jaray {deducted} · weli lagu leeyahay {left}',
  ),
  'warning.deduction.notDeductedNote': message(
    "The net is never below $0, so {left} could not be deducted this month. It stays owed and is taken from the next months' pay.",
    'Mushaharka saafiga ahi ma noqon karo wax ka hooseeya $0, sidaas darteed {left} bishan lama jari karin. Weli waa lagu leeyahay, waxaana laga jarayaa mushaharka bilaha soo socda.',
  ),
  'warning.deduction.previouslyDeducted': message('Already deducted in earlier months: {amount}.', 'Hore ayaa loogu jaray bilihii hore: {amount}.'),

  // ---------------------------------------------------------------- errors of the new endpoints (English: the server's own sentence; Somali: these)
  'notify.error.DUPLICATE_RECORD': message(
    'An identical record already exists. Nothing was changed.',
    'Diiwaan isku mid ah ayaa hore u jira. Waxba lama beddelin.',
  ),
  'notify.error.IDEMPOTENCY_KEY_REUSED': message(
    'This request was already used for a different record. Close the window and try again. Nothing was changed.',
    'Codsigan hore ayaa loogu isticmaalay diiwaan kale. Xir daaqadda oo mar kale isku day. Waxba lama beddelin.',
  ),
  'notify.error.DEDUCTION_CHANGE_FORBIDDEN': message(
    'Only an HR Manager or a Super Admin can void or correct a penalty or an advance. Nothing was changed.',
    'HR Manager ama Super Admin keliya ayaa ka noqon ama saxi kara ganaax ama lacag hormaris. Waxba lama beddelin.',
  ),
  'notify.error.REVERSE_APPLIED_SUPER_ADMIN_ONLY': message(
    'This was deducted in a company payroll that was finalized and then reopened, so only a Super Admin can reverse it. Nothing was changed.',
    'Tan waxaa laga jaray mushahar shirkad oo la dhammaystiray kadibna dib loo furay, sidaas darteed Super Admin keliya ayaa dib u rogi kara. Waxba lama beddelin.',
  ),
  'notify.error.RECORD_CHANGED': message(
    'This record was changed by someone else while this screen was open. Refresh the page and try again. Nothing was changed.',
    'Diiwaankan qof kale ayaa beddelay intii shaashaddan furnayd. Cusboonaysii bogga oo mar kale isku day. Waxba lama beddelin.',
  ),
  'notify.error.RECORD_NOT_ACTIVE': message(
    'This record is already voided; nothing more can be done with it. Nothing was changed.',
    'Diiwaankan hore ayaa la buriyey; wax dheeraad ah lagu samayn karo ma jiro. Waxba lama beddelin.',
  ),
  'notify.error.RECORD_NOT_FOUND': message(
    'This record no longer exists for this employee. Refresh the page. Nothing was changed.',
    'Diiwaankan shaqaalahan mar dambe ma jiro. Cusboonaysii bogga. Waxba lama beddelin.',
  ),
  'notify.error.DEDUCTION_ALREADY_APPLIED': message(
    "A finalized company payroll already deducted part of this, so it cannot be changed in place. Reopen that company's payroll, or ask a Super Admin to waive what is still owed. Nothing was changed.",
    'Mushahar shirkad oo la dhammaystiray ayaa hore qayb ka mid ah uga jaray, sidaas darteed si toos ah looma beddeli karo. Dib u fur mushaharka shirkaddaas, ama weydiiso Super Admin inuu cafiyo wixii weli lagu leeyahay. Waxba lama beddelin.',
  ),
  'notify.error.WAIVE_SUPER_ADMIN_ONLY': message(
    'Only a Super Admin can waive what is still owed. Nothing was changed.',
    'Super Admin keliya ayaa cafin kara wixii weli lagu leeyahay. Waxba lama beddelin.',
  ),
  'notify.error.WAIVE_NOT_APPLICABLE': message(
    'There is no balance to waive: either nothing was deducted yet (void the record instead) or it was deducted in full. Nothing was changed.',
    'Haraag la cafiyo ma jiro: ama weli waxba lama jarin (diiwaanka ka noqo), ama gebi ahaanba waa la jaray. Waxba lama beddelin.',
  ),
  'notify.error.WAIVE_AMOUNT_INVALID': message(
    'The amount to waive must be between 0.01 and what is still owed. Nothing was changed.',
    'Qadarka la cafinayo waa inuu u dhexeeyaa 0.01 iyo wixii weli lagu leeyahay. Waxba lama beddelin.',
  ),
  // Outstanding balances are paged on the server; if the server's safety ceiling ever cuts the scan short it says so.
  'warning.deduction.truncated': message(
    'There are too many records to examine at once, so some are not listed here. Choose a company or a kind to narrow the list and see the rest.',
    'Diiwaannadu aad bay u badan yihiin in mar keliya la baaro, sidaas darteed qaar halkan lagama soo bandhigin. Dooro shirkad ama nooc si aad liiska u yaraysid oo aad inta kale u aragto.',
  ),
  // Owner decision O-2 (label only): the dashboard's two different months.
  'option.hr.calendarMonthLabel': message('Calendar month: {month}', 'Bisha kalandarka: {month}'),
  'option.hr.payrollMonthLabel': message('Payroll month: {month}', 'Bisha mushaharka: {month}'),
  'notify.error.PAYROLL_INPUTS_CHANGED': message(
    'A penalty or an advance of this company changed after its payroll was generated, so the lines no longer match. Generate this company again, then review and finalize it. Nothing was changed.',
    'Ganaax ama lacag hormaris shirkaddan ah ayaa isbeddelay kadib markii mushaharkeedii la sameeyey, sidaas darteed safafku isma waafaqaan. Mar kale samee shirkaddan, kadibna dib u eeg oo dhammaystir. Waxba lama beddelin.',
  ),
});
