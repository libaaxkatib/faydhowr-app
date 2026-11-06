// Messages added by the payroll / deductions UI audit (package C). Every message here is NEW and `proposed` (the Somali wording is for the owner to review).
// No existing catalogue message is reworded: where the old wording no longer fits, the screen switches to a key in this file.

import { defineMessages, message } from '../catalog.ts';

export const uiAuditC = defineMessages({
  // ---------------------------------------------------------------- penalty / advance entry limits (mirror DeductionRules on the server)
  'validation.deduction.amountTooLarge': message('The amount cannot be more than 1,000,000.', 'Qadarku ma noqon karo mid ka badan 1,000,000.'),
  'validation.deduction.dateTooEarly': message('The date cannot be before 1 January 2020.', 'Taariikhdu ma noqon karto mid ka hor ah 1da Janaayo 2020.'),
  'validation.deduction.reasonLong': message('The reason can be at most 500 characters.', 'Sababtu waa inay noqotaa ugu badnaan 500 xaraf.'),

  // ---------------------------------------------------------------- payroll adjustment
  'validation.payroll.adjustAmountRange': message('The amount must be between -100,000 and 100,000.', 'Qadarku waa inuu u dhexeeyaa -100,000 iyo 100,000.'),

  // ---------------------------------------------------------------- payroll corrections ledger
  'warning.correction.supersededNoActions': message(
    'Superseded: this correction is kept as history only. Nothing can be paid, repaid or written off against it.',
    'La beddelay: sixitaankan waa taariikh keliya. Wax lacag ah lagama bixin karo, lagama celin karo, lagamana tirtiri karo.',
  ),
  'notify.payroll.correctionsLoadFailed': message('The payroll corrections could not be loaded.', 'Sixitaannada mushaharka lama soo qaadi karin.'),

  // ---------------------------------------------------------------- payroll lines and company payments
  'status.payroll.nothingToPayLine': message('Nothing to pay', 'Wax lagu bixiyo ma jiro'),
  'warning.payroll.noCompanyPayLine': message(
    'These lines have no company, so they cannot be paid for the company as a whole. Pay them line by line.',
    'Safafkan shirkad ma laha, sidaas darteed looma bixin karo shirkad ahaan. Safaf-safaf u bixi.',
  ),
  'warning.payroll.reopenPaidLines': message(
    'Some lines of this company were marked paid one by one. Void those payments first, then reopen it.',
    'Safaf ka mid ah shirkaddan waa la calaamadeeyey inay la bixiyey mid-mid. Marka hore burbur lacag-bixintaas, kadibna dib u fur.',
  ),

  // ---------------------------------------------------------------- financial ledger
  'option.payroll.ledgerReversalOf': message('Reversal of payment #{id}', 'Celinta lacag-bixinta #{id}'),
  'validation.reports.dateRangeOrder': message('The "to" date cannot be before the "from" date.', 'Taariikhda "ilaa" ma noqon karto mid ka hor ah taariikhda "laga bilaabo".'),

  // ---------------------------------------------------------------- server refusals that had no wording yet (proposed; added by the consolidation of the UI audit remediation)
  'notify.error.CORRECTION_SUPERSEDED': message(
    'This correction was replaced when the payroll was rebuilt, so nothing can be recorded against it. Nothing was changed.',
    'Sixitaankan waa la beddelay markii mushaharka dib loo dhisay, sidaa darteed waxba laguma diiwaan gelin karo. Wax isbeddel ah lagama sameynin.',
  ),
  'notify.error.WORKPLACE_NOT_IN_PAYROLL': message(
    'These lines name no company, so they can only be paid line by line. Nothing was changed.',
    'Safafkani ma magacaabaan shirkad, sidaa darteed waxaa lagu bixin karaa safaf-safaf oo keliya. Wax isbeddel ah lagama sameynin.',
  ),
  'notify.error.END_BEFORE_ASSIGNMENT_START': message(
    'The last day cannot be before the day the assignment started. Nothing was changed.',
    'Maalinta ugu dambeysa ma noqon karto mid ka hor maalinta qoondaynta bilaabatay. Wax isbeddel ah lagama sameynin.',
  ),
  'notify.error.EMPLOYEE_HAS_OPEN_ASSIGNMENTS': message(
    'This employee still has an open assignment or an active Temporary cover. End it first (or use Separation). Nothing was changed.',
    'Shaqaalahan wali wuxuu leeyahay qoondayn furan ama buuxin ku-meel-gaar ah oo shaqaynaysa. Marka hore soo afjar (ama isticmaal Kala-bax). Wax isbeddel ah lagama sameynin.',
  ),
});
