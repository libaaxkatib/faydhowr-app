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
    'Another person chosen by HR (appointed; assigned from {date})',
    'Qof kale oo HR doortay (la magacaabay; la qoondeeyey laga bilaabo {date})',
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

  // ---- Monthly payment list: the read-only check shown before the Excel is downloaded for one company or guri
  'confirm.payroll.listPreviewTitle': message('Check the payment list before downloading', 'Hubi liiska lacag-bixinta ka hor inta aanad soo dejin'),
  'option.payroll.previewMonth': message('Payroll month', 'Bisha mushaharka'),
  'option.payroll.previewRows': message('Payment-list rows', 'Safafka liiska lacag-bixinta'),
  'option.payroll.previewPermanentTotal': message('Permanent total', 'Wadarta Joogtada'),
  'option.payroll.previewTemporaryTotal': message('Temporary / Replacement total', 'Wadarta Ku-meel-gaarka / Beddelaadka'),
  'option.payroll.previewCombinedTotal': message('Combined total', 'Wadarta isku-darka'),
  'option.payroll.previewMissingNumbers': message('Rows with no payment number', 'Safafka aan lahayn lambarka lacag-bixinta'),
  'option.payroll.loadingPreview': message('Loading the preview…', 'Hordhaca waa la soo raraya…'),
  'warning.payroll.previewOnly': message(
    'This is a preview. Nothing has been paid, changed or downloaded yet.',
    'Kani waa hordhac. Weli wax lacag ah lama bixin, wax lama beddelin, wax lamana soo dejin.',
  ),
  'warning.payroll.previewEstimate': message(
    'Estimate: no approved payroll run exists for this month yet.',
    'Qiyaas: weli ma jirto mushahar la ansixiyey oo bishan ah.',
  ),
  'warning.payroll.previewMissingNumbers': message(
    'In the Excel file the NUMBER cell of these rows will be blank and highlighted. Add the number to the employee\'s record before paying.',
    'Faylka Excel-ka, unugga NUMBER ee safafkan wuu madhan yahay oo waa la iftiimiyey. Ku dar lambarka diiwaanka shaqaalaha ka hor inta aan la bixin.',
  ),
  'warning.payroll.previewNoRecords': message(
    '{name} has no payment records for {month}. There is nothing to download.',
    '{name} ma laha diiwaanno lacag-bixin oo {month} ah. Wax la soo dejiyo ma jiro.',
  ),
  'notify.payroll.previewFailed': message('Could not load the preview. Nothing was downloaded.', 'Hordhaca lama soo rari karin. Waxba lama soo dejin.'),
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

  // ---- Payments by workplace: full and partial payments for each workplace of a finalized month
  'option.payroll.wpTitle': message('Payments by workplace', 'Lacag-bixinta goob-shaqo kasta'),
  'option.payroll.wpHelp': message(
    'For each workplace: its total, what has been paid and what is outstanding. Payments are recorded from the company list above (Approve Paid or Approve Partial); here you can see the history of each workplace and void a payment recorded by mistake. Recording a payment does not move any money.',
    'Wadarta goob-shaqo kasta, wixii la bixiyey iyo wixii hadhay. Lacag-bixinta waxaa laga diiwaangeliyaa liiska shirkadaha ee sare (Ansixi Lacagta La Bixiyey ama Ansixi Bixin Qayb ah); halkan waxaad ka arki kartaa taariikhda goob-shaqo kasta oo aad ka burburin kartaa lacag-bixin khalad ah. Diiwaangelinta lacag-bixinta lacag ma dhaqaajiso.',
  ),
  'option.payroll.wpWorkplace': message('Workplace', 'Goob-shaqo'),
  'option.payroll.wpTotal': message('Total payroll', 'Wadarta mushaharka'),
  'option.payroll.wpPaid': message('Total paid', 'Wadarta la bixiyey'),
  'option.payroll.wpOutstanding': message('Outstanding', 'Haraaga'),
  'option.payroll.wpAll': message('All workplaces', 'Dhammaan goobaha shaqada'),
  'option.payroll.wpNone': message('No workplace has payable lines in this month.', 'Goob-shaqo ma laha safaf la bixin karo bishan.'),
  'option.payroll.wpUnassigned': message(
    'Lines with no workplace on the assignment (pay these one by one in the table below)',
    'Safaf aan goob-shaqo ku lahayn qoondaynta (kuwan mid-mid ugu bixi shaxda hoose)',
  ),
  'option.payroll.wpHistory': message('Payment history', 'Taariikhda lacag-bixinta'),
  'option.payroll.wpNoPayments': message('No payment has been recorded for this workplace yet.', 'Goobtan shaqo lacag-bixin weli lagama diiwaangelin.'),
  'option.payroll.wpRecordedBy': message('Recorded by', 'Waxaa diiwaangeliyey'),
  'option.payroll.wpNote': message('Note', 'Qoraal'),
  'option.payroll.wpVoidedMark': message('Voided: {reason}', 'La burburiyey: {reason}'),
  'status.payroll.wpUnpaid': message('UNPAID', 'LAMA BIXIN'),
  'status.payroll.wpPartial': message('PARTIAL', 'QAYB'),
  'status.payroll.wpPaid': message('PAID', 'LA BIXIYEY'),
  'action.payroll.wpVoid': message('Void', 'Burburi'),
  'confirm.payroll.wpVoidTitle': message('Void this workplace payment', 'Burburi lacag-bixintan goobta shaqada'),
  'confirm.payroll.wpVoidBody': message(
    'The payment of {amount} dated {date} for {name} will stop counting toward what was paid. It stays in the history. Give the reason.',
    'Lacag-bixinta {amount} ee taariikhda {date} ee {name} ma ahaan doonto mid lagu xisaabiyo wixii la bixiyey. Waxay ku hartaa taariikhda. Sheeg sababta.',
  ),
  'option.payroll.wpVoidReason': message('Reason', 'Sabab'),
  'validation.payroll.wpVoidReason': message('Give a reason of at least 5 characters.', 'Sheeg sabab ugu yaraan 5 xaraf ah.'),
  'warning.payroll.wpNotFinalized': message(
    'Payments are recorded for a company once its payroll is finalized. You can see each workplace\'s total here already.',
    'Lacag-bixinta shirkad waa la diiwaangelin karaa marka mushaharkeeda la dhammaystiro. Wadarta goob-shaqo kasta halkan hore ayaad ka arki kartaa.',
  ),
  'warning.payroll.wpCompanyNotFinalized': message('Company not finalized yet', 'Shirkadda weli lama dhammaystirin'),
  'notify.payroll.wpVoided': message('Payment voided. It no longer counts; the record is kept.', 'Lacag-bixinta waa la burburiyey. Mid lagu xisaabiyo ma aha; diiwaanka waa la hayaa.'),
  'notify.payroll.wpFailed': message('The payment was not recorded. Nothing was changed.', 'Lacag-bixinta lama diiwaangelin. Waxba lama beddelin.'),
  'notify.payroll.wpLoadFailed': message('Could not load the workplace payments.', 'Lacag-bixinta goobaha shaqada lama soo qaadi karin.'),

  // ---- Excel preview: every worksheet of the workbook, shown before the download
  'option.payroll.previewSheets': message('Worksheets', 'Xaashiyada shaqada'),
  'option.payroll.previewSheetSize': message('{rows} rows, {columns} columns', '{rows} saf, {columns} tiir'),
  'option.payroll.previewFreezeNote': message('The header row stays in view while you scroll.', 'Safka madaxa wuu muuqdaa markaad hoos u rogto.'),
  'warning.payroll.previewChanged': message(
    'The list changed after the preview was shown, so nothing was downloaded. The preview below is the current list; check it and download again.',
    'Liiska waa isbeddelay ka dib marka horudhaca la muujiyey, sidaas darteed waxba lama soo dejin. Horudhaca hoose waa liiska hadda; hubi oo mar kale soo deji.',
  ),

  // ---- Company-level finalization: each company of a payroll month is reviewed, approved and finalized on its own
  'status.payroll.partiallyFinalized': message('Partially finalized', 'Qayb ayaa la dhammaystiray'),
  'option.payroll.companiesFinalized': message('{finalized} of {total} companies finalized', '{finalized} ka mid ah {total} shirkadood ayaa la dhammaystiray'),
  'option.payroll.sectionsTitle': message('Payroll by company', 'Mushaharka shirkad kasta'),
  'option.payroll.sectionsHelp': message(
    'Each company is reviewed and finalized on its own. Finalizing one company does not finalize the others; the month is finalized when every company is. Finalizing does not approve: a company is approved when it is paid in full, and payments can be recorded once it is finalized.',
    'Shirkad kasta si gooni ah ayaa loo eegaa loona dhammaystiraa. Dhammaystirka hal shirkad kuma dhammaystirayo kuwa kale; bisha waa la dhammaystiraa marka shirkad kasta la dhammaystiro. Dhammaystirku ma ansixiyo: shirkad waa la ansixiyaa marka si buuxda loo bixiyo, lacag-bixintuna waa la diiwaangelin karaa marka la dhammaystiro.',
  ),
  'option.payroll.sectionHistory': message('Review and finalization', 'Eegis iyo dhammaystir'),
  'option.payroll.sectionNoCompany': message('No company on the assignment', 'Qoondaynta shirkad kuma jirto'),
  'option.payroll.sectionShowLines': message('Show its lines', 'Muuji safafkeeda'),
  'option.payroll.sectionAllLines': message('Show all companies', 'Muuji dhammaan shirkadaha'),
  'option.payroll.sectionFilteredBy': message('Showing only the lines of {name}.', 'Waxaa la muujinayaa oo keliya safafka {name}.'),
  'confirm.payroll.sectionFinalizeTitle': message('Finalize {name}?', 'Ma dhammaystirtaa {name}?'),
  'confirm.payroll.sectionFinalizeBody': message(
    'Finalize the payroll of {name} for {month}. Other companies are not affected. A finalized company cannot be regenerated or reopened, and payments can then be recorded for it.',
    'Dhammaystir mushaharka {name} ee {month}. Shirkadaha kale lama saameynayo. Shirkad la dhammaystiray dib looma samayn karo lamana furi karo, lacag-bixinna waa lagu diiwaangelin karaa.',
  ),
  'confirm.payroll.sectionReopenBody': message(
    'Reopen the payroll of {name} for {month}. Only this company becomes a draft again; the others are not affected.',
    'Dib u fur mushaharka {name} ee {month}. Shirkaddan oo keliya ayaa mar kale noqonaysa qabyo; kuwa kale lama saameynayo.',
  ),
});
