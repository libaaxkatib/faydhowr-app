// Messages added by the HR UI audit remediation (package B, 2026-11-05): the Temporary cover forms (daily rate = salary / 30, a stale-version refresh, the
// closed-payroll notice of a NEW cover), the Workforce Request cancel confirmation and candidate eligibility, and the Training Batch completion
// confirmation and search. Every message is `proposed` (written for this implementation; the Somali wording is for the owner to review). No existing
// message is changed: where wording had to change, a NEW key is used and the screen switched to it.

import { defineMessages, message } from '../catalog.ts';

export const uiAuditB = defineMessages({
  // ---------------------------------------------------------------- Workdays & cost: the rate is the salary / 30 in every month (replaces `option.pipeline.dailyRateLabel` on the screen)
  'option.uiAuditB.dailyRateLabel': message(
    'Daily rate (covered salary ÷ 30, in every month)',
    'Qiimaha maalinlaha ah (mushaharka la bedelay ÷ 30, bil kasta)',
  ),

  // ---------------------------------------------------------------- a cover changed by someone else (409): the list is refreshed and the form closed
  'notify.uiAuditB.coverChangedReloaded': message(
    'Someone else changed this cover. The list has been refreshed. Open the cover again and check your change before saving.',
    'Qof kale ayaa beddelay beddelaaddan. Liiska waa la cusboonaysiiyey. Mar kale fur beddelaadda oo hubi isbeddelkaaga ka hor intaanad kaydin.',
  ),

  // ---------------------------------------------------------------- New Temporary Replacement: a back-dated cover that reaches approved or finalized payroll
  'warning.uiAuditB.createReachesClosedPayroll': message(
    'This new cover reaches {months}, which is approved or finalized payroll. Saving it is a correction of closed payroll: it needs a written reason, it is recorded with your name, and the closed month is never rewritten.',
    'Beddelaaddan cusub waxay gaadhaysaa {months}, oo ah mushahar la ansixiyay ama la xiray. Kaydinteedu waa sax-bixin mushahar xiran: waxay u baahan tahay sabab qoran, waxaa lagu diiwaangelinayaa magacaaga, bisha xiranna marna dib looma qorayo.',
  ),

  // ---------------------------------------------------------------- Workforce Requests
  'confirm.uiAuditB.cancelRequestTitle': message('Cancel this workforce request?', 'Ma jebinaysaa codsigan shaqaalaha?'),
  'confirm.uiAuditB.cancelRequestBody': message(
    'The request for {name} will be cancelled and cannot be opened again. Matches already confirmed are kept.',
    'Codsiga {name} waa la jebinayaa mana la furi karo mar kale. Isku-dhaca horey loo xaqiijiyey waa la hayaa.',
  ),
  'action.uiAuditB.yesCancelRequest': message('Yes, cancel the request', 'Haa, jebi codsiga'),
  'warning.uiAuditB.cannotMatchRegistration': message(
    'Cannot be matched yet: the registration is not complete. Missing: {missing}.',
    'Wali lama isku dhigi karo: diiwaangelintu ma dhammaystirna. Waa maqan yahay: {missing}.',
  ),

  // ---------------------------------------------------------------- Training Batches
  'confirm.uiAuditB.completeBatchTitle': message('Mark {batch} completed?', 'Ma dhammaystirtay {batch}?'),
  'confirm.uiAuditB.completeBatchBody': message(
    '{go} participant(s) will move to Need Practical and {absent} will be marked absent and stay in Need Training. This cannot be undone.',
    '{go} ka qaybgale ayaa u gudbaya Ubaahan Baraatiko, {absent} na waxaa loo calaamadinayaa maqan oo waxay ku hadhayaan Ubaahan Tababar. Tan lama noqon karo.',
  ),
  'option.uiAuditB.searchNeedTraining': message('Search people in Need Training (name, number, phone)', 'Raadi dadka Ubaahan Tababar (magac, lambar, taleefan)'),
  'option.uiAuditB.needTrainingShown': message(
    'Showing {shown} of {total} people in Need Training. Search to find the others.',
    'Waxaa la muujinayaa {shown} ka mid ah {total} qof ee Ubaahan Tababar. Raadi si aad ugu hesho kuwa kale.',
  ),
  'option.uiAuditB.needTrainingNoMatch': message('Nobody in Need Training matches this search.', 'Qofna oo ku jira Ubaahan Tababar kuma habboona raadintan.'),
});
