// Messages for the HR screens and rules that were added or reworded after the first Somali layer (the replacement choice, Deep Cleaning, the
// coverage-period payment wording, the monthly payment list download, the employment-type and cover-conflict warnings).
// Every message here is `proposed` unless it is `approved(...)` with an owner id; none of the 161 locked messages lives in this file.

import { defineMessages, message } from '../catalog.ts';

export const hrDelta = defineMessages({
  // ---- Stop assignment: the replacement row of the summary
  'confirm.company.stopSummaryReplacement': message('Replacement', 'Beddelka'),
  'confirm.company.stopSummaryTemporaryTakes': message(
    'A Temporary employee who covered the position (shown as Permanent at once; Permanent assignment and the same salary from {date})',
    'Shaqaale ku-meel-gaar ah oo jagada buuxiyey (isla markiiba waxaa loo muujinayaa Joogto; qoondayn Joogto ah iyo isla mushaharka laga bilaabo {date})',
  ),
  'confirm.company.stopSummaryWaitingTakes': message(
    'A person from the HR Waiting List (appointed; assigned from {date})',
    'Qof ka mid ah Liiska Sugitaanka HR (la magacaabay; la qoondeeyey laga bilaabo {date})',
  ),

  // ---- Payroll corrections: settlement date
  'option.payroll.datesMogadishu': message('Dates follow the Mogadishu calendar.', 'Taariikhuhu waxay raacaan kalandarka Muqdisho.'),
  'validation.payroll.chooseDate': message('Choose the date.', 'Dooro taariikhda.'),
  'validation.payroll.dateFuture': message('The date cannot be later than today.', 'Taariikhdu ma noqon karto mid ka dambeysa maanta.'),

  // ---- Monthly payment list: choose All, one Company or one Guri
  'option.payroll.scopeAll': message('All companies and guryo', 'Dhammaan shirkadaha iyo guryaha'),
  'option.payroll.scopeCompany': message('One company', 'Hal shirkad'),
  'option.payroll.scopeGuri': message('One guri', 'Hal guri'),
  'option.payroll.fieldCompany': message('Company', 'Shirkad'),
  'option.payroll.fieldGuri': message('Guri', 'Guri'),
  'option.payroll.chooseCompany': message('Choose a company…', 'Dooro shirkad…'),
  'option.payroll.chooseGuri': message('Choose a guri…', 'Dooro guri…'),
  'option.payroll.noneAvailable': message('None available', 'Midna ma jiro'),
  'warning.payroll.noCompanyExists': message('No company exists yet, so there is nothing to choose.', 'Weli shirkad lama diiwaangelin, sidaas darteed wax la doorto ma jiro.'),
  'warning.payroll.noGuriExists': message('No guri exists yet, so there is nothing to choose.', 'Weli guri lama diiwaangelin, sidaas darteed wax la doorto ma jiro.'),
  'action.payroll.downloadCompanyListNamed': message('Download company list: {name} (Excel)', 'Soo deji liiska shirkadda: {name} (Excel)'),
  'action.payroll.downloadGuriListNamed': message('Download guri list: {name} (Excel)', 'Soo deji liiska guriga: {name} (Excel)'),

  // ---- Temporary cover: coverage-period wording (replaces the older "verified days" wording)
  'option.pipeline.daysCovered': message(
    'Days covered (first to last day, both included)',
    'Maalmaha la daboolay (maalinta ugu horreysa ilaa tan ugu dambaysa, labaduba waa ku jiraan)',
  ),
  'warning.pipeline.coverageDaysStrong': message('days the cover actually existed', 'maalmihii beddelaaddu runtii jirtay'),
  'action.recordAsEntered': message('Record as entered', 'Diiwaangeli sida la geliyey'),
  'notify.pipeline.paymentRecordedAsEntered': message(
    'Payment recorded as entered ({entered}); the calculated amount was {calculated}.',
    'Lacag-bixinta waa la diiwaangeliyey sida la geliyey ({entered}); qaddarka la xisaabiyey wuxuu ahaa {calculated}.',
  ),
  'warning.pipeline.calcSummary': message(
    'Calculated: {amount} ({days} calendar day(s); already paid {paid}).',
    'La xisaabiyey: {amount} ({days} maalmood oo kalandar ah; hore ayaa loo bixiyey {paid}).',
  ),
  'warning.pipeline.calcSummaryThrough': message(
    'Calculated: {amount} ({days} calendar day(s) up to {date}; already paid {paid}).',
    'La xisaabiyey: {amount} ({days} maalmood oo kalandar ah ilaa {date}; hore ayaa loo bixiyey {paid}).',
  ),
  'warning.pipeline.endedEarly': message(
    'Ended early: planned until {planned}, ended {actual}. Days after the end are not counted.',
    'Waa goor hore la dhammeeyey: la qorsheeyey ilaa {planned}, waxaa la dhammeeyey {actual}. Maalmaha dhammaadka ka dambeeya lama tiriyo.',
  ),
  'warning.pipeline.expectedHint': message('Prefilled with the calculated amount. You can change it.', 'Waxaa lagu buuxiyey qaddarka la xisaabiyey. Waad beddeli kartaa.'),
  'option.pipeline.whyDifferent': message('Why is it different? (optional)', 'Maxay u kala duwan tahay? (ikhtiyaari ah)'),
  'warning.pipeline.overrideWarning': message(
    'The calculated amount is {calculated} {currency}; you entered {entered} {currency} ({difference}). It will be recorded as you typed it, together with the calculated amount.',
    'Qaddarka la xisaabiyey waa {calculated} {currency}; adigu waxaad geliyey {entered} {currency} ({difference}). Waxaa la diiwaangelin doonaa sidaad u qortay, iyo qaddarka la xisaabiyey.',
  ),
  'warning.pipeline.differenceMore': message('{amount} more', '{amount} ka badan'),
  'warning.pipeline.differenceLess': message('{amount} less', '{amount} ka yar'),
  // Server warnings of the expected-payment answer, by their code (the server's own English text is shown for codes without an entry).
  'warning.expected.COST_NOT_APPROVED': message(
    'This cover has not been approved for deduction yet. The expected payment is a calculation, not an approval.',
    'Beddelaadkan weli lama ansixin si laga jaro mushaharka. Lacagta la filayo waa xisaab, ma aha ansixin.',
  ),

  // ---- Temporary cover form: overlap refusals (U12) and the employee's own assignment (U1; the opening paragraph of the latter is in ownerWarnings.ts)
  'warning.pipeline.conflictHeadingPosition': message('This position already has a Temporary cover on some of these days', 'Jagadan waxaa hore u jira beddelaad ku-meel-gaar ah maalmahan qaar ah'),
  'warning.pipeline.conflictHeadingEmployee': message('This Temporary already covers another position on some of these days', 'Shaqaalahan ku-meel-gaarka ah wuxuu hore u buuxiyaa jago kale maalmahan qaar ah'),
  'warning.pipeline.conflictHeadingOwnAssignment': message('This employee already has their own assignment at the same time', 'Shaqaalahan hore ayuu qoondayn u leeyahay isla waqtigaas'),
  'warning.pipeline.conflictNoEndDate': message('no end date yet', 'weli taariikh dhammaad ma leh'),
  'warning.pipeline.conflictOffice': message('the office', 'xafiiska'),
  'warning.pipeline.conflictRecordCover': message('cover #{id} at {where}, from {start} until {end}', 'beddelaadda #{id} ee {where}, laga bilaabo {start} ilaa {end}'),
  'warning.pipeline.conflictRecordAssignment': message('assignment #{id} at {where}, from {start} until {end}', 'qoondaynta #{id} ee {where}, laga bilaabo {start} ilaa {end}'),
  'warning.pipeline.conflictPosition': message('This position already has a Temporary cover over some of these days: {record}.', 'Jagadan waxaa hore u jira beddelaad ku-meel-gaar ah maalmahan qaar ah: {record}.'),
  'warning.pipeline.conflictPositionAdvice': message(
    'A position has one Temporary at a time. End or correct that cover first, or choose dates that do not overlap it.',
    'Jago waxay leedahay hal shaqaale ku-meel-gaar ah markiiba. Marka hore soo afjar ama saxo beddelaaddaas, ama dooro taariikho aan isku dhicin.',
  ),
  'warning.pipeline.conflictEmployee': message(
    'This person already covers another position over some of these days: {record}. One person cannot be paid for two positions at the same time.',
    'Qofkan hore ayuu u buuxiyaa jago kale maalmahan qaar ah: {record}. Hal qof lama bixin karo lacag labo jago isla waqti.',
  ),
  'warning.pipeline.conflictEarlier': message('Earlier assignment: {record}.', 'Qoondaynta hore: {record}.'),
  'warning.pipeline.shiftRuleHint': message(
    'Different shifts are allowed only when both assignments have a recorded Day/Night shift and the two shifts differ; otherwise record the shift on both assignments or correct the dates.',
    'Shift-yo kala duwan waa la oggol yahay oo keliya marka labada qoondayn ay leeyihiin shift Maalin/Habeen la diiwaangeliyey oo labada shift ay kala duwan yihiin; haddii kale shift-ka ku qor labada qoondayn ama saxdo taariikhaha.',
  ),
  'warning.pipeline.conflictNothingChanged': message(
    'Nothing is cancelled or changed for you. Fix the dates, the shifts or the earlier record first.',
    'Waxba laguma joojin ama laguu beddelin. Marka hore saxdo taariikhaha, shift-yada ama diiwaankii hore.',
  ),
});
