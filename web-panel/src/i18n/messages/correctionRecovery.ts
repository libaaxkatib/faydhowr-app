// Messages of the correction and recovery actions (owner decisions 2026-11-04): reopen finalized payroll, reconcile an overpaid company, end an assignment
// without a replacement and reopen it, and close / reopen a company. Every message is `proposed`: the Somali was written for this implementation (the owner
// asked for no separate review). None of the 161 owner-approved messages is touched, reworded or removed here.

import { defineMessages, message } from '../catalog.ts';

export const correctionRecovery = defineMessages({
  // ---------------------------------------------------------------- reopen a finalized company payroll
  'action.correction.reopenFinalized': message('Reopen for correction', 'Dib u fur si loo saxo'),
  'confirm.correction.reopenFinalizedTitle': message('Reopen finalized payroll: {name}', 'Dib u fur mushaharka la dhammaystiray: {name}'),
  'confirm.correction.reopenFinalizedBody': message(
    'Payroll {month} for {name} is finalized. Reopening sends it back to Draft so it can be corrected and finalized again. Every payment already recorded stays exactly as it is, and the company is not approved again until it is paid in full. Who reopened it, when and why is recorded.',
    'Mushaharka {month} ee {name} waa la dhammaystiray. Dib u furistu waxay ku celinaysaa Qabyo si loo saxo oo mar kale loo dhammaystiro. Lacag-bixin kasta oo horey loo diiwaangeliyey sidiisii ayay u hadhaysaa, shirkaddana mar kale lama ansixin ilaa si buuxda loo bixiyo. Qofka furay, goorta iyo sababta waa la diiwaangeliyaa.',
  ),
  'validation.correction.reasonHint10': message('Required, at least 10 characters. It is saved with the correction.', 'Waa loo baahan yahay, ugu yaraan 10 xaraf. Waxaa lagu keydiyaa sax-bixinta.'),
  'notify.correction.reopened': message('Finalized payroll reopened. No payment was changed.', 'Mushaharkii la dhammaystiray dib ayaa loo furay. Lacag-bixin lama beddelin.'),
  'option.correction.reopeningHistory': message('Reopening history', 'Taariikhda dib-u-furista'),
  'option.correction.reopeningLine': message(
    '{who} ({role}) reopened it on {date}: {reason}. Already paid then: {paid}.',
    '{who} ({role}) ayaa dib u furay {date}: {reason}. Markaas horey loo bixiyey: {paid}.',
  ),
  'option.correction.reopeningRefinalized': message('Finalized again on {date}.', 'Mar kale ayaa la dhammaystiray {date}.'),

  // ---------------------------------------------------------------- overpaid company and its reconciliation
  'status.payroll.apOverpaid': message('Overpaid', 'Si ka badan loo bixiyey'),
  'option.payroll.approvalOverpaid': message('Overpaid (to reconcile)', 'Si ka badan loo bixiyey (la isku-xaajoo)'),
  'option.correction.overpaidAmount': message('Overpaid: {amount}', 'Si ka badan loo bixiyey: {amount}'),
  'action.correction.reconcile': message('Reconcile overpayment', 'Isku-xaaji lacagta dheeraadka ah'),
  'confirm.correction.reconcileTitle': message('Reconcile overpayment: {name}', 'Isku-xaaji lacagta dheeraadka ah: {name}'),
  'confirm.correction.reconcileBody': message(
    '{name} has been paid {amount} more than its corrected payroll says. Record how the excess was settled. This step changes no payment and moves no money; it only records the settlement. The company is approved again once the whole excess is settled.',
    '{name} waxaa loo bixiyey {amount} ka badan sida mushaharkeeda la saxay sheegayo. Diiwaangeli sida dheeraadka loo xaliyey. Tallaabadan lacag-bixin ma beddesho mana dhaqaajiso lacag; waxay diiwaangelisaa oo keliya xalinta. Shirkadda mar kale ayaa la ansixinayaa marka dheeraadka oo dhan la xaliyo.',
  ),
  'option.correction.settleMethod': message('How it was settled', 'Sida loo xaliyey'),
  'option.correction.methodReturned': message('Returned', 'La soo celiyey'),
  'option.correction.methodCarried': message('Carried forward to a later month', 'Loo wareejiyey bil dambe'),
  'option.correction.methodOther': message('Other', 'Kale'),
  'option.correction.settleAll': message('The whole excess', 'Dhammaan dheeraadka'),
  'option.correction.settleAmount': message('Amount to settle (USD)', 'Lacagta la xalinayo (USD)'),
  'option.correction.settledOn': message('Settled on', 'La xaliyey taariikhda'),
  'option.correction.reason': message('Reason', 'Sabab'),
  'validation.correction.reasonHint5': message('Required, at least 5 characters.', 'Waa loo baahan yahay, ugu yaraan 5 xaraf.'),
  'notify.correction.reconciled': message('Overpayment reconciled.', 'Lacagta dheeraadka ah waa la isku-xaajiyey.'),

  // ---------------------------------------------------------------- end an assignment without a replacement, and reopen it
  'option.correction.replacementOptional': message(
    'A replacement is optional. You can end this assignment now and give the position to someone else later.',
    'Beddelid ma aha qasab. Hadda ayaad soo afjari kartaa meeshan shaqo, goor dambena qof kale ayaad siin kartaa.',
  ),
  'action.correction.reopenAssignment': message('Reopen assignment', 'Dib u fur meesha shaqo'),
  'confirm.correction.reopenAssignmentTitle': message('Reopen the assignment of {name}', 'Dib u fur meesha shaqo ee {name}'),
  'confirm.correction.reopenAssignmentBody': message(
    'The assignment of {name} at {company} ended on {date}. Reopening makes the same assignment active again with no end date. It is refused if the position was given to someone else since. The employee HR status does not change.',
    'Meesha shaqo ee {name} ee {company} waxay dhammaatay {date}. Dib u furistu waxay mar kale ka dhigaysaa isla meeshii shaqo mid firfircoon oo aan taariikh dhammaad lahayn. Waa la diidayaa haddii booska la siiyey qof kale. Xaaladda HR ee shaqaalaha ma isbeddesho.',
  ),
  'notify.correction.assignmentReopened': message('Assignment reopened. The employee HR status was not changed.', 'Meesha shaqo dib ayaa loo furay. Xaaladda HR ee shaqaalaha lama beddelin.'),

  // ---------------------------------------------------------------- close and reopen a company
  'status.company.closed': message('Closed', 'Xiran'),
  'action.correction.closeCompany': message('Close company', 'Xir shirkadda'),
  'action.correction.reopenCompany': message('Reopen company', 'Dib u fur shirkadda'),
  'confirm.correction.closeTitle': message('Close {name}', 'Xir {name}'),
  'confirm.correction.closeBody': message(
    'Use this when {name} has stopped operating. Every active assignment at this company ends on the last day of operation, so those employees are not paid by this company after it. Their assignments at other companies, their HR status, payroll, payments and history do not change. Temporary covers are not ended; they are listed for HR to review.',
    'Isticmaal marka {name} joojiso shaqada. Meel kasta oo shaqo oo firfircoon oo shirkaddan ah waxay dhammaanaysaa maalinta ugu dambeysa ee shaqada, sidaas darteed shaqaalahaas shirkaddan kama qaadanayaan mushahar ka dib. Meelaha shaqo ee shirkadaha kale, xaaladda HR, mushaharka, lacag-bixinta iyo taariikhdu ma isbeddelaan. Beddelaadyada ku-meel-gaarka ah lama joojiyo; waxaa lagu qoraa si HR u dib-u-eego.',
  ),
  'option.correction.closeDate': message('Last day of operation', 'Maalinta ugu dambeysa ee shaqada'),
  'option.correction.closeReason': message('Why is the company closing?', 'Maxay shirkaddu u xirmaysaa?'),
  'option.correction.payrollCorrectionReason': message(
    'Payroll correction reason (needed only if this change reaches a finalized month)',
    'Sababta sax-bixinta mushaharka (looga baahan yahay oo keliya haddii isbeddelkani gaadho bil la dhammaystiray)',
  ),
  'option.correction.closedOn': message('Closed on {date}: {reason}', 'Waa xirmay {date}: {reason}'),
  'notify.correction.companyClosed': message('Company closed. {ended} assignment(s) ended on the last day.', 'Shirkadda waa la xiray. {ended} meel shaqo ayaa ku dhammaatay maalinta ugu dambeysa.'),
  'option.correction.coversToReview': message('{count} Temporary cover(s) need HR review.', '{count} beddelaad ku-meel-gaar ah ayaa u baahan dib-u-eegis HR.'),
  'confirm.correction.reopenCompanyTitle': message('Reopen {name}', 'Dib u fur {name}'),
  'confirm.correction.reopenCompanyBody': message(
    'Only the assignments that THIS closure ended can come back. Tick the ones to restore; the rest stay ended. Anything that conflicts is shown with the reason and cannot be restored.',
    'Waxaa soo laaban kara oo keliya meelaha shaqo ee xiristaankan dhammeeyey. Calaamadee kuwa la soo celinayo; inta kale way dhammaatay sidoodii. Wax kasta oo is-diidaya waa la tusayaa sababtiisa lamana soo celin karo.',
  ),
  'option.correction.notRestorable': message('Cannot be restored: {why}', 'Lama soo celin karo: {why}'),
  'option.correction.restoreCancelled': message('Was cancelled by the closure (it had not started)', 'Xiristaanka ayaa baajiyey (weli ma bilaabmin)'),
  'notify.correction.companyReopened': message('Company reopened.', 'Shirkadda dib ayaa loo furay.'),
  'option.correction.noAssignmentsToRestore': message('The closure ended no assignments.', 'Xiristaanku ma dhammeynin meel shaqo.'),
});
