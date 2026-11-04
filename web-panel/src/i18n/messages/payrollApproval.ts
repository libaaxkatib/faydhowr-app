// Messages of the payment-based approval of a payroll month (owner decision 2026-11-04): a company is APPROVED by being paid in full ("Approve Paid" /
// "Approve Partial"), the month is approved by itself when every company is, and there is no Approve button. Every message is `proposed` (written for this
// implementation; the Somali wording is for the owner to review) - none of the 161 owner-approved messages is touched or reworded here.

import { defineMessages, message } from '../catalog.ts';

export const payrollApproval = defineMessages({
  // ---------------------------------------------------------------- the two statuses a company has: the PAYROLL and the PAYMENT / approval
  'status.payroll.apUnpaid': message('Unpaid', 'Lacag lama bixin'),
  'status.payroll.apPartial': message('Partially paid', 'Qayb ayaa la bixiyey'),
  'status.payroll.apApproved': message('Approved · Paid', 'La ansixiyey · La bixiyey'),
  'option.payroll.colPayrollStatus': message('Payroll status', 'Xaaladda mushaharka'),
  'option.payroll.colPaymentStatus': message('Payment / approval', 'Lacag-bixin / ansixin'),

  // ---------------------------------------------------------------- the month
  'option.payroll.approvalTitle': message('Monthly approval', 'Ansixinta bisha'),
  'option.payroll.approvalHelp': message(
    'A company is approved when it has been paid in full. The month is approved automatically when every company is approved. There is no separate Approve button.',
    'Shirkad waa la ansixiyaa marka si buuxda loo bixiyo. Bisha si toos ah ayaa loo ansixiyaa marka shirkad kasta la ansixiyo. Badhan Ansixi oo gooni ah ma jiro.',
  ),
  'option.payroll.approvalCompanies': message('Companies', 'Shirkadaha'),
  'option.payroll.approvalApproved': message('Fully paid and approved', 'Si buuxda la bixiyey oo la ansixiyey'),
  'option.payroll.approvalUnpaid': message('Unpaid', 'Aan la bixin'),
  'option.payroll.approvalPartial': message('Partially paid', 'Qayb la bixiyey'),
  'option.payroll.approvalNotFinalized': message('Not finalized yet', 'Weli aan la dhammaystirin'),
  'option.payroll.approvalOutstanding': message('Remaining outstanding', 'Haraaga weli la bixin doono'),
  'status.payroll.monthApproved': message('Month approved', 'Bisha waa la ansixiyey'),
  'status.payroll.monthPending': message('Month approval pending', 'Ansixinta bisha way sugaysaa'),

  // ---------------------------------------------------------------- the two payment actions of a finalized company
  'action.payroll.approvePaid': message('Approve Paid', 'Ansixi Lacagta La Bixiyey'),
  'action.payroll.approvePartial': message('Approve Partial', 'Ansixi Bixin Qayb ah'),
  'action.payroll.apRecordPaid': message('Record payment and approve', 'Diiwaangeli bixinta oo ansixi'),
  'action.payroll.apRecordPartial': message('Record partial payment', 'Diiwaangeli bixinta qayb ah'),
  'confirm.payroll.apPaidTitle': message('Approve {name} as paid', 'U ansixi {name} sida la bixiyey'),
  'confirm.payroll.apPaidBody': message(
    'This records a payment of {amount} USD - the whole outstanding balance of {name} for {month} - and approves the company, because it is then fully paid. Payroll amounts are not changed.',
    'Tani waxay diiwaangelinaysaa bixin dhan {amount} USD - dhammaan haraagga {name} ee {month} - waxayna ansixinaysaa shirkadda, maxaa yeelay markaas si buuxda ayaa loo bixiyey. Qaddarka mushaharka lama beddelayo.',
  ),
  'confirm.payroll.apPartialTitle': message('Partial payment for {name}', 'Bixin qayb ah oo loogu talagalay {name}'),
  'confirm.payroll.apPartialBody': message(
    'Enter the amount paid now. The company stays Partially paid and is NOT approved until its whole payroll has been paid.',
    'Geli qaddarka hadda la bixinayo. Shirkaddu waxay ahaanaysaa Qayb la bixiyey mana la ansixinayo ilaa mushaharkeeda oo dhan la bixiyo.',
  ),
  'option.payroll.apPayNow': message('Amount paid now (USD)', 'Qaddarka hadda la bixinayo (USD)'),
  'notify.payroll.apApproved': message('Payment recorded. The company is fully paid and approved.', 'Lacag-bixinta waa la diiwaangeliyey. Shirkadda si buuxda ayaa loo bixiyey waana la ansixiyey.'),
  'notify.payroll.apPartial': message('Partial payment recorded. The company is not approved until it is fully paid.', 'Bixinta qayb ahi waa la diiwaangeliyey. Shirkadda lama ansixinayo ilaa si buuxda loo bixiyo.'),

  // ---------------------------------------------------------------- HR Manager adding an employee to a company whose payroll is already closed
  'warning.company.correctionNoticeAdd': message(
    'This employee starts in a payroll month that is already approved or finalized. Adding them is recorded as an HR correction with your name and the reason below. The closed month stays exactly as it was; any money owed to the employee is recorded as a payroll correction, and paying it stays with a Super Admin.',
    'Shaqaalahani wuxuu ka bilaabmayaa bil mushahar oo horey loo ansixiyey ama loo dhammaystiray. Ku darista waxaa loo diiwaangelinayaa sixitaan HR oo magacaaga iyo sababta hoose leh. Bisha xidhan sidii hore ayay u hadhaysaa; lacagta shaqaalaha lagu leeyahay waxaa loo diiwaangelinayaa sixitaan mushahar, bixinteeduna waxay ku hadhaysaa Super Admin.',
  ),
});
