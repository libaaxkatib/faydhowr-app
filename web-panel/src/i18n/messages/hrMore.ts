// More messages for the HR screens added or reworded after the first Somali layer (replacement choice, Deep Cleaning, dashboard headings, report pages).
// Every message is `proposed`. Keys are `<category>.hr.<name>`.

import { defineMessages, message } from '../catalog.ts';

export const hrMore = defineMessages({
  // ---- Replacement choice when a Permanent assignment ends (SuccessorPicker)
  'option.hr.successorQuestion': message('Who takes over this Permanent position?', 'Yaa qaadanaya jagadan Joogto ah?'),
  'option.hr.successorHelp': message(
    'This position cannot be left without a replacement. The replacement\'s Permanent assignment and salary start on {start} (that day is included).',
    'Jagadan lagama tegi karo beddelaad la\'aan. Qoondaynta Joogto ah iyo mushaharka beddelaadku waxay bilaabmayaan {start} (maalintaas waa lagu daray).',
  ),
  'option.hr.dayAfterLastDay': message('the day after the last day', 'maalinta ka dambeysa maalinta ugu dambeysa'),
  'option.hr.successorTemporaryChoice': message('A Temporary employee who has been covering this position', 'Shaqaale ku-meel-gaar ah oo jagadan buuxinayay'),
  'option.hr.successorWaitingChoice': message('A person from the HR Waiting List', 'Qof ka mid ah Liiska Sugitaanka HR'),
  'option.hr.successorEffectiveDate': message('Effective date (first day of the replacement)', 'Taariikhda bilowga (maalinta ugu horreysa ee beddelaadka)'),
  'option.hr.successorEffectiveDateHint': message(
    'Leave empty for the day after the last day. That day is included in the new salary. It must be after the last day.',
    'Ka tag madhan si loo isticmaalo maalinta ka dambeysa maalinta ugu dambeysa. Maalintaas waxaa lagu daray mushaharka cusub. Waa inay ka dambeysaa maalinta ugu dambeysa.',
  ),
  'option.hr.successorTemporaryHint': message(
    'Becomes Permanent with the same employee record. The salary of the departing employee is inherited automatically; nothing to enter.',
    'Wuxuu noqonayaa Joogto isagoo isticmaalaya diiwaanka shaqaale ee la mid ah. Mushaharka shaqaalaha ka tagaya si toos ah ayuu u dhaxlayaa; waxba lama geliyo.',
  ),
  'option.hr.successorNoTemporary': message(
    'No Temporary employee has covered this position. Choose a person from the Waiting List instead.',
    'Shaqaale ku-meel-gaar ah jagadan ma buuxin. Ka dooro qof Liiska Sugitaanka halkii.',
  ),
  'option.hr.chooseTemporaryEmployee': message('Choose the Temporary employee', 'Dooro shaqaalaha ku-meel-gaarka ah'),
  'option.hr.successorCoveredFrom': message('{name} ({number}) - covered from {date}', '{name} ({number}) - wuxuu buuxiyey laga bilaabo {date}'),
  'option.hr.searchWaitingList': message('Search the Waiting List', 'Ka raadi Liiska Sugitaanka'),
  'option.hr.typeNameOrNumber': message('Type a name or an employee number.', 'Ku qor magac ama lambarka shaqaalaha.'),
  'option.hr.nameOrEmployeeNumber': message('Name or employee number', 'Magac ama lambarka shaqaalaha'),
  'option.hr.personFromWaitingList': message('Person from the Waiting List', 'Qof ka mid ah Liiska Sugitaanka'),
  'option.hr.waitingAppointHint': message(
    'The person is appointed through the normal status workflow; a registration that is not complete is refused.',
    'Qofka waxaa lagu magacaabayaa hab-socodka caadiga ah ee xaaladda; diiwaangelin aan dhammaystirnayn waa la diidayaa.',
  ),
  'option.hr.choosePerson': message('Choose a person', 'Dooro qof'),
  'option.hr.agreedSalaryPerMonth': message('Agreed salary (per month)', 'Mushaharka la isku raacay (bishii)'),

  'validation.hr.successorChoose': message(
    'Choose who takes over this Permanent position: a Temporary employee who has been covering it, or a person from the HR Waiting List.',
    'Dooro cidda qaadanaysa jagadan Joogto ah: shaqaale ku-meel-gaar ah oo buuxinayay, ama qof ka mid ah Liiska Sugitaanka HR.',
  ),
  'validation.hr.successorDateInvalid': message('Enter the effective date of the replacement as a valid date.', 'Geli taariikhda bilowga ee beddelaadka taariikh sax ah.'),
  'validation.hr.successorDateAfterLastDay': message(
    'The effective date ({date}) must be after the last paid day ({lastDay}). Both days are included, so one day cannot be paid to two people.',
    'Taariikhda bilowga ({date}) waa inay ka dambeysaa maalinta ugu dambeysa ee la bixiyey ({lastDay}). Labada maalmood waa la daray, sidaas darteed hal maalin laba qof lagu bixin karo.',
  ),
  'validation.hr.successorSalary': message('Enter the salary agreed for the person from the Waiting List.', 'Geli mushaharka lagu heshiiyey qofka ka yimid Liiska Sugitaanka.'),
  'validation.hr.successorCurrency': message('Enter the salary currency (for example USD).', 'Geli lacagta mushaharka (tusaale USD).'),

  // ---- Monthly Deep Cleaning overview (inside Facility Inspections)
  'option.hr.deepCleaning': message('Deep Cleaning', 'Nadiifin qoto dheer'),
  'option.hr.dcState.all': message('All facilities', 'Dhammaan xarumaha'),
  'option.hr.dcState.completed': message('Completed this month', 'Bishan waa la dhammeeyey'),
  'option.hr.dcState.not_completed': message('Not completed this month', 'Bishan lama dhammeynin'),
  'option.hr.dcState.overdue_multiple': message('Overdue for several months', 'Dhawr bilood ayay dib uga dhacday'),
  'option.hr.dcState.no_history': message('No Deep Cleaning recorded', 'Nadiifin qoto dheer lama diiwaangelin'),
  'option.hr.dcMonthly': message('Monthly Deep Cleaning', 'Nadiifinta qoto dheer ee bil kasta'),
  'option.hr.dcBasisShort': message(
    'Built only from Deep Cleaning completions recorded inside facility inspections.',
    'Waxaa lagu dhisay oo keliya dhammaystirka Nadiifinta qoto dheer ee lagu diiwaangeliyey kormeerada xarumaha.',
  ),
  'option.hr.dcBasisFull': message(
    'Built only from Deep Cleaning completions recorded inside facility inspections. Nothing is assumed for months with no record.',
    'Waxaa lagu dhisay oo keliya dhammaystirka Nadiifinta qoto dheer ee lagu diiwaangeliyey kormeerada xarumaha. Bilaha aan diiwaan lahayn waxba lama qaadanayo.',
  ),
  'option.hr.dcTimeZone': message('Months and dates follow Mogadishu time (UTC+3).', 'Bilaha iyo taariikhuhu waxay raacaan waqtiga Muqdisho (UTC+3).'),
  'option.hr.searchFacilityPlaceholder': message('Search facility…', 'Raadi xarun…'),
  'option.hr.searchFacility': message('Search facility', 'Raadi xarun'),
  'option.hr.dcFilterByState': message('Filter by Deep Cleaning state', 'Ku sifee xaaladda Nadiifinta qoto dheer'),
  'option.hr.includeInactiveFacilities': message('Include inactive facilities', 'Ku dar xarumaha aan shaqeynayn'),
  'warning.hr.dcInactiveHidden.one': message(
    '{n} inactive facility is not listed (nothing recorded for this month). Tick "Include inactive facilities" to see them.',
    '{n} xarun oo aan shaqeynayn liiska kuma jirto (bishan waxba lama diiwaangelin). Calaamadi "Ku dar xarumaha aan shaqeynayn" si aad u aragto.',
  ),
  'warning.hr.dcInactiveHidden.other': message(
    '{n} inactive facilities are not listed (nothing recorded for this month). Tick "Include inactive facilities" to see them.',
    '{n} xarumood oo aan shaqeynayn liiska kuma jiraan (bishan waxba lama diiwaangelin). Calaamadi "Ku dar xarumaha aan shaqeynayn" si aad u aragto.',
  ),
  'option.hr.updating': message('Updating…', 'Waa la cusboonaysiinayaa…'),
  'option.hr.loadingDeepCleaning': message('Loading Deep Cleaning…', 'Waa la soo rarayaa Nadiifinta qoto dheer…'),
  'option.hr.dcNoFacilities': message('No facilities for this month', 'Bishan xarumo ma jiraan'),
  'option.hr.dcNoFacilitiesMatch': message('No facilities match', 'Xarumo ku habboon ma jiraan'),
  'option.hr.dcNoFacilitiesHint': message(
    'Only facilities registered by the end of the month are listed. Change the month or the search.',
    'Waxaa la liis garaynayaa oo keliya xarumaha la diiwaangeliyey ka hor dhammaadka bisha. Beddel bisha ama raadinta.',
  ),
  'option.hr.dcChangeFilterHint': message('Change the filter or the search.', 'Beddel sifeynta ama raadinta.'),
  'option.hr.dcTableCaption': message('Deep Cleaning status of each facility for {month}', 'Xaaladda Nadiifinta qoto dheer ee xarun kasta ee {month}'),
  'option.hr.dcSelectedMonth': message('the selected month', 'bisha la doortay'),
  'option.hr.dcColumnThisMonth': message('This month', 'Bishan'),
  'option.hr.dcColumnLast': message('Last Deep Cleaning', 'Nadiifinta qoto dheer ee ugu dambeysay'),
  'option.hr.dcColumnLastSix': message('Last 6 months', '6-dii bilood ee ugu dambeeyay'),
  'status.hr.dcNeverRecorded': message('Never recorded', 'Weligeed lama diiwaangelin'),
  'option.hr.dcLastSixAria': message('Last six months for {company}', 'Lixdii bilood ee ugu dambeeyay ee {company}'),
  'option.hr.dcMonthDone': message('{month}: done on {date}', '{month}: waa la sameeyey {date}'),
  'option.hr.dcMonthNone': message('{month}: not recorded', '{month}: lama diiwaangelin'),
  'status.hr.dcDone': message('Done', 'La sameeyey'),
  'status.hr.dcNone': message('None', 'Midna'),
  'status.hr.dcGapDone': message('Done this month', 'Bishan waa la sameeyey'),
  'status.hr.dcGapNotYet': message('Not done yet this month', 'Bishan weli lama sameynin'),
  'status.hr.dcGapMonthsInARow': message('No Deep Cleaning for {n} months in a row', 'Nadiifin qoto dheer ma jirin {n} bilood oo isku xigta'),

  // ---- Deep Cleaning section of the inspection form
  'option.hr.dcNotRecordedChoice': message('Not recorded in this inspection', 'Lama diiwaangelin kormeerkan'),
  'option.hr.dcCompletedChoice': message('Deep Cleaning completed', 'Nadiifinta qoto dheer waa la dhammeeyey'),
  'option.hr.dcSectionHint': message(
    'Each facility should be deep cleaned at least once every calendar month. Record it here only if it was actually completed.',
    'Xarun kasta waa in la nadiifiyaa si qoto dheer ugu yaraan hal mar bil kasta. Halkan ku diiwaangeli oo keliya haddii runtii la dhammeeyey.',
  ),
  'option.hr.dcSaved': message('Recorded: completed on {date}.', 'La diiwaangeliyey: waa la dhammeeyey {date}.'),
  'option.hr.dcSavedBy': message('Recorded: completed on {date} by {name}.', 'La diiwaangeliyey: waa la dhammeeyey {date}; waxaa diiwaangeliyey {name}.'),
  'option.hr.dcChangedBy': message('Last changed by {name}.', 'Markii ugu dambaysay waxaa beddelay {name}.'),
  'option.hr.dcChangedByReason': message('Last changed by {name} ({reason}).', 'Markii ugu dambaysay waxaa beddelay {name} ({reason}).'),
  'warning.hr.dcWithdrawn': message(
    'A Deep Cleaning recorded on {date} was withdrawn{reason}. It is kept on record but not counted.',
    'Nadiifin qoto dheer oo la diiwaangeliyey {date} waa laga noqday{reason}. Waa la kaydiyey laakiin lama tiriyo.',
  ),
  'option.hr.dcDateCompleted': message('Date completed', 'Taariikhda la dhammeeyey'),
  'option.hr.dcDateCompletedHint': message(
    'The day the Deep Cleaning was done (Mogadishu date), not the inspection date. It cannot be in the future.',
    'Maalinta Nadiifinta qoto dheer la sameeyey (taariikhda Muqdisho), maaha taariikhda kormeerka. Ma noqon karto mid mustaqbalka ah.',
  ),
  'option.hr.dcNotes': message('Deep Cleaning notes', 'Qoraallada Nadiifinta qoto dheer'),
  'option.hr.optionalDot': message('Optional.', 'Ikhtiyaari ah.'),
  'option.hr.dcWhyWithdrawn': message('Why is the recorded Deep Cleaning being withdrawn?', 'Maxaa loo noqonayaa Nadiifinta qoto dheer ee la diiwaangeliyey?'),
  'option.hr.dcWhyChanged': message('Why is the Deep Cleaning record being changed?', 'Maxaa loo beddelayaa diiwaanka Nadiifinta qoto dheer?'),
  'option.hr.dcReasonHint': message(
    'At least {min} characters. The earlier values stay in the audit history with this reason.',
    'Ugu yaraan {min} xaraf. Qiimayaashii hore waxay ku jiraan taariikhda hubinta iyo sababtan.',
  ),
  'validation.hr.dcEnterDate': message('Deep Cleaning: enter the date it was completed.', 'Nadiifinta qoto dheer: geli taariikhda la dhammeeyey.'),
  'validation.hr.dcDateFuture': message('Deep Cleaning: the completion date cannot be in the future.', 'Nadiifinta qoto dheer: taariikhda dhammaystirka ma noqon karto mid mustaqbalka ah.'),
  'validation.hr.dcReasonWithdraw': message(
    'Deep Cleaning: say why the recorded completion is being withdrawn (at least 5 characters).',
    'Nadiifinta qoto dheer: sheeg sababta dhammaystirka la diiwaangeliyey loogu noqonayo (ugu yaraan 5 xaraf).',
  ),
  'validation.hr.dcReasonChange': message(
    'Deep Cleaning: say why the recorded completion is being changed (at least 5 characters).',
    'Nadiifinta qoto dheer: sheeg sababta dhammaystirka la diiwaangeliyey loo beddelayo (ugu yaraan 5 xaraf).',
  ),

  // ---- Inspection detail: the Deep Cleaning block
  'option.hr.dcDetailNotRecorded': message('Not recorded in this inspection.', 'Lama diiwaangelin kormeerkan.'),
  'warning.hr.dcDetailWithdrawn': message(
    'Recorded for {date}, then withdrawn{reason}. Kept on record, not counted.',
    'La diiwaangeliyey {date}, kadibna laga noqday{reason}. Waa la kaydiyey, lama tiriyo.',
  ),
  'option.hr.dcDetailCompletedOn': message('Completed on {date}', 'La dhammeeyey {date}'),
  'option.hr.dcRecordedByLower': message('recorded by {name}', 'waxaa diiwaangeliyey {name}'),
  'option.hr.dcChangedByLower': message('changed by {name}', 'waxaa beddelay {name}'),

  // ---- Complaint form: what is missing
  'validation.hr.complaintChooseCompany': message('Choose the company.', 'Dooro shirkadda.'),
  'validation.hr.complaintChooseType': message('Choose whether this is a staff or a customer complaint.', 'Dooro in tani tahay cabasho shaqaale ama mid macmiil.'),
  'validation.hr.complaintChooseStaff': message('Choose the employee who made the staff complaint.', 'Dooro shaqaalaha soo gudbiyey cabashada shaqaalaha.'),

  // ---- HR Dashboard
  'nav.hr.dashboardTitle': message('HR Dashboard', 'Dashboard-ka HR'),
  'nav.hr.dashboardLoading': message('Loading HR dashboard…', 'Waa la soo rarayaa dashboard-ka HR…'),
  'option.hr.kpiTotalEmployees': message('Total Employees', 'Wadarta Shaqaalaha'),
  'option.hr.kpiNewApplicants': message('New Applicants (30d)', 'Codsadayaal cusub (30 maalmood)'),
  'option.hr.kpiOpenRequests': message('Open Workforce Requests', 'Codsiyada Shaqaalaha ee furan'),
  'option.hr.kpiFulfilledRequests': message('Fulfilled Workforce Requests', 'Codsiyada Shaqaalaha ee la buuxiyey'),
  'option.hr.kpiActiveReplacements': message('Active Replacements', 'Beddelaadyada shaqeynaya'),
  'option.hr.kpiClientCompanyEmployees': message('Client Company Active Employees', 'Shaqaalaha shaqeynaya ee shirkadaha macmiilka'),
  'option.hr.sectionAttendanceToday': message('Today\'s Attendance', 'Xaadirinta Maanta'),
  'option.hr.kpiPresentToday': message('Present Today', 'Joogay Maanta'),
  'option.hr.kpiAbsentToday': message('Absent Today', 'Maqnaa Maanta'),
  'option.hr.kpiLateToday': message('Late Today', 'Daahay Maanta'),
  'option.hr.kpiMarkedToday': message('Attendance Marked Today', 'Xaadirinta La Calaamadiyey Maanta'),
  'option.hr.sectionFinancials': message('This Month\'s Financials', 'Xisaabaadka Maaliyadeed ee Bishan'),
  'option.hr.sectionPipelineQueues': message('Pipeline Queues', 'Safafka Hab-socodka'),
  'option.hr.sectionCategoryBreakdown': message('Category Breakdown', 'Kala qaybinta Qaybaha'),
  'option.hr.categoryTileAria': message('{label}: {n} employees', '{label}: {n} shaqaale'),
  'option.hr.officeStaffCategory': message('Office Staff (Category)', 'Shaqaalaha Xafiiska (Qayb)'),

  // ---- Server error codes with wording that names people, dates or amounts (Somali only; English keeps the server's own message)
  'notify.error.SUCCESSOR_TYPE_INVALID': message(
    'The replacement must be a Temporary employee who covered this position or a person from the HR Waiting List.',
    'Beddelaadku waa inuu noqdaa shaqaale ku-meel-gaar ah oo jagadan buuxiyey ama qof ka mid ah Liiska Sugitaanka HR.',
  ),
  'notify.error.SUCCESSOR_LAST_DAY_BEFORE_START': message('The last day is before the assignment started.', 'Maalinta ugu dambeysa waxay ka horreysaa bilowga qoondaynta.'),
  'notify.error.SUCCESSOR_NOT_FOUND': message(
    'The chosen replacement was not found among the active HR records.',
    'Beddelaadka la doortay lagama helin diiwaannada HR ee shaqeynaya.',
  ),
  'notify.error.SUCCESSOR_SAME_EMPLOYEE': message('An employee cannot be their own replacement.', 'Shaqaale ma noqon karo beddelaadkiisa.'),
  'notify.error.SUCCESSOR_EFFECTIVE_DATE_INVALID': message(
    'The effective date of the replacement is not a valid date.',
    'Taariikhda bilowga ee beddelaadku ma aha taariikh sax ah.',
  ),
  'notify.error.SUCCESSOR_NOT_TEMPORARY': message('The chosen person is not an active Temporary employee.', 'Qofka la doortay ma aha shaqaale ku-meel-gaar ah oo shaqeynaya.'),
  'notify.error.SUCCESSOR_NOT_COVERING': message(
    'The chosen person has not been covering this position. Choose a Temporary employee who has, or a person from the Waiting List.',
    'Qofka la doortay jagadan ma buuxin. Dooro shaqaale ku-meel-gaar ah oo buuxiyey, ama qof ka mid ah Liiska Sugitaanka.',
  ),
  'notify.error.SUCCESSOR_NOT_WAITING': message('The chosen person is not on the HR Waiting List.', 'Qofka la doortay Liiska Sugitaanka HR kuma jiro.'),
  'notify.error.SUCCESSOR_NO_SALARY_TO_INHERIT': message('The departing assignment has no salary to inherit.', 'Qoondaynta ka dhacaysa mushahar lagu dhaxlo ma laha.'),
  'notify.error.SUCCESSOR_SALARY_REQUIRED': message(
    'A person from the Waiting List needs the salary HR agrees for the position (amount and currency).',
    'Qofka ka yimid Liiska Sugitaanka wuxuu u baahan yahay mushaharka HR ku heshiiyo jagada (tirada iyo lacagta).',
  ),
  'notify.error.SUCCESSOR_SALARY_FREQUENCY_INVALID': message('The salary frequency is not valid.', 'Inta jeer ee mushaharka la bixiyo ma aha mid sax ah.'),
  'notify.error.TEMPORARY_REGISTERED_AS_REPLACEMENT': message(
    'This person is a Temporary employee. Temporary cover is recorded as a Temporary replacement (paid per verified day), not as a Replacement who takes over the position. Correct the assignment, or confirm that you really want a Replacement for this person.',
    'Qofkani waa shaqaale ku-meel-gaar ah. Beddelaadka ku-meel-gaarka ah waxaa loo diiwaangeliyaa sidii Beddelaad ku-meel-gaar ah (waxaa lagu bixiyaa maalin kasta oo la hubiyey), maaha sidii Beddelaad jagada la wareegaya. Sax qoondaynta, ama xaqiiji inaad runtii Beddelaad u rabto qofkan.',
  ),
  'notify.error.START_DATE_PASSES_SALARY_CHANGE': message(
    'The new start date is on or after a later salary change. Move the start date to before that change, or correct the salary history first.',
    'Taariikhda cusub ee bilowga waxay la mid tahay ama ka dambeysaa isbeddel mushahar oo dambe. Ka horee taariikhda bilowga isbeddelkaas, ama marka hore sax taariikhda mushaharka.',
  ),
  'notify.error.REPLACEMENT_BEFORE_ASSIGNMENT_START': message(
    'The original employee\'s last day is before the assignment started. Choose a later replacement date.',
    'Maalinta ugu dambeysa ee shaqaalihii asalka ahaa waxay ka horreysaa bilowga qoondaynta. Dooro taariikh beddelaad oo dambe.',
  ),
  'notify.error.REPLACEMENT_NEEDS_REPLACED_ASSIGNMENT': message(
    'A Replacement takes over the position of a Permanent employee. Use Replace Employee and choose the assignment being replaced.',
    'Beddelaadku wuxuu la wareegaa jagada shaqaale Joogto ah. Isticmaal Beddel Shaqaale oo dooro qoondaynta la beddelayo.',
  ),
  'notify.error.REPLACEMENT_NO_SALARY_BASIS': message(
    'The Permanent assignment being replaced has no salary to inherit.',
    'Qoondaynta Joogto ah ee la beddelayo mushahar lagu dhaxlo ma laha.',
  ),
  'notify.error.REPLACEMENT_SALARY_IS_INHERITED': message(
    'A Replacement\'s salary is the Permanent employee\'s salary and cannot be entered separately.',
    'Mushaharka Beddelaadku waa mushaharka shaqaalaha Joogto ah lamana gelin karo si gaar ah.',
  ),
  'notify.error.REPLACEMENT_SAME_EMPLOYEE': message('An employee cannot replace themselves. Choose a different employee.', 'Shaqaale isma beddeli karo. Dooro shaqaale kale.'),
  'notify.error.RETURN_BEFORE_REPLACEMENT_STARTED': message(
    'The return date must be after the day the replacement started.',
    'Taariikhda soo laabashada waa inay ka dambeysaa maalinta beddelaadku bilaabmay.',
  ),
  'notify.error.RETURN_NOT_A_REPLACEMENT': message(
    'Only an employee marked Replacement can be returned from. This assignment is Permanent; use Replace Employee to change who holds the position.',
    'Waxaa laga soo celin karaa oo keliya shaqaale loo calaamadiyey Beddelaad. Qoondayntani waa Joogto; isticmaal Beddel Shaqaale si aad u beddesho cidda jagada haysa.',
  ),
  'notify.error.RETURN_NO_ORIGINAL': message(
    'This replacement is not linked to an original employee, so there is nobody to return. Use Replace Employee instead.',
    'Beddelaadkan lagama xirna shaqaale asal ah, sidaas darteed qofna lama soo celin karo. Isticmaal Beddel Shaqaale.',
  ),
  'notify.error.RETURN_SALARY_REQUIRED': message(
    'Confirm or edit the salary of the returning employee before saving; it is not assumed to be unchanged.',
    'Xaqiiji ama wax ka beddel mushaharka shaqaalaha soo laabanaya ka hor intaadan keydin; lama qaadanayo inaanu isbeddelin.',
  ),
  'notify.error.STOP_BEFORE_ASSIGNMENT_START': message('The last day is before the assignment started.', 'Maalinta ugu dambeysa waxay ka horreysaa bilowga qoondaynta.'),
  'notify.error.USE_REPLACE_EMPLOYEE': message(
    'An assignment becomes a Replacement only through Replace Employee, which links it to the Permanent assignment it replaces and inherits that salary.',
    'Qoondayn waxay noqotaa Beddelaad Beddel Shaqaale oo keliya, taas oo ku xirta qoondaynta Joogto ah ee ay beddelayso oo ka dhaxlisa mushaharkeeda.',
  ),
  'notify.error.ASSIGNMENT_NOT_ACTIVE': message('Only an active assignment can be changed this way.', 'Qoondayn shaqeynaysa oo keliya ayaa sidan loo beddeli karaa.'),
  'notify.error.ASSIGNMENT_CANCELLED': message('A cancelled assignment cannot be edited.', 'Qoondayn la joojiyey lama wax ka beddeli karo.'),
  'notify.error.WORK_ASSIGNMENT_ALREADY_CANCELLED': message('This assignment is already cancelled.', 'Qoondayntan horey ayaa loo joojiyey.'),
  'notify.error.WORK_ASSIGNMENT_HAS_PAYROLL': message(
    'This assignment already appears in payroll; end it instead and correct pay with a payroll adjustment.',
    'Qoondayntani horey ayay ugu jirtaa mushaharka; halkii dhammee oo mushaharka ku saxo hagaajin mushahar.',
  ),
  'notify.error.WORK_ASSIGNMENT_HAS_REPLACEMENTS': message(
    'This assignment has Temporary replacement records; end it instead of cancelling.',
    'Qoondaynta waxay leedahay diiwaanno beddelaad ku-meel-gaar ah; dhammee halkii aad joojin lahayd.',
  ),
  'notify.error.WORKDAY_ALREADY_VOID': message('This workday is already void.', 'Maalintan shaqo horey looga noqday.'),
  'notify.error.WORKDAY_NOT_VERIFIABLE': message('Only a recorded workday can be verified.', 'Maalin shaqo oo la diiwaangeliyey oo keliya ayaa la hubin karaa.'),
  'notify.error.WORKDAY_DATES_REQUIRED': message('At least one workday date is required.', 'Ugu yaraan hal taariikh maalin shaqo ah ayaa loo baahan yahay.'),
  'notify.error.TEMPORARY_REPLACEMENT_NOT_ACTIVE': message('This temporary replacement has already ended.', 'Beddelaadkan ku-meel-gaarka ah horey ayuu u dhammaaday.'),
  'notify.error.EXPECTED_PAYMENT_NO_SALARY': message(
    'No permanent salary is defined for one of the covered days; the amount will not be guessed.',
    'Mushahar joogto ah looma qeexin mid ka mid ah maalmaha la daboolay; tirada lacagta lama qiyaasayo.',
  ),
  'notify.error.PAYROLL_UNSUPPORTED_SALARY': message(
    'No permanent salary is defined for one of the days; the amount will not be guessed.',
    'Mushahar joogto ah looma qeexin mid ka mid ah maalmaha; tirada lacagta lama qiyaasayo.',
  ),
  'notify.error.PAYROLL_UNRESOLVED_TEMPORARY_REPLACEMENT': message(
    'Payroll for this month cannot be calculated yet: some records register a Temporary worker as a Replacement. Correct them first.',
    'Mushaharka bishan weli lama xisaabin karo: diiwaanno qaar ayaa shaqaale ku-meel-gaar ah u diiwaangeliyey Beddelaad. Marka hore sax.',
  ),
  'notify.error.PAYROLL_RUN_NOT_REOPENABLE': message(
    'Only an approved payroll can be reopened. Finalized payroll is corrected with adjustments.',
    'Mushahar la ansixiyey oo keliya ayaa dib loo furi karaa. Mushaharka la dhammaystiray waxaa lagu saxaa hagaajinno.',
  ),
  'notify.error.PAYROLL_RUN_WRONG_STATE': message('This payroll is not in the right state for this step.', 'Mushaharkan ma ahan xaaladda saxda ah tallaabadan.'),
  'notify.error.PAYROLL_ITEM_NOTHING_TO_PAY': message('There is no positive net amount to pay on this line.', 'Safkan lacag saafi ah oo la bixiyo ma jirto.'),
  'notify.error.PAYROLL_ITEM_NOT_PAID': message(
    'Only a payroll line that is marked paid can have its payment voided. This line is not paid (it may already have been voided).',
    'Lacag-bixinta waa laga noqon karaa oo keliya safka mushaharka ee loo calaamadiyey la bixiyey. Safkan lama bixin (laga yaabo in horey looga noqday).',
  ),
  'notify.error.PAYROLL_ITEM_VOIDED': message('A voided payroll line cannot be paid.', 'Safka mushaharka ee laga noqday lama bixin karo.'),
  'notify.error.PAYROLL_PAYMENT_VOID_NOT_ALLOWED': message(
    'Only a Super Admin or an HR Manager can void a payroll payment.',
    'Keliya Super Admin ama HR Manager ayaa ka noqon kara lacag-bixinta mushaharka.',
  ),
  'notify.error.PAYROLL_CORRECTION_NOT_ALLOWED': message(
    'Only a Super Admin or an HR Manager can manage an employee debt.',
    'Keliya Super Admin ama HR Manager ayaa maamuli kara deynta shaqaalaha.',
  ),
  'notify.error.PAYROLL_CORRECTION_SUPER_ADMIN_ONLY': message(
    'This change would alter approved or finalized payroll. Only a Super Admin can correct approved or finalized payroll. Nothing was changed.',
    'Isbeddelkani wuxuu beddeli lahaa mushahar la ansixiyey ama la dhammaystiray. Keliya Super Admin ayaa saxi kara mushahar la ansixiyey ama la dhammaystiray. Waxba lama beddelin.',
  ),
  'notify.error.PAYMENT_ALREADY_VOIDED': message('This payment was already voided.', 'Lacag-bixintan horey looga noqday.'),
  'notify.error.PAYMENT_LEDGER_ENTRY_MISSING': message(
    'The payment ledger entry is missing, so it cannot be reversed safely.',
    'Qoraalka diiwaanka lacag-bixinta way maqan tahay, sidaas darteed si ammaan ah looma celin karo.',
  ),
  'notify.error.TRANSACTION_ALREADY_VOIDED': message('This transaction is already voided.', 'Macaamilkan horey looga noqday.'),
  'notify.error.TRANSACTION_NOT_VOIDABLE': message(
    'A write-off or a reversal cannot be voided: they stay in the ledger as recorded.',
    'Tirtirid deyn ama celin looma noqon karo: waxay ku sii jiraan diiwaanka sidii loo diiwaangeliyey.',
  ),
  'notify.error.CORRECTION_WRONG_KIND': message(
    'This kind of entry cannot be recorded for this correction.',
    'Nooca qoraalkan looma diiwaangelin karo hagaajintan.',
  ),
  'notify.error.VOID_REASON_REQUIRED': message(
    'A reason is required to void a payment (at least 5 characters). Nothing was changed.',
    'Sabab ayaa loo baahan yahay si looga noqdo lacag-bixinta (ugu yaraan 5 xaraf). Waxba lama beddelin.',
  ),
  'notify.error.WRITE_OFF_REASON_REQUIRED': message(
    'A write-off needs a reason (at least 5 characters). Nothing was recorded.',
    'Tirtiridda deynta waxay u baahan tahay sabab (ugu yaraan 5 xaraf). Waxba lama diiwaangelin.',
  ),
  'notify.error.ADJUSTMENT_AMOUNT_REQUIRED': message('A correction needs a non-zero amount.', 'Hagaajintu waxay u baahan tahay tiro aan eber ahayn.'),
  'notify.error.AMOUNT_EXCEEDS_OUTSTANDING': message(
    'The amount is more than what is still open. Nothing was recorded.',
    'Tirada lacagta waxay ka badan tahay waxa weli furan. Waxba lama diiwaangelin.',
  ),
  'notify.error.NO_PAYMENT_RECORDS': message(
    'There are no payment records for this period. Nothing was downloaded.',
    'Xilligan diiwaanno lacag-bixin ma jiraan. Waxba lama soo dejin.',
  ),
  'notify.error.DEEP_CLEANING_CHANGE_REASON_REQUIRED': message(
    'A recorded Deep Cleaning can only be changed or withdrawn with a reason (at least 5 characters).',
    'Nadiifin qoto dheer oo la diiwaangeliyey waxaa lagu beddeli ama laga noqon karaa oo keliya sabab (ugu yaraan 5 xaraf).',
  ),
  'notify.error.DEEP_CLEANING_ALREADY_RECORDED': message(
    'A Deep Cleaning of this company on that date is already recorded. Open that inspection instead of recording it again. Nothing was saved.',
    'Nadiifin qoto dheer oo shirkaddan ah taariikhdaas horey ayaa loo diiwaangeliyey. Fur kormeerkaas halkii mar kale aad diiwaangelin lahayd. Waxba lama keydin.',
  ),
  'notify.error.WORKPLACE_NOT_OF_COMPANY': message(
    'The selected workplace does not belong to this company.',
    'Goobta shaqada ee la doortay kuma jirto shirkadan.',
  ),
});
