// Messages added by the Marketing UI audit (finding group E): the Commission screen (log an entry, month / employee filters), the rate form, the Teams
// page, the follow-up editors, the Reports period, and the "Brought by" choice of a new XARUN / PROJECT. Every message is `proposed` (written for this
// implementation; the Somali wording is for the owner to review). None of the 161 owner-approved messages and none of the 86 approved ones is touched or
// reworded: existing wording is reused as it is and new wording gets a NEW key.

import { defineMessages, message } from '../catalog.ts';

export const uiAuditE = defineMessages({
  // ------------------------------------------------------------------ Commission: log an entry on a record (the existing manual-entry endpoint)
  'action.marketing.logCommission': message('Log commission', 'Diiwaangeli komishan'),
  'confirm.marketing.logCommissionTitle': message('Log a commission entry', 'Diiwaangeli gelitaanka komishanka'),
  'option.marketing.logCommissionHint': message(
    'Records who brought this lead. Nothing is calculated; the amount, if any, is typed by the Marketing Manager.',
    'Waxay diiwaangelisaa cidda keentay fursaddan. Waxba lama xisaabiyo; qadarka, haddii uu jiro, waxaa qora Maamulaha Suuqgeynta.',
  ),
  'option.marketing.commissionEmployee': message('Employee who brought it', 'Shaqaalaha keenay'),
  'option.marketing.chooseEmployee': message('Choose an employee…', 'Dooro shaqaale…'),
  'option.marketing.referenceDate': message('Reference date', 'Taariikhda tixraaca'),
  'option.marketing.commissionAmountOptional': message('Agreed amount (optional)', 'Qadarka la isku raacay (ikhtiyaari)'),
  'notify.marketing.commissionLogged': message('Commission entry logged.', 'Diiwaanka komishanka waa la geliyey.'),
  'validation.marketing.chooseEmployee': message('Choose an employee.', 'Dooro shaqaale.'),
  'validation.marketing.enterReferenceDate': message('Enter the reference date.', 'Geli taariikhda tixraaca.'),
  'validation.marketing.enterAmount': message('Enter an amount of 0 or more.', 'Geli qadar ah 0 ama ka badan.'),

  // ------------------------------------------------------------------ Commission list: month, employee and page
  'option.marketing.commissionMonth': message('Month', 'Bisha'),
  'option.marketing.commissionEmployeeFilter': message('Employee', 'Shaqaalaha'),

  // ------------------------------------------------------------------ Commission rate form
  'option.marketing.rateEmployee': message('Employee (optional)', 'Shaqaalaha (ikhtiyaari)'),
  'option.marketing.rateEmployeeHint': message(
    'Leave empty for the default rate that applies to all employees.',
    'Ka tag madhan heerka caadiga ah ee lagu dabaqo dhammaan shaqaalaha.',
  ),
  'validation.marketing.enterRateValue': message('Enter a rate of 0 or more.', 'Geli heer ah 0 ama ka badan.'),

  // ------------------------------------------------------------------ Teams page
  'validation.marketing.enterTeamName': message('Enter the team name.', 'Geli magaca kooxda.'),
  'confirm.marketing.removeMemberTitle': message('Remove this member?', 'Ka saar xubintan?'),
  'confirm.marketing.removeMemberBody': message(
    '{name} will no longer be in this team and will stop seeing its records. The account is not deleted.',
    '{name} mar dambe kooxdan kuma jiri doono, diiwaannadeedana ma arki doono. Akoonka lama tirtirayo.',
  ),
  'confirm.marketing.removeMemberButton': message('Remove member', 'Ka saar xubinta'),

  // ------------------------------------------------------------------ Follow-ups page
  'option.marketing.tabAll': message('All (with completed)', 'Dhammaan (ay ku jiraan kuwii dhammaaday)'),
  'warning.marketing.feedbackReplacesHint': message(
    'Saving replaces the feedback shown here on the record. Earlier versions stay in the follow-up history.',
    'Kaydintu waxay beddeshaa jawaabta halkan ku taal ee diiwaanka. Nooca hore wuxuu ku hadhayaa taariikhda la socodka.',
  ),
  'warning.marketing.statusUnchanged': message('Choose a different status to save.', 'Dooro xaalad kale si aad u kaydiso.'),

  // ------------------------------------------------------------------ New XARUN / PROJECT: who brought it
  'option.marketing.broughtByDefault': message('Me (default)', 'Aniga (caadi)'),
  'option.marketing.broughtByHint': message(
    'Only if you register this lead for another employee; otherwise it is credited to you.',
    'Kaliya haddii aad fursaddan u diiwaangelinayso shaqaale kale; haddii kale adiga ayaa lagu xisaabinayaa.',
  ),

  // ------------------------------------------------------------------ Reports: the period actually shown
  'option.marketing.reportPeriod': message('Showing records created from {from} to {to}.', 'Waxaa la tusayaa diiwaannada la sameeyey {from} ilaa {to}.'),
  'warning.marketing.reportDefaultPeriod': message(
    'No dates chosen, so the last 30 days are shown.',
    'Taariikh lama dooran, sidaas darteed 30-kii maalmood ee la soo dhaafay ayaa la tusayaa.',
  ),
  'option.marketing.reportQuickRange': message('Quick range', 'Muddo degdeg ah'),
  'option.marketing.presetToday': message('Today', 'Maanta'),
  'option.marketing.presetLast7': message('Last 7 days', '7-dii maalmood ee la soo dhaafay'),
  'option.marketing.presetLast30': message('Last 30 days', '30-kii maalmood ee la soo dhaafay'),
  'option.marketing.presetThisMonth': message('This month', 'Bishan'),
});
