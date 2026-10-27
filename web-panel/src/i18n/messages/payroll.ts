// Messages for payroll runs, payroll corrections and the financial ledger.
// Keys are `<category>.payroll.<name>`; every entry holds its English text (exactly as the screen shows it today) and its Somali text.

import { defineMessages, message } from '../catalog.ts';

export const payroll = defineMessages({
  // Run status explanations (the labels reuse option.payrollStatus.*)
  'status.payroll.helpDraft': message('Still being prepared. It can be regenerated.', 'Weli waa la diyaarinayaa. Dib ayaa loo samayn karaa.'),
  'status.payroll.helpReviewed': message('Checked by one person. A different person approves it.', 'Hal qof ayaa hubiyey. Qof kale ayaa ansixiya.'),
  'status.payroll.helpApproved': message(
    'The month is locked. It can still be reopened until it is finalized.',
    'Bisha waa la xidhay. Weli dib ayaa loo furi karaa ilaa la dhammaystiro.',
  ),
  'status.payroll.helpFinalized': message(
    'Final. It cannot be edited or reopened; corrections are recorded as adjustments in a later month.',
    'Waa kama dambays. Lama wax-ka-beddeli karo lamana dib u furi karo; sixitaannada waxaa loo diiwaangeliyaa hagaajinno bil dambe ah.',
  ),

  // Payment status of a payroll line
  'status.payroll.paid': message('Paid', 'La bixiyey'),
  'status.payroll.paidOn': message('Paid {date}', 'La bixiyey {date}'),
  'status.payroll.notPaidYet': message('Not paid yet', 'Weli lama bixin'),

  // Kind of a payroll line deduction (keyed by the stored kind)
  'status.payroll.deduction.replacement': message('Temporary cover', 'Beddelaad ku-meel-gaar ah'),
  'status.payroll.deduction.penalty': message('Penalty', 'Ganaax'),
  'status.payroll.deduction.advance': message('Salary advance', 'Lacag hormaris'),
  'status.payroll.deduction.absence': message('Absence', 'Maqnaansho'),
  'status.payroll.deduction.adjustment': message('Adjustment', 'Hagaajin'),
  'status.payroll.deduction.unpaid_leave': message('Unpaid leave', 'Fasax aan mushahar lahayn'),
  'status.payroll.deduction.overpayment_recovery': message('Overpayment recovery', 'Soo celinta lacagta dheeraadka ah'),

  // Status of a correction (keyed by the stored status)
  'status.payroll.correction.open': message('Still open', 'Weli furan'),
  'status.payroll.correction.recovered': message('Fully recovered', 'Si buuxda dib loo helay'),
  'status.payroll.correction.written_off': message('Written off', 'La cafiyey'),
  'status.payroll.correction.settled': message('Paid in full', 'Si buuxda loo bixiyey'),

  // Buttons and links
  'action.payroll.downloadExcel': message('Download Excel', 'Soo deji Excel'),
  'action.payroll.downloadList': message('Download payment list (Excel)', 'Soo deji liiska lacag-bixinta (Excel)'),
  'action.payroll.downloadCompanyList': message('Download {name} list (Excel)', 'Soo deji liiska {name} (Excel)'),
  'action.payroll.showDeductions': message('Show deductions', 'Muuji ka goynta'),
  'action.payroll.hideDeductions': message('Hide deductions', 'Qari ka goynta'),
  'action.payroll.recordPayment': message('Record payment', 'Diiwaangeli lacag-bixin'),
  'action.payroll.recordRepayment': message('Record repayment', 'Diiwaangeli dib-u-bixin'),
  'action.payroll.writeOff': message('Write off', 'Ka cafi'),
  'action.payroll.voidRepayment': message('Void repayment', 'Ka noqo dib-u-bixinta'),
  'action.payroll.voidLink': message('void', 'ka noqo'),

  // Field labels and selectable options
  'option.payroll.reason': message('Reason', 'Sabab'),
  'option.payroll.paymentListFor': message('Payment list for', 'Liiska lacag-bixinta ee'),
  'option.payroll.allCompanies': message('All companies', 'Dhammaan shirkadaha'),
  'option.payroll.type': message('Type', 'Nooca'),
  'option.payroll.adjustCorrection': message('Correction (add or take away an amount)', 'Sixitaan (ku dar ama ka goy qaddar)'),
  'option.payroll.adjustReversal': message(
    'Reverse the whole net pay ({net}) - can only be done once',
    'Rogo mushaharka saafiga ah oo dhan ({net}) - mar keliya ayaa la samayn karaa',
  ),
  'option.payroll.amountUsdLong': message('Amount in US dollars', 'Qaddarka doolarka Maraykanka'),
  'option.payroll.applyInPayroll': message('Apply in the payroll of', 'Ku dabaq mushaharka bisha'),
  'option.payroll.paymentDate': message('Payment date', 'Taariikhda lacag-bixinta'),
  'option.payroll.amountUsd': message('Amount (USD)', 'Qaddarka (USD)'),
  'option.payroll.date': message('Date', 'Taariikh'),
  'option.payroll.writeOffReason': message('Reason for the write-off', 'Sababta ka cafinta'),
  'option.payroll.noteOptional': message('Note (optional)', 'Qoraal (ikhtiyaari ah)'),

  // Hints and validation
  'validation.payroll.reasonHint10': message('At least 10 characters. This is kept in the audit log.', 'Ugu yaraan 10 xaraf. Tan waxaa lagu hayaa diiwaanka hubinta.'),
  'validation.payroll.reasonRequired5': message('Required, at least 5 characters.', 'Waajib, ugu yaraan 5 xaraf.'),
  'validation.payroll.reasonHint5': message('At least 5 characters. Kept in the audit history.', 'Ugu yaraan 5 xaraf. Waxaa lagu hayaa taariikhda hubinta.'),
  'validation.payroll.writeOffReasonHint': message(
    'Required, at least 5 characters. Kept in the audit history.',
    'Waajib, ugu yaraan 5 xaraf. Waxaa lagu hayaa taariikhda hubinta.',
  ),
  'validation.payroll.adjustAmountHint': message(
    'A positive amount pays the employee more. A negative amount (for example -25) recovers money.',
    'Qaddar togan wuxuu shaqaalaha ka siinayaa wax dheeraad ah. Qaddar taban (tusaale -25) wuxuu dib u soo celiyaa lacag.',
  ),
  'validation.payroll.adjustPeriodHint': message(
    'Must be {month} or later, and that month must not be approved or finalized yet.',
    'Waa inay noqotaa {month} ama ka dambeysa, bishaasna waa inayan weli ahayn mid la ansixiyey ama la dhammaystiray.',
  ),
  'validation.payroll.adjustPeriodError': message('Choose a month after {month}.', 'Dooro bil ka dambeysa {month}.'),
  'validation.payroll.amountAtMost': message('At most {amount}.', 'Ugu badnaan {amount}.'),
  'validation.payroll.writeOffReasonMissing': message(
    'Give the reason for writing this debt off (at least 5 characters).',
    'Sii sababta aad deynta uga cafineyso (ugu yaraan 5 xaraf).',
  ),
  'validation.payroll.amountPositive': message('Enter an amount greater than zero.', 'Geli qaddar ka weyn eber.'),
  'validation.payroll.amountTooMuch': message('The amount is more than what is still open ({amount}).', 'Qaddarku wuu ka badan yahay waxa weli furan ({amount}).'),

  // Notices that explain what an action does or why it is unavailable
  'warning.payroll.lockedApproved': message(
    '{month} is already approved, so it cannot be regenerated. Reopen it first if it needs to change.',
    '{month} horey ayaa loo ansixiyey, sidaas darteed dib looma samayn karo. Marka hore dib u fur haddii wax laga beddelayo.',
  ),
  'warning.payroll.lockedFinalized': message(
    '{month} is already finalized, so it cannot be regenerated. Use adjustments for corrections.',
    '{month} horey ayaa loo dhammaystiray, sidaas darteed dib looma samayn karo. Isticmaal hagaajinno si wax loo sixo.',
  ),
  'warning.payroll.generateNote': message(
    'Creates the draft payroll for the month from salaries, absences, penalties, advances and approved temporary cover. Running it again refreshes the draft.',
    'Waxay samaysaa mushaharka qabyada ah ee bisha iyadoo laga duulayo mushaharyada, maqnaanshaha, ganaaxyada, lacagaha hormarka ah iyo beddelaadka ku-meel-gaarka ah ee la ansixiyey. Haddii mar kale la socodsiiyo, qabyada waa la cusboonaysiiyaa.',
  ),
  'warning.payroll.paymentListNote': message(
    'The payment list is an Excel file of who to pay for the selected month (an estimate until the month is approved); choose a company to get only that company. Downloading it does not mark anything paid or change anything.',
    'Liiska lacag-bixinta waa fayl Excel ah oo muujinaya cidda la bixinayo bisha la doortay (qiyaas ilaa bisha la ansixiyo); dooro shirkad si aad u hesho shirkaddaas oo keliya. Soo dejintiisu waxba kuma calaamadeyso in la bixiyey, waxna ma beddesho.',
  ),
  'warning.payroll.checkBeforeApproving': message('Please check before approving', 'Fadlan hubi ka hor inta aan la ansixin'),
  'warning.payroll.temporaryCoverWaiting': message('Temporary cover waiting for approval', 'Beddelaad ku-meel-gaar ah oo ansixin sugaya'),
  'warning.payroll.notDeductedYet': message(
    '{summary}. It was calculated but is NOT deducted until the replacement is approved.',
    '{summary}. Waa la xisaabiyey laakiin lagama goynayo ilaa beddelaadka la ansixiyo.',
  ),
  'warning.payroll.notDeducted': message('(not deducted)', '(lagama goyn)'),
  // Corrected for the coverage-period rule (U8): the days are the days the cover existed.
  'warning.payroll.coverSummary.one': message('{who} covered for {n} day', '{who} ayaa buuxiyey {n} maalin'),
  'warning.payroll.coverSummary.other': message('{who} covered for {n} days', '{who} ayaa buuxiyey {n} maalmood'),
  'warning.payroll.aTemporaryEmployee': message('a Temporary employee', 'shaqaale ku-meel-gaar ah'),
  'warning.payroll.atDailyRate': message('at {rate} per day', 'oo ah {rate} maalintii'),
  'warning.payroll.earlierVoided.one': message('{n} earlier payment voided (kept in the ledger)', '{n} lacag-bixin hore ayaa laga noqday (waxaa lagu hayaa diiwaanka)'),
  'warning.payroll.earlierVoided.other': message('{n} earlier payments voided (kept in the ledger)', '{n} lacag-bixin hore ayaa laga noqday (waxaa lagu hayaa diiwaanka)'),
  'warning.payroll.followUpDebt': message('No longer paid by payroll: follow up the debt here', 'Mushaharka mar dambe kuma bixinayo: deynta halkan ka raac'),

  // Dialogs
  'confirm.payroll.approveTitle': message('Approve this payroll?', 'Ma ansixisaa mushaharkan?'),
  'confirm.payroll.approveBody': message(
    'Approving locks {month}: salary and temporary-cover changes for that month will be refused. A different person from the reviewer must approve. You can still reopen an approved payroll before it is finalized.',
    'Ansixintu waxay xidhaa {month}: isbeddellada mushaharka iyo beddelaadka ku-meel-gaarka ah ee bishaas waa la diidi doonaa. Waa inuu ansixiyaa qof ka duwan kii dib u eegay. Weli waad dib u furi kartaa mushahar la ansixiyey ka hor inta aan la dhammaystirin.',
  ),
  'confirm.payroll.theMonth': message('the month', 'bisha'),
  'confirm.payroll.finalizeTitle': message('Finalize this payroll?', 'Ma dhammaystirtaa mushaharkan?'),
  'confirm.payroll.finalizeBody': message(
    'Finalized payroll cannot be edited or reopened; corrections are recorded as adjustments in a later month. After finalizing you can mark each line as paid.',
    'Mushahar la dhammaystiray lama wax-ka-beddeli karo lamana dib u furi karo; sixitaannada waxaa loo diiwaangeliyaa hagaajinno bil dambe ah. Marka la dhammaystiro, saf kasta waad u calaamadayn kartaa in la bixiyey.',
  ),
  'confirm.payroll.reopenBody': message(
    '{month} goes back to Draft and has to be reviewed and approved again. Only an approved payroll can be reopened.',
    '{month} wuxuu ku noqonayaa Qabyo oo waa in mar kale dib loo eego oo la ansixiyaa. Keliya mushahar la ansixiyey ayaa dib loo furi karaa.',
  ),
  'confirm.payroll.adjustNote': message(
    'Net pay for that month: {net}. The finalized month is never edited; the adjustment is applied in the payroll of the later month you choose.',
    'Mushaharka saafiga ah ee bishaas: {net}. Bisha la dhammaystiray weligeed lama wax-ka-beddelo; hagaajinta waxaa lagu dabaqayaa mushaharka bisha dambe ee aad doorato.',
  ),
  'confirm.payroll.voidTitle': message('Void this payment', 'Ka noqo lacag-bixintan'),
  'confirm.payroll.voidBody': message(
    'This cancels the payment of {net} marked as paid to {name} for {month}. The original payment is not edited or deleted: a linked reversal is added to the payment ledger, the line goes back to "not paid" and can be paid again. It is recorded in the audit history.',
    'Tani waxay rogaysaa lacag-bixinta {net} ee loo calaamadeeyey inay la siiyey {name} bisha {month}. Lacag-bixinta asalka ah lama wax-ka-beddelo lamana tirtiro: rogid ku xiran ayaa lagu darayaa diiwaanka lacag-bixinta, safku wuxuu ku noqonayaa "lama bixin" waana la bixin karaa mar kale. Waxaa lagu diiwaangeliyey taariikhda hubinta.',
  ),
  'confirm.payroll.theEmployee': message('the employee', 'shaqaalaha'),
  'confirm.payroll.markPaidBody': message(
    'This records that {name} was paid {net} for {month}. The payment record is created once and cannot be repeated.',
    'Tani waxay diiwaangelinaysaa in {name} la siiyey {net} bisha {month}. Diiwaanka lacag-bixinta mar keliya ayaa la sameeyaa lamana soo noqnoqon karo.',
  ),
  'confirm.payroll.writeOffTitle': message('Write off part of the debt', 'Ka cafi qayb ka mid ah deynta'),
  'confirm.payroll.settlePaymentTitle': message('Record payment of an underpayment', 'Diiwaangeli bixinta lacagta la bixin waayey'),
  'confirm.payroll.settleRepaymentTitle': message('Record a repayment of the debt', 'Diiwaangeli dib-u-bixinta deynta'),
  'confirm.payroll.settleOwedToEmployee': message(
    '{name} ({number}), {period}: owed to the employee {amount}',
    '{name} ({number}), {period}: lacagta shaqaalaha loo leeyahay {amount}',
  ),
  'confirm.payroll.settleOwedByEmployee': message(
    '{name} ({number}), {period}: still owed by the employee {amount}',
    '{name} ({number}), {period}: deynta shaqaalaha saaran ee weli furan {amount}',
  ),
  'confirm.payroll.settleScheduled': message(
    ', of which {amount} is already scheduled in a draft payroll',
    ', taas oo {amount} mar hore lagu jadwalay mushahar qabyo ah',
  ),
  'confirm.payroll.writeOffTail': message(
    'A write-off forgives this part of the debt. The debt record and its history are kept, the write-off is audited with your reason, and it cannot be undone silently.',
    'Ka cafidda waxay cafisaa qaybtan deynta. Diiwaanka deynta iyo taariikhdeeda waa la hayaa, ka cafidda waxaa lagu hubiyaa sababtaada, lamana soo celin karo si aamusnaan ah.',
  ),
  'confirm.payroll.paymentTail': message(
    'This records a payment you have made; it is kept as its own transaction and in the payment ledger. The closed payroll month is not changed.',
    'Tani waxay diiwaangelinaysaa lacag-bixin aad samaysay; waxaa loo hayaa dhaqdhaqaaq u gaar ah iyo diiwaanka lacag-bixinta. Bisha mushaharka ee xidhan lama beddelayo.',
  ),
  'confirm.payroll.repaymentTail': message(
    'This records money the employee gave back outside payroll; it is kept as its own transaction.',
    'Tani waxay diiwaangelinaysaa lacag shaqaaluhu dib u soo celiyey mushaharka dibaddiisa; waxaa loo hayaa dhaqdhaqaaq u gaar ah.',
  ),
  'confirm.payroll.voidPaymentToEmployeeTitle': message('Void a payment to the employee', 'Ka noqo lacag-bixin shaqaalaha la siiyey'),
  'confirm.payroll.voidRepaymentTitle': message('Void a repayment', 'Ka noqo dib-u-bixin'),
  'confirm.payroll.voidPaymentToEmployeeBody': message(
    'Voiding keeps the payment and adds a linked reversal of {amount} to the payment ledger; the amount is owed to {name} again. Nothing is deleted.',
    'Ka noqoshadu waxay haysaa lacag-bixinta waxayna ku darraysaa rogid ku xiran oo ah {amount} diiwaanka lacag-bixinta; lacagta mar kale ayaa loo leeyahay {name}. Waxba lama tirtirayo.',
  ),
  'confirm.payroll.voidRepaymentBody': message(
    'Voiding keeps the repayment in the history and puts {amount} back on the debt of {name}.',
    'Ka noqoshadu waxay dib-u-bixinta ku haysaa taariikhda waxayna {amount} dib ugu celisaa deynta {name}.',
  ),

  // Toasts and errors
  'notify.payroll.draftReady': message('Draft payroll for {month} is ready.', 'Mushaharka qabyada ah ee {month} waa diyaar.'),
  'notify.payroll.draftFailed': message('Could not generate the payroll draft.', 'Lama samayn karin qabyada mushaharka.'),
  'notify.payroll.reviewed': message(
    'Payroll marked as reviewed. A different person must approve it.',
    'Mushaharka waxaa loo calaamadeeyey inuu dib loo eegay. Waa inuu ansixiyaa qof kale.',
  ),
  'notify.payroll.reviewFailed': message('Could not mark the payroll as reviewed.', 'Lama calaamadeyn karin mushaharka inuu dib loo eegay.'),
  'notify.payroll.approved': message('Payroll approved.', 'Mushaharka waa la ansixiyey.'),
  'notify.payroll.approveFailed': message('Could not approve the payroll.', 'Lama ansixin karin mushaharka.'),
  'notify.payroll.finalized': message('Payroll finalized.', 'Mushaharka waa la dhammaystiray.'),
  'notify.payroll.finalizeFailed': message('Could not finalize the payroll.', 'Lama dhammaystiri karin mushaharka.'),
  'notify.payroll.reopened': message('Payroll reopened. It is a draft again.', 'Mushaharka dib ayaa loo furay. Waa qabyo mar kale.'),
  'notify.payroll.reopenFailed': message('Could not reopen the payroll.', 'Dib looma furi karin mushaharka.'),
  'notify.payroll.paymentListFailed': message('Could not download the payment list.', 'Lama soo dejin karin liiska lacag-bixinta.'),
  'notify.payroll.excelFailed': message('Could not download the Excel file.', 'Lama soo dejin karin faylka Excel.'),
  'notify.payroll.adjustmentRecorded': message(
    'Adjustment recorded. It will apply in the {month} payroll.',
    'Hagaajinta waa la diiwaangeliyey. Waxay ka dhaqan gali doontaa mushaharka {month}.',
  ),
  'notify.payroll.adjustmentFailed': message('Could not record the adjustment.', 'Lama diiwaangelin karin hagaajinta.'),
  'notify.payroll.paymentVoided': message(
    'Payment voided. A linked reversal was recorded and the line can be paid again.',
    'Lacag-bixinta waa laga noqday. Rogid ku xiran ayaa la diiwaangeliyey, safkana mar kale ayaa la bixin karaa.',
  ),
  'notify.payroll.paymentVoidFailed': message('Could not void this payment. Nothing was changed.', 'Lacag-bixintan lama noqon karin. Waxba lama beddelin.'),
  'notify.payroll.markedPaid': message('Marked as paid.', 'Waa la calaamadeeyey in la bixiyey.'),
  'notify.payroll.markPaidFailed': message('Could not mark this line as paid.', 'Safkan lama calaamadeyn karin in la bixiyey.'),
  'notify.payroll.debtWrittenOff': message('Debt written off.', 'Deynta waa laga cafiyey.'),
  'notify.payroll.paymentRecorded': message('Payment recorded.', 'Lacag-bixinta waa la diiwaangeliyey.'),
  'notify.payroll.repaymentRecorded': message('Repayment recorded.', 'Dib-u-bixinta waa la diiwaangeliyey.'),
  'notify.payroll.nothingRecorded': message('Nothing was recorded.', 'Waxba lama diiwaangelin.'),
  'notify.payroll.correctionPaymentVoided': message(
    'Payment voided: a linked reversal was recorded.',
    'Lacag-bixinta waa laga noqday: rogid ku xiran ayaa la diiwaangeliyey.',
  ),
  'notify.payroll.repaymentVoided': message('Repayment voided. The debt is restored.', 'Dib-u-bixinta waa laga noqday. Deynta waa la soo celiyey.'),
  'notify.payroll.nothingChanged': message('Nothing was changed.', 'Waxba lama beddelin.'),

  // Payroll months list, run detail and lines
  'option.payroll.gross': message('Gross', 'Guud'),
  'option.payroll.netPay': message('Net pay', 'Mushaharka saafiga ah'),
  'option.payroll.coverDeductions': message('Cover deductions', 'Ka goynta beddelaadka'),
  'option.payroll.generated': message('Generated', 'La sameeyey'),
  'option.payroll.otherDeductions': message('Other deductions', 'Ka goynta kale'),
  'option.payroll.grossPay': message('Gross pay', 'Mushaharka guud'),
  'option.payroll.coverDeductionsLong': message('Temporary-cover deductions', 'Ka goynta beddelaadka ku-meel-gaarka ah'),
  'option.payroll.earlierAdjustments': message('Adjustments from earlier months', 'Hagaajinnada bilaha hore'),
  'option.payroll.companyWorkplace': message('Company / workplace', 'Shirkadda / goobta shaqada'),
  'option.payroll.days': message('Days', 'Maalmaha'),
  'option.payroll.companyMissing': message('Company missing', 'Shirkad ma jirto'),
  'option.payroll.payrollMonths': message('Payroll months', 'Bilaha mushaharka'),
  'option.payroll.noPayrollYet': message('No payroll yet', 'Mushahar weli ma jiro'),
  'option.payroll.pickMonth': message('Pick a month and generate the first draft.', 'Dooro bil oo samee qabyada ugu horreeya.'),
  'option.payroll.clickMonth': message('Click a month to see its lines.', 'Dhagsii bil si aad u aragto safafkeeda.'),
  'option.payroll.loadingPayroll': message('Loading payroll…', 'Waa la soo rarayaa mushaharka…'),
  'option.payroll.loadingLines': message('Loading payroll lines…', 'Waa la soo rarayaa safafka mushaharka…'),
  'option.payroll.payrollFor': message('Payroll for {month}', 'Mushaharka {month}'),
  'option.payroll.noLines': message('No payroll lines', 'Safaf mushahar ma jiraan'),
  'option.payroll.noLinesRun': message(
    'No employee had an active assignment with a salary in this month.',
    'Bishan shaqaale ma lahayn qoondayn shaqeynaysa oo mushahar leh.',
  ),
  'option.payroll.noDeductions': message('No deductions for this employee this month.', 'Shaqaalahan bishan wax ka goyn ah ma jiro.'),
  'option.payroll.trailGeneratedBy': message('Generated by {name} on {date}', '{name} ayaa sameeyey, {date}'),
  'option.payroll.trailGenerated': message('Generated on {date}', 'La sameeyey {date}'),
  'option.payroll.trailReviewedBy': message('Reviewed by {name} on {date}', '{name} ayaa dib u eegay, {date}'),
  'option.payroll.trailReviewed': message('Reviewed on {date}', 'Dib u eegay {date}'),
  'option.payroll.trailApprovedBy': message('Approved by {name} on {date}', '{name} ayaa ansixiyey, {date}'),
  'option.payroll.trailApproved': message('Approved on {date}', 'La ansixiyey {date}'),
  'option.payroll.trailFinalizedBy': message('Finalized by {name} on {date}', '{name} ayaa dhammaystiray, {date}'),
  'option.payroll.trailFinalized': message('Finalized on {date}', 'La dhammaystiray {date}'),

  // Payroll rollup report
  'option.payroll.rollupMonthlySalary': message('Monthly Salary', 'Mushaharka Bille'),
  'option.payroll.rollupDaysPaid': message('Days paid', 'Maalmaha la bixiyey'),
  'option.payroll.rollupFinalPay': message('final pay', 'mushaharka ugu dambeeya'),
  'option.payroll.rollupAbsence': message('{days}d · {amount}', '{days}m · {amount}'),
  'option.payroll.penalties': message('Penalties', 'Ganaaxyo'),
  'option.payroll.advances': message('Advances', 'Lacag hormarisyo'),
  'option.payroll.rollupNetPayable': message('Net Payable', 'Saafiga la bixinayo'),
  'option.payroll.rollupOvertime': message('Overtime', 'Waqti dheeraad ah'),
  'option.payroll.rollupOvertimePending': message('Pending management decision', 'Go\'aanka maamulka ayaa la sugayaa'),
  'option.payroll.rollupClientCompany': message('Client company', 'Shirkadda macmiilka'),
  'option.payroll.rollupLoading': message('Loading payroll rollup…', 'Waa la soo rarayaa isu-geynta mushaharka…'),
  'option.payroll.rollupEstimate': message('Estimate', 'Qiyaas'),
  'warning.payroll.rollupStale': message(
    'The {status} payroll run for this month no longer matches these figures. Regenerate it on the Payroll page.',
    'Mushaharka {status} ee bishan ma waafaqsana tirooyinkan mar dambe. Dib u samee bogga Mushaharka.',
  ),
  'option.payroll.rollupFinalFigures': message(
    'Final figures from the {status} payroll run for {period}.',
    'Tirooyinka kama dambaysta ah waa kuwa mushaharka {period} oo xaaladdiisu tahay {status}.',
  ),
  'option.payroll.rollupEmpty': message(
    'No payable assignments matched the selected period and filters.',
    'Wax qoondayn ah oo mushahar la bixin karo ah oo ku haboon muddada iyo shaandhooyinka la doortay lama helin.',
  ),
  'option.payroll.rollupEmployeeCount': message('{n} employee(s)', '{n} shaqaale'),
  'option.payroll.rollupTotal': message('Total Net Payable: {amount}', 'Wadarta saafiga la bixinayo: {amount}'),
  'option.payroll.rollupTotalEstimate': message('Total Net Payable (estimate): {amount}', 'Wadarta saafiga la bixinayo (qiyaas): {amount}'),

  // Financial ledger
  'option.payroll.ledgerPayments': message('Payments', 'Lacag-bixinnada'),
  'option.payroll.ledgerReasonNotes': message('Reason / Notes', 'Sabab / Qoraal'),
  'option.payroll.ledgerEmptyTitle': message('No financial records', 'Diiwaan maaliyadeed ma jiro'),
  'option.payroll.ledgerEmptyBody': message(
    'Payments, penalties, and advances appear here once recorded.',
    'Lacag-bixinnada, ganaaxyada iyo lacag hormarisyada halkan ayay ka muuqdaan marka la diiwaangeliyo.',
  ),

  // Corrections panel
  'option.payroll.correctionsTitle': message(
    'Corrections of closed payroll, overpayment recovery and underpayments',
    'Sixitaannada mushaharka xidhan, soo celinta lacagta dheeraadka ah iyo lacagaha ka yaraaday',
  ),
  'option.payroll.totalOverpaid': message('Overpaid in closed months', 'Wax ka badan oo la bixiyey bilaha xidhan'),
  'option.payroll.totalRecovered': message('Recovered so far', 'Dib loo helay ilaa hadda'),
  'option.payroll.totalDebtOwed': message('Debt still owed', 'Deynta shaqaalaha saaran ee weli furan'),
  'option.payroll.totalUnderpaid': message('Underpaid in closed months', 'Wax ka yar oo la bixiyey bilaha xidhan'),
  'option.payroll.totalUnderpaymentsPaid': message('Underpayments paid', 'Lacagaha ka yaraaday ee la bixiyey'),
  'option.payroll.totalStillToPay': message('Still to pay to employees', 'Lacagta shaqaalaha loo leeyahay'),
  'option.payroll.paymentsVoided': message('Payroll payments voided', 'Lacag-bixinnada mushaharka ee laga noqday'),
  'warning.payroll.correctionsExplain': message(
    'The closed month is never changed. An overpayment is taken from the employee\'s later salaries (never more than that month\'s pay, and from the final salary when they leave); what is left stays a debt that a Super Admin or an HR Manager manages here. A Super Admin can write off what cannot be recovered (with a reason; the debt and its history stay). An underpayment is owed to the employee and is paid only by a Super Admin, as a separate recorded payment, which only a Super Admin can void (a linked reversal is added; nothing is deleted).',
    'Bisha xidhan weligeed lama beddelo. Lacagta dheeraadka ah waxaa laga qaadaa mushaharyada dambe ee shaqaalaha (weligeed ma dhaafto mushaharka bishaas, waxaana laga qaadaa mushaharka ugu dambeeya marka uu shaqada ka tago); wixii hadha waxay noqonayaan deyn halkan ka maamula Super Admin ama HR Manager. Super Admin wuxuu ka cafi karaa waxa aan la soo celin karin (sabab la siiyo; deynta iyo taariikhdeeda way hadhayaan). Lacagta ka yaraaday waa lagu leeyahay shaqaalaha, waxaana bixin kara Super Admin keliya, isagoo u bixinaya lacag-bixin gaar ah oo la diiwaangeliyey, taas oo Super Admin keliya uu ka noqon karo (rogid ku xiran ayaa lagu daraa; waxba lama tirtiro).',
  ),
  'option.payroll.colRecorded': message('Recorded', 'La diiwaangeliyey'),
  'option.payroll.colShouldHaveBeen': message('Should have been', 'Intii ay ahayd inay noqoto'),
  'option.payroll.colDifference': message('Difference', 'Farqiga'),
  'option.payroll.colSettled': message('Settled so far', 'La xaliyey ilaa hadda'),
  'option.payroll.colReasonWho': message('Reason and who', 'Sababta iyo cidda'),
  'option.payroll.colAction': message('Action', 'Tallaabo'),
  'option.payroll.kindOverpayment': message('Overpaid', 'Wax ka badan la bixiyey'),
  'option.payroll.kindUnderpayment': message('Underpaid', 'Wax ka yar la bixiyey'),
  'option.payroll.settledBreakdown': message(
    '{payroll} payroll + {repaid} repaid + {writtenOff} written off',
    '{payroll} mushahar + {repaid} dib loo bixiyey + {writtenOff} la cafiyey',
  ),
  'option.payroll.recoveryDraft': message('{period}: {amount} (draft)', '{period}: {amount} (qabyo)'),
  'option.payroll.txPaid': message('paid', 'la bixiyey'),
  'option.payroll.txReversed': message('payment reversed', 'lacag-bixinta waa la rogay'),
  'option.payroll.txRepaid': message('repaid', 'dib loo bixiyey'),
  'option.payroll.txWrittenOff': message('written off', 'la cafiyey'),
  'option.payroll.txBy': message('by {name}', 'waxaa diiwaangeliyey {name}'),
  'option.payroll.txReason': message('reason: {reason}', 'sabab: {reason}'),
  'option.payroll.txBalance': message('(balance {from} → {to})', '(haraaga {from} → {to})'),
  'option.payroll.txVoided': message('(voided)', '(laga noqday)'),
  'option.payroll.txVoidedReason': message('(voided: {reason})', '(laga noqday: {reason})'),
  'warning.payroll.voidsExplain': message(
    'A payment marked paid was cancelled by a Super Admin or an HR Manager. The original payment stays in the payment ledger; a linked reversal of the same amount was added, and the payroll line can be paid again.',
    'Lacag-bixin loo calaamadeeyey inay la bixiyey waxaa joojiyey Super Admin ama HR Manager. Lacag-bixinta asalka ah waxay ku hadhaysaa diiwaanka lacag-bixinta; rogid ku xiran oo isla qaddarkaas ah ayaa lagu daray, safka mushaharkana waa la bixin karaa mar kale.',
  ),
  'option.payroll.colAmountVoided': message('Amount voided', 'Qaddarka laga noqday'),
  'option.payroll.colPaymentsOriginalReversal': message('Payments (original → reversal)', 'Lacag-bixinnada (asalka → rogidda)'),
});
