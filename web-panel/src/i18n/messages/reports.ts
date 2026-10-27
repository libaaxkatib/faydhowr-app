// Messages for the HR report screens (HR Reports, Leave Report, Performance Report).
// Keys are `<category>.reports.<name>`; every entry holds its English text (exactly as the screen shows it today) and its Somali text.

import { defineMessages, message } from '../catalog.ts';

export const reports = defineMessages({

  // HR Reports: filters
  'option.reports.title': message('HR Reports', 'Warbixinnada Maamulka shaqaalaha'),
  'option.reports.dateFrom': message('Date from', 'Taariikhda laga bilaabo'),
  'option.reports.dateTo': message('Date to', 'Taariikhda ilaa'),
  'option.reports.clientCompany': message('Client company', 'Shirkadda macmiilka'),
  'option.reports.allLocations': message('All locations', 'Dhammaan goobaha'),
  'option.reports.assignmentStatus': message('Assignment status', 'Xaaladda qoondaynta'),
  'option.reports.activeDefault': message('Active (default)', 'Shaqeynaya (caadiga)'),
  'option.reports.detailedReports': message('Detailed Reports', 'Warbixinno faahfaahsan'),
  'option.reports.loading': message('Loading report…', 'Waa la soo rarayaa warbixinta…'),

  // HR Reports: cards and their empty states
  'option.reports.registrations': message('Registrations', 'Diiwaangelinta'),
  'option.reports.byStatus': message('By Status', 'Xaalad ahaan'),
  'option.reports.pipelineBreakdown': message('Pipeline Breakdown', 'Kala qaybinta marxaladaha'),
  'option.reports.pipelineEmpty': message('No one currently in the pre-Waiting pipeline.', 'Hadda qofna kuma jiro marxaladaha ka horreeya Shaqa sugayaal.'),
  'option.reports.byCategory': message('By Category', 'Qayb ahaan'),
  'option.reports.byDepartment': message('By Department', 'Waax ahaan'),
  'option.reports.departmentsEmpty': message('No departments configured yet.', 'Waaxyo weli lama habeynin.'),
  'option.reports.byWorkplace': message('By Workplace', 'Goob shaqo ahaan'),
  'option.reports.noWorkplaceYet': message('Company recorded, workplace not set yet', 'Shirkadda waa la diiwaangeliyey, goobta shaqada weli lama dejin'),
  'option.reports.salaryByCompany': message('Salary by Company', 'Mushahar shirkad ahaan'),
  'option.reports.salaryByCompanyEmpty': message('No company-assigned salaries yet.', 'Mushaharro shirkad loo qoondeeyey weli ma jiraan.'),
  'option.reports.penaltiesByDepartment': message('Penalties by Department', 'Ganaaxyo waax ahaan'),
  'option.reports.penaltiesEmpty': message('No penalties recorded in this range.', 'Muddadan ganaax lama diiwaangelin.'),
  'option.reports.advancesByDepartment': message('Advances by Department', 'Lacag hormaris waax ahaan'),
  'option.reports.advancesEmpty': message('No salary advances recorded in this range.', 'Muddadan lacag hormaris lama diiwaangelin.'),
  'option.reports.waitingDuration': message('Waiting Duration', 'Muddada sugitaanka'),
  'option.reports.waitingUnder7': message('Under 7 days', 'Ka yar 7 maalmood'),
  'option.reports.waiting7to30': message('7–30 days', '7–30 maalmood'),
  'option.reports.waiting30to90': message('30–90 days', '30–90 maalmood'),
  'option.reports.waitingOver90': message('Over 90 days', 'Ka badan 90 maalmood'),
  'option.reports.waitingUnavailable': message('Unavailable (no waiting_since)', 'Lama heli karo (waiting_since ma jiro)'),
  'option.reports.waitingByCategory': message('Waiting by Category', 'Sugitaanka qayb ahaan'),
  'option.reports.waitingByCategoryEmpty': message('No one currently waiting.', 'Hadda qofna kuma jiro Shaqa sugayaal.'),
  'option.reports.requestsByCompany': message('Workforce Requests by Company', 'Codsiyada shaqaalaha shirkad ahaan'),
  'option.reports.requestsEmpty': message('No workforce requests recorded.', 'Codsiyo shaqaale lama diiwaangelin.'),
  'option.reports.activeByGender': message('Active Workforce by Gender', 'Shaqaalaha Shaqeynaya jinsi ahaan'),
  'option.reports.activeByGenderEmpty': message('No active employees with a recorded gender.', 'Shaqaale Shaqeynaya oo jinsigiisa la diiwaangeliyey ma jiro.'),
  'option.reports.replacementsByCompany': message('Temporary Replacements by Company', 'Beddelaadyo ku-meel-gaar ah shirkad ahaan'),
  'option.reports.replacementsEmpty': message('No temporary replacements recorded.', 'Beddelaad ku-meel-gaar ah lama diiwaangelin.'),
  'option.reports.separationsRehires': message('Separations & Rehires', 'Kala-tagid iyo dib u shaqaalaysiin'),
  'option.reports.separationsEmpty': message('No separations recorded in this range.', 'Muddadan kala-tagid lama diiwaangelin.'),
  'option.reports.rehiredInRange': message('Rehired in range', 'Dib loo shaqaaleysiiyey muddada'),
  'option.reports.supervisorOffice': message('Supervisor Pool & Office Staff', 'Kooxda Kormeerayaasha iyo Shaqaalaha Xafiiska'),
  'option.reports.nominatedSupervisors': message('Nominated supervisors in range', 'Kormeerayaasha la magacaabay muddada'),
  'option.reports.officeDepartment': message('Office · {name}', 'Xafiiska · {name}'),
  'option.reports.attendanceBreakdown': message('Attendance Breakdown', 'Kala qaybinta Xaadirinta'),
  'option.reports.attendanceEmpty': message('No attendance recorded in this range.', 'Muddadan xaadirin lama diiwaangelin.'),
  'option.reports.reviewsByRating': message('Performance Reviews by Rating', 'Dib-u-eegisyada waxqabadka qiimeyn ahaan'),
  'option.reports.reviewsEmpty': message('No performance reviews recorded in this range.', 'Muddadan dib-u-eegis waxqabad lama diiwaangelin.'),

  // Leave Report and Performance Report tables
  'option.reports.colRecordedBy': message('Recorded By', 'Diiwaangeliyey'),
  'option.reports.leavesEmptyTitle': message('No leaves recorded', 'Fasaxyo lama diiwaangelin'),
  'option.reports.leavesEmptyBody': message('Leaves appear here once recorded for an employee.', 'Fasaxyadu halkan ayay ka muuqdaan marka shaqaale loo diiwaangeliyo.'),
  'option.reports.colReviewDate': message('Review Date', 'Taariikhda dib-u-eegista'),
  'option.reports.colReviewedBy': message('Reviewed By', 'Dib u eegay'),
  'option.reports.reviewsListEmptyTitle': message('No performance reviews recorded', 'Dib-u-eegis waxqabad lama diiwaangelin'),
  'option.reports.reviewsListEmptyBody': message('Reviews appear here once recorded for an employee.', 'Dib-u-eegisyadu halkan ayay ka muuqdaan marka shaqaale loo diiwaangeliyo.'),
});
