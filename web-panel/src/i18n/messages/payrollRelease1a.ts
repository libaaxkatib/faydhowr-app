// Messages of HR Release 1A (company-by-company payroll generation): the "All companies" list with a state for every assigned company, the per-company Generate
// dialog, the non-blocking Temporary Replacement warnings, the month summary, the optional payment reference and the dashboard's Company payroll group. Every
// message is `proposed` (written for this implementation; the Somali wording is for the owner to review). The owner-approved "Generate / refresh draft"
// (`action.generateDraft`) is reused as it is for the per-company button; none of the 161 owner-approved messages is touched or reworded here.

import { defineMessages, message } from '../catalog.ts';

export const payrollRelease1a = defineMessages({
  // ---------------------------------------------------------------- the state of each company in a month
  'status.payroll.state.notGenerated': message('Not Generated', 'Aan La Samayn'),
  'status.payroll.state.paid': message('Paid', 'La Bixiyey'),
  'status.payroll.state.finalizedUnpaid': message('Finalized (unpaid)', 'La dhammaystiray (aan la bixin)'),
  'status.payroll.state.nothingPayable': message('Generated — Nothing Payable', 'La Sameeyey — Waxba Lama Bixinayo'),
  'status.payroll.allCompaniesPaid': message('{month} — All Companies Paid', '{month} — Dhammaan Shirkadaha Waa La Bixiyey'),
  'warning.payroll.legacyApproved': message(
    'Locked from an earlier version of the system. Finalize it before it can be paid.',
    'Nooc hore oo nidaamka ah ayaa xidhay mushaharkan. Waa in la dhammaystiraa ka hor inta aan la bixin.',
  ),
  'option.payroll.colState': message('State', 'Xaalad'),

  // ---------------------------------------------------------------- the list of all companies and its note
  'option.payroll.allCompaniesTitle': message('All companies', 'Dhammaan shirkadaha'),
  'warning.payroll.generatePerCompanyNote': message(
    'Payroll is generated one company at a time from the list below. Generating a company changes only that company and pays nothing.',
    'Mushaharka waxaa la sameeyaa shirkad shirkad, laga bilaabo liiska hoose. Samaynta mushaharka shirkad waxay beddelaysaa shirkaddaas oo keliya, waxna lama bixinayo.',
  ),
  'option.payroll.loadingCompanies': message('Loading the companies of this month…', 'Waxaa la soo rarayaa shirkadaha bishan…'),
  'option.payroll.noCompaniesAssigned': message('No company is assigned in this month.', 'Bishan shirkad lama qoondeyn.'),
  'warning.payroll.reopenPaidSuperAdmin': message(
    'This company has recorded payments. Only a Super Admin can reopen it; an HR Manager should use a Payroll Correction.',
    'Shirkaddan lacag-bixinno ayaa lagu diiwaangeliyey. Super Admin keliya ayaa dib u furi kara; HR Manager waa inuu isticmaalaa Sixitaan Mushahar.',
  ),
  'option.payroll.warningCount': message('{count} warning(s)', '{count} digniin'),

  // ---------------------------------------------------------------- the month summary (driven by the overview)
  'option.payroll.sumTitle': message('Month summary', 'Soo koobidda bisha'),
  'option.payroll.sumHelp': message(
    'Counts and totals cover the companies assigned in this month. The money figures include only companies that have been generated.',
    'Tirooyinka iyo wadarta waxay ku salaysan yihiin shirkadaha bishan loo qoondeeyey. Tirooyinka lacagta ah waxaa ku jira shirkadaha la sameeyey oo keliya.',
  ),
  'option.payroll.sumAssigned': message('Assigned', 'La qoondeeyey'),
  'option.payroll.sumPayrollTotal': message('Payroll total (generated companies)', 'Wadarta mushaharka (shirkadaha la sameeyey)'),
  'option.payroll.sumPaymentsReceived': message('Payments received', 'Lacagaha la helay'),

  // ---------------------------------------------------------------- per-company Generate
  'confirm.payroll.generateCompanyTitle': message('Generate payroll for {name} — {month}', 'Samee mushaharka {name} — {month}'),
  'confirm.payroll.generateCompanyBody': message(
    'Only {name} is generated for {month}. No other company is changed and nothing is paid.',
    'Waxaa la samaynayaa mushaharka {name} ee {month} oo keliya. Shirkad kale lama beddelayo, waxna lama bixinayo.',
  ),
  'confirm.payroll.generateCompanyReviewedNote': message(
    'This company is Reviewed. Generating it again resets its review to Draft, and it must be reviewed again.',
    'Shirkaddan waa la dib u eegay. Haddii mar kale la sameeyo, dib-u-eegistu waxay ku noqonaysaa Qabyo, waana in mar kale dib loo eegaa.',
  ),
  'notify.payroll.companyGenerated': message('Payroll for {name} — {month} is generated.', 'Mushaharka {name} — {month} waa la sameeyey.'),
  'notify.payroll.sectionChanged': message(
    'This company changed while you were looking at it. Refresh the page and try again. Nothing was changed.',
    'Shirkaddan way isbeddeshay intaad eegaysay. Cusboonaysii bogga oo mar kale isku day. Waxba lama beddelin.',
  ),

  // ---------------------------------------------------------------- Review and Finalize dialogs
  'confirm.payroll.sectionReviewTitle': message('Mark {name} as reviewed', 'Calaamadi {name} in dib loo eegay'),
  'confirm.payroll.sectionReviewBody': message(
    'Mark the payroll of {name} for {month} as reviewed. Other companies are not changed.',
    'Calaamadi mushaharka {name} ee {month} in dib loo eegay. Shirkadaha kale lama beddelayo.',
  ),

  // ---------------------------------------------------------------- Temporary Replacement warnings (never block anything)
  'warning.payroll.gen.title': message('Temporary Replacement warnings', 'Digniinaha Beddelaadka Ku-meel-gaarka'),
  'warning.payroll.gen.intro': message(
    'These warnings do not stop you. They show what is still unresolved for this company.',
    'Digniinahan kuma joojinayaan. Waxay muujinayaan wixii weli aan la xallin ee shirkaddan.',
  ),
  'warning.payroll.gen.condA': message('Temporary worker registered as a Replacement', 'Shaqaale ku-meel-gaar ah oo loo diiwaangeliyey sidii Beddelaad'),
  'warning.payroll.gen.condB': message('Temporary cover with unverified workdays', 'Beddelaad ku-meel-gaar ah oo maalmo shaqo aan la hubin leh'),
  'warning.payroll.gen.condC': message('Temporary cover cost not approved', 'Kharashka beddelaadka ku-meel-gaarka ah lama ansixin'),
  'warning.payroll.gen.condD': message('Temporary payroll calculation warning', 'Digniin ku saabsan xisaabinta mushaharka ku-meel-gaarka'),
  'warning.payroll.gen.whatRemains': message('What remains unresolved: {text}', 'Waxa weli aan la xallin: {text}'),
  'warning.payroll.gen.checking': message('Checking for Temporary Replacement warnings…', 'Waxaa la hubinayaa digniinaha Beddelaadka Ku-meel-gaarka…'),
  'warning.payroll.gen.loadFailed': message('Could not check for warnings. You can still continue.', 'Lama hubin karin digniinaha. Weli waad sii wadi kartaa.'),
  'warning.payroll.gen.none': message('No Temporary Replacement warnings for this company.', 'Digniin Beddelaad Ku-meel-gaar ah ma jirto shirkaddan.'),

  // ---------------------------------------------------------------- the optional payment reference
  'option.payroll.paymentReference': message('Payment reference (optional)', 'Tixraaca lacag-bixinta (ikhtiyaari ah)'),
  'option.payroll.paymentReferencePlaceholder': message(
    'Bank or mobile-money transaction ID, or receipt number',
    'Lambarka macaamilka bangiga ama lacagta mobaylka, ama lambarka rasiidka',
  ),
  'option.payroll.wpReference': message('Reference', 'Tixraac'),

  // ---------------------------------------------------------------- errors of the new endpoints
  'notify.error.PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY': message(
    'This company has recorded payments, so only a Super Admin can reopen it. An HR Manager should use a Payroll Correction. Nothing was changed.',
    'Shirkaddan lacag-bixinno ayaa lagu diiwaangeliyey, sidaas darteed Super Admin keliya ayaa dib u furi kara. HR Manager waa inuu isticmaalaa Sixitaan Mushahar. Waxba lama beddelin.',
  ),
  'notify.error.PAYROLL_SECTION_CHANGED': message(
    'This company changed while you were looking at it. Refresh the page and try again. Nothing was changed.',
    'Shirkaddan way isbeddeshay intaad eegaysay. Cusboonaysii bogga oo mar kale isku day. Waxba lama beddelin.',
  ),
  'notify.error.PAYROLL_COMPANY_LOCKED': message(
    'This company is approved or finalized, so it cannot be generated again. Nothing was changed.',
    'Shirkaddan waa la ansixiyey ama la dhammaystiray, sidaas darteed dib looma samayn karo. Waxba lama beddelin.',
  ),
  'notify.error.COMPANY_NOT_ASSIGNED_IN_PERIOD': message(
    'This company has no assignment in this month, so there is nothing to generate. Nothing was changed.',
    'Shirkaddan bishan qoondayn kuma laha, sidaas darteed wax la sameeyo ma jiro. Waxba lama beddelin.',
  ),

  // ---------------------------------------------------------------- the dashboard's Company payroll group
  'option.hr.companyPayrollTitle': message('Company payroll', 'Mushaharka shirkadaha'),
  'option.hr.companyPayrollMonth': message('Month', 'Bisha'),
  'option.hr.companyPayrollHelp': message(
    'Company payroll for the chosen month. The money figures include only companies that have been generated.',
    'Mushaharka shirkadaha bisha la doortay. Tirooyinka lacagta ah waxaa ku jira shirkadaha la sameeyey oo keliya.',
  ),
  'option.hr.kpiCompanyPayrollTotal': message('Company payroll total', 'Wadarta mushaharka shirkadaha'),
  'option.hr.kpiCompanyPaymentsReceived': message('Company payments received', 'Lacagaha shirkadaha la helay'),
  'option.hr.kpiCompanyOutstanding': message('Outstanding company payroll balance', 'Haraaga mushaharka shirkadaha ee la bixin doono'),
});
