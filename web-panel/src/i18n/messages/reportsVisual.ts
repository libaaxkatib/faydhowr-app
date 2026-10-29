// Messages of the visual HR and Marketing reports (2026-10-29): headline figures, chart titles and the texts that go with charts. Every message is
// `proposed` (written for this implementation); Super Admin can reword any of them in Translations.

import { defineMessages, message } from '../catalog.ts';

export const reportsVisual = defineMessages({
  // ---------------------------------------------------------------- shared by the charts
  'option.reports.showAll': message('Show all {count}', 'Tus dhammaan {count}'),
  'option.reports.showFewer': message('Show fewer', 'Tus yar'),
  'option.reports.chartEmpty': message('Nothing to show for this period.', 'Wax lagu tusi karo muddadan ma jiro.'),
  'option.reports.totalLabel': message('total', 'wadar'),

  // ---------------------------------------------------------------- HR reports
  'option.reports.kpiRegistrations': message('Registrations in the period', 'Diiwaangelinta muddada'),
  'option.reports.kpiPeople': message('People on record', 'Dadka diiwaangashan'),
  'option.reports.kpiPeopleHint': message('All statuses together', 'Dhammaan xaaladaha oo la isku daray'),
  'option.reports.kpiActive': message('Active', 'Shaqeynaya'),
  'option.reports.kpiWaiting': message('Waiting', 'Sugaya'),
  'option.reports.kpiNeedsAttention': message('Needs attention', 'Wuxuu u baahan yahay indho'),
  'option.reports.sectionPeople': message('People', 'Dadka'),
  'option.reports.sectionWorkplaces': message('Where people work', 'Meesha dadku ka shaqeeyaan'),
  'option.reports.sectionPay': message('Pay and deductions', 'Mushaharka iyo dhaafidka'),
  'option.reports.paymentsByDepartment': message('Payments by department', 'Bixinnada waax kasta'),
  'option.reports.sectionWaiting': message('Waiting and workforce requests', 'Sugitaanka iyo codsiyada shaqaalaha'),
  'option.reports.sectionPeopleMovements': message('Attendance, reviews and departures', 'Imaanshaha, qiimeynta iyo ka tagitaanka'),
  'option.reports.capacityUsed': message('{count} of {capacity} places filled', '{count} ka mid ah {capacity} meel ayaa buuxda'),
  'option.reports.noCapacitySet': message('No capacity set', 'Awood lama dejin'),
  'option.reports.requestProgress': message('Workforce request progress', 'Horumarka codsiyada shaqaalaha'),
  'option.reports.requestMatched': message('{matched} of {needed} matched', '{matched} ka mid ah {needed} ayaa la helay'),
  'option.reports.requestWhere': message('Request: {name}', 'Codsi: {name}'),
  'option.reports.requestNoPlace': message('workplace not set', 'goobta shaqada lama dejin'),
  'option.reports.requestsLink': message('Open workforce requests', 'Fur codsiyada shaqaalaha'),
  'option.reports.peopleByStatus': message('People by status', 'Dadka marka loo eego xaaladda'),
  'option.reports.activeByGenderChart': message('Active people by gender', 'Dadka shaqeynaya marka loo eego jinsiga'),

  // ---------------------------------------------------------------- Marketing reports
  'option.marketing.sectionRecords': message('Records', 'Diiwaannada'),
  'option.marketing.kpiTotal': message('Records in the period', 'Diiwaannada muddada'),
  'option.marketing.kpiOpenFollowUps': message('Open follow-ups', 'La socodyada furan'),
  'option.marketing.kpiOpenFollowUpsHint': message('Scheduled or rescheduled', 'La qorsheeyey ama dib loo qorsheeyey'),
  'option.marketing.kpiDueToday': message('Due today', 'Maanta u egyahay'),
  'option.marketing.kpiOverdue': message('Overdue', 'Dib uga dhacay'),
  'option.marketing.typeByStatus': message('XARUN and PROJECT by status', 'XARUN iyo PROJECT marka loo eego xaaladda'),
  'option.marketing.typeByStatusNote': message('The same records, side by side.', 'Diiwaannadaas oo isku xigxiga.'),
  'option.marketing.followUpStatusTitle': message('Follow-up status', 'Xaaladda la socodka'),
  'option.marketing.followUpStatusNote': message('Follow-ups of the records counted here.', 'La socodyada diiwaannada halkan lagu tiriyey.'),
  'option.marketing.workloadTitle': message('Open follow-ups per person', 'La socodyada furan qof kasta'),
  'option.marketing.workloadOverdue': message('{count} overdue', '{count} dib uga dhacay'),
  'option.marketing.workloadUnassigned': message('Nobody assigned', 'Qof lama magacaabin'),
  'option.marketing.workloadEmpty': message('No open follow-ups.', 'La socodyo furan ma jiraan.'),
  'option.marketing.recordsPerTeam': message('Records per team', 'Diiwaannada koox kasta'),
  'option.marketing.recordsPerPerson': message('Records per person', 'Diiwaannada qof kasta'),
});
