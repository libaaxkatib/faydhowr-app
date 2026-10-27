// The plain-language wording the web panel already shows for the server's error codes (utils/hrHelpers.ts), plus the shared HR questions.
// The server's own message text is not translated in this phase: for a code that has no entry here the screen shows the server's English message.
// Every Somali text here is `proposed` (written for this implementation; not yet reviewed by the owner).

import { defineMessages, message } from '../catalog.ts';

export const errors = defineMessages({
  'notify.error.LOCATION_COMPANY_MISMATCH': message(
    'That workplace belongs to a different company. Choose a workplace that belongs to the selected company.',
    'Goobtaas shaqada waxay iska leedahay shirkad kale. Dooro goob shaqo oo iska leh shirkadda la doortay.',
  ),
  'notify.error.OFFICE_ASSIGNMENT_HAS_NO_COMPANY': message(
    'The Fayadhowr Office is not a client company, so it cannot be linked to one. Choose the office on its own.',
    'Xafiiska Fayadhowr ma aha shirkad macmiil ah, sidaas darteed lama xiri karo shirkad. Dooro xafiiska keligiis.',
  ),
  'notify.error.EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY': message(
    'This employee is already working for this company. End the current assignment first if they are moving.',
    'Shaqaalahan hore ayuu ugu shaqeynayaa shirkadan. Haddii uu u wareegayo, marka hore soo afjar qoondaynta hadda jirta.',
  ),
  'notify.error.EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION': message(
    'This employee is already working at this workplace. End the current assignment first if they are moving.',
    'Shaqaalahan hore ayuu ugu shaqeynayaa goobtan shaqada. Haddii uu u wareegayo, marka hore soo afjar qoondaynta hadda jirta.',
  ),
  'notify.error.WORK_LOCATION_CAPACITY_EXCEEDED': message(
    'This workplace is already full. Choose another workplace, or ask a Super Admin to override the limit.',
    'Goobtan shaqada way buuxdaa. Dooro goob kale, ama weydii Super Admin inuu dhaafo xadka.',
  ),
  'notify.error.ASSIGNMENT_LOCATION_ALREADY_SET': message(
    'This assignment already has a workplace. Moving someone to a different workplace is a new assignment.',
    'Qoondaynta hore waxay leedahay goob shaqo. U wareejinta qof goob shaqo oo kale waa qoondayn cusub.',
  ),
  'notify.error.PAYROLL_PERIOD_LOCKED': message(
    "That month's payroll is already approved or finalized, so this cannot be changed. Ask for a payroll adjustment instead.",
    'Mushaharka bishaas horey ayaa loo ansixiyey ama loo dhammaystiray, sidaas darteed taas lama beddeli karo. Codso hagaajin mushahar.',
  ),
  'notify.error.SALARY_BEFORE_ASSIGNMENT_START': message(
    'The new salary cannot start before the assignment itself started. Choose a later effective date.',
    'Mushaharka cusub ma bilaaban karo ka hor bilowga qoondaynta. Dooro taariikh dambe oo uu ka bilaabmo.',
  ),
  'notify.error.REPLACEMENT_EMPLOYEE_NOT_TEMPORARY': message(
    'The person doing the cover must be a Temporary employee. Change their employment type first.',
    'Qofka bedelaya waa inuu ahaadaa shaqaale ku-meel-gaar ah. Marka hore beddel nooca shaqaaleysiintiisa.',
  ),
  'notify.error.COVERED_EMPLOYEE_NOT_PERMANENT': message(
    'The person being covered must be a Permanent employee.',
    'Qofka la bedelayo waa inuu ahaadaa shaqaale joogto ah.',
  ),
  'notify.error.EMPLOYEE_CANNOT_REPLACE_SELF': message('An employee cannot cover their own assignment.', 'Shaqaalaha ma bedeli karo qoondayntiisa.'),
  'notify.error.EMPLOYEE_NOT_ACTIVE': message(
    'Both employees must be Active. Make the employee Active first.',
    'Labada shaqaale waa inay ahaadaan Shaqeynaya. Marka hore ka dhig shaqaalaha Shaqeynaya.',
  ),
  'notify.error.REPLACEMENT_ALREADY_APPROVED': message('This replacement has already been approved.', 'Beddelaadkan horey ayaa loo ansixiyey.'),
  'notify.error.WORKDAY_IN_FUTURE': message('You cannot record a day that has not happened yet.', 'Ma diiwaangelin kartid maalin aan weli dhicin.'),
  'notify.error.WORKDAY_OUTSIDE_REPLACEMENT_WINDOW': message(
    'One of the days falls outside the dates this replacement covers.',
    'Mid ka mid ah maalmaha wuxuu ka baxsan yahay taariikhaha uu beddelaadkani daboolayo.',
  ),
  'notify.error.WORKDAY_OUTSIDE_ASSIGNMENT': message(
    'One of the days falls outside the covered assignment.',
    'Mid ka mid ah maalmaha wuxuu ka baxsan yahay qoondaynta la bedelayo.',
  ),
  'notify.error.WORKDAY_NO_PERMANENT_SALARY': message(
    'The covered employee had no salary on one of those days, so the day cannot be charged.',
    'Shaqaalaha la bedelay mushahar ma lahayn mid ka mid ah maalmahaas, sidaas darteed maalinta lama xisaabin karo.',
  ),
  'notify.error.WORKDAY_ALREADY_CHARGED_FOR_POSITION': message(
    'Another temporary employee is already charged for the same position on one of those days. A position is only charged once per day.',
    'Shaqaale ku-meel-gaar ah oo kale ayaa mid ka mid ah maalmahaas horey loogu xisaabiyey isla jagadaas. Jago waxaa maalintii mar keliya la xisaabiyaa.',
  ),
  'notify.error.EMPLOYEE_ALREADY_RECORDED_THAT_DAY': message(
    'This temporary employee already has a recorded day on one of those dates.',
    'Shaqaalahan ku-meel-gaarka ah horey ayaa maalin loogu diiwaangeliyey mid ka mid ah taariikhahaas.',
  ),
  'notify.error.PAYROLL_RUN_LOCKED': message(
    'This month is already approved or finalized and cannot be regenerated. Reopen it first if it is only approved.',
    'Bishan horey ayaa loo ansixiyey ama loo dhammaystiray, lamana dib u samayn karo. Haddii la ansixiyey oo keliya, marka hore dib u fur.',
  ),
  'notify.error.PAYROLL_RUN_EMPTY': message('There are no payroll lines to review for this month.', 'Bishan ma jiraan safaf mushahar oo dib loo eego.'),
  'notify.error.APPROVER_MUST_DIFFER_FROM_REVIEWER': message(
    'A different person must approve this payroll. The person who marked it reviewed cannot approve it.',
    'Qof kale ayaa waa inuu ansixiyaa mushaharkan. Qofka u calaamadiyey Dib u eegay ma ansixin karo.',
  ),
  'notify.error.PAYROLL_RUN_NOT_FINALIZED': message(
    'Adjustments and payments can only be recorded on finalized payroll.',
    'Hagaajinnada iyo lacag-bixinnada waxaa lagu diiwaangelin karaa oo keliya mushahar la dhammaystiray.',
  ),
  'notify.error.ADJUSTMENT_PERIOD_NOT_LATER': message(
    'An adjustment must apply in a later month than the one it corrects.',
    'Hagaajintu waa inay quseysaa bil ka dambeysa tan ay sixeyso.',
  ),
  'notify.error.ADJUSTMENT_PERIOD_LOCKED': message(
    'That later month is already approved or finalized. Choose a month after it.',
    'Bishaas dambe horey ayaa loo ansixiyey ama loo dhammaystiray. Dooro bil ka dambeysa.',
  ),
  'notify.error.PAYROLL_ITEM_ALREADY_REVERSED': message('This payroll line has already been reversed.', 'Safkan mushaharka horey ayaa loo rogay.'),
  'notify.error.PAYROLL_ITEM_ALREADY_PAID': message('This payroll line is already marked as paid.', 'Safkan mushaharka horey ayaa loogu calaamadiyey in la bixiyey.'),
  'notify.error.LEAVE_OVERLAPS_UNPAID_LEAVE': message(
    'This employee already has unpaid leave on some of those dates. Change the dates or reject the other leave first.',
    'Shaqaalahan fasax aan mushahar lahayn ayuu horey ugu leeyahay qaar ka mid ah taariikhahaas. Beddel taariikhaha ama marka hore diid fasaxa kale.',
  ),
  'notify.error.APPROVER_MUST_DIFFER_FROM_RECORDER': message(
    'A different person must approve this leave. The person who recorded it cannot approve it.',
    'Qof kale ayaa waa inuu ansixiyaa fasaxan. Qofka diiwaangeliyey ma ansixin karo.',
  ),
  'notify.error.SUCCESSION_OVERLAP': message(
    'That would pay two people for the same calendar day. The replacement must start the day after the last paid day of the previous employee.',
    'Taasi waxay laba qof u bixin lahayd isla maalin. Beddelaadku waa inuu bilaabmaa maalinta ka dambeysa maalinta ugu dambeysay ee shaqaalihii hore la bixiyey.',
  ),
  'notify.error.SUCCESSION_PREDECESSOR_STILL_OPEN': message(
    'Record the separation of the previous employee (or end their assignment) first, so their last paid day is known.',
    'Marka hore diiwaangeli ka tagista shaqaalihii hore (ama soo afjar qoondayntiisa), si maalinta ugu dambeysay ee la bixiyey loo ogaado.',
  ),
  'notify.error.SUCCESSION_ALREADY_REPLACED': message('Someone already takes over this assignment.', 'Qof horey ayaa qoondaynta kan la wareegay.'),
  'notify.error.SUCCESSION_DIFFERENT_COMPANY': message(
    'The assignment being replaced belongs to a different company.',
    'Qoondaynta la bedelayo waxay iska leedahay shirkad kale.',
  ),
  'notify.error.SUCCESSION_SAME_EMPLOYEE': message('An employee cannot take over their own assignment.', 'Shaqaalaha ma la wareegi karo qoondayntiisa.'),
  'notify.error.SUCCESSION_PREDECESSOR_CANCELLED': message('The assignment being replaced was cancelled.', 'Qoondaynta la bedelayo waa la joojiyey.'),
  'notify.error.LEAVE_ALREADY_APPROVED': message('This leave is already approved.', 'Fasaxan horey ayaa loo ansixiyey.'),
  'notify.error.LEAVE_ALREADY_REJECTED': message('This leave is already rejected.', 'Fasaxan horey ayaa loo diiday.'),

  // The question put to HR when the server asks for an explicit yes before a second company (used only when the server sent no wording of its own).
  'confirm.errors.additionalCompany': message(
    'This employee is already assigned to another company. Do you want to assign them to this company as well?',
    'Shaqaalahan horey ayaa loogu qoondeeyey shirkad kale. Ma rabtaa inaad shirkadan sidoo kale ugu qoondayso?',
  ),
});
