// Messages of the Replacement cycles (2026-11-01): the original assignment stays open while its holder is away, ending it while a Replacement works needs a
// confirmation, the last-working-day validation, and the in-place Activate of the employee picker. Every message is `proposed` (written for this
// implementation, not reviewed by a native speaker); Super Admin can reword any of them in Translations. None of the 161 owner-approved messages is touched.

import { defineMessages, message } from '../catalog.ts';

export const replacementCycles = defineMessages({
  // ---------------------------------------------------------------- validation
  'validation.company.lastDayBeforeAssignmentStart': message(
    'The last working day cannot be before the assignment started on {date}.',
    'Maalinta ugu dambeysay ee shaqada ma noqon karto mid ka hor bilowga qoondaynta, {date}.',
  ),

  // ---------------------------------------------------------------- the company list
  'option.company.awayFrom': message('Away since {date}', 'Maqan tan iyo {date}'),
  'option.company.awayCovered': message('A Replacement works the position. This assignment stays open.', 'Beddelaad ayaa booska shaqeynaya. Qoondaynta way furan tahay.'),
  'option.company.awayEnded': message('Ended on {date} while away; the Replacement carries on.', 'Waxay dhammaatay {date} iyadoo uu maqnaa; Beddelaadku wuu sii shaqeynayaa.'),

  // ---------------------------------------------------------------- ending an assignment whose holder is away
  'warning.company.awayStop': message(
    'This employee has been away since {date} and a Replacement is working at the position. Ending this assignment does NOT end the Replacement: they keep working and are paid on the salary of this assignment, and the position will have no Permanent holder until you decide (Replace Employee, or make the Replacement Permanent).',
    'Shaqaalahan wuu maqan yahay tan iyo {date} oo Beddelaad ayaa booska ka shaqeynaya. Soo afjarista qoondaynta MA soo afjarayso Beddelaadka: wuu sii shaqeynayaa oo waxaa lagu bixinayaa mushaharka qoondaynta, boosku na ma yeelan doono shaqaale Joogto ah ilaa aad go\'aan gaarto (Beddel Shaqaale, ama Beddelaadka ka dhig mid Joogto ah).',
  ),
  'confirm.company.awayStopConfirm': message('I understand. End only this assignment; the Replacement keeps working.', 'Waan fahmay. Soo afjar qoondaynta oo kaliya; Beddelaadku wuu sii shaqeynayaa.'),
  'validation.company.awayStopConfirm': message('Confirm that the Replacement keeps working before you continue.', 'Xaqiiji in Beddelaadku sii shaqeynayo ka hor intaadan sii wadin.'),
  'confirm.company.stopSummaryReplacementContinues': message('The Replacement keeps working', 'Beddelaadku wuu sii shaqeynayaa'),

  // ---------------------------------------------------------------- history of cycles
  'option.company.cycles': message('Absences and Replacements', 'Maqnaanshaha iyo Beddelaadyada'),
  'option.company.cycleSummary': message('Assignment #{id}: away from {from}', 'Qoondaynta #{id}: maqan tan iyo {from}'),
  'option.company.cycleBack': message('back on {date}', 'dib ayuu u soo laabtay {date}'),
  'option.company.cycleStillAway': message('still away', 'weli wuu maqan yahay'),
  'option.company.cycleEnded': message('assignment ended on {date}', 'qoondaynta waxay dhammaatay {date}'),
  'option.company.cycleEvent.absence_started': message('Last working day', 'Maalinta ugu dambeysay ee shaqada'),
  'option.company.cycleEvent.replacement_started': message('Replacement started', 'Beddelaadku wuu bilaabmay'),
  'option.company.cycleEvent.replacement_ended': message('Replacement ended', 'Beddelaadku wuu dhammaaday'),
  'option.company.cycleEvent.returned': message('Returned to the same assignment', 'Wuxuu ku soo laabtay isla qoondaynta'),
  'option.company.cycleEvent.original_terminated': message('Assignment ended while away', 'Qoondaynta waxay dhammaatay isagoo maqan'),
  'option.company.cycleEvent.cover_cancelled': message('Cover cancelled (entered by mistake)', 'Beddelaadka waa la joojiyey (wuxuu ahaa khalad)'),
  'option.company.cycleCancelled': message('last cover cancelled; the absence was closed', 'Beddelaadkii ugu dambeeyay waa la joojiyey; maqnaanshaha waa la xidhay'),

  // ---------------------------------------------------------------- Return when the original assignment is still open (the new cycle model)
  'confirm.company.returnFooterNoteSame': message(
    "The original employee returns to the SAME assignment, with the salary you confirm above. No new Permanent assignment is created. The Replacement's assignment ends the day before the return date and everything is kept in the history.",
    'Shaqaalihii asalka ahaa wuxuu ku soo laabanayaa isla qoondaynta, mushaharka aad kor ku xaqiijisay. Qoondayn Joogto ah oo cusub lama abuurayo. Qoondaynta Beddelaadku waxay dhammaanaysaa maalinta ka horeysa taariikhda soo noqoshada, wax walbana taariikhda ayay ku hadhayaan.',
  ),
  'confirm.company.returnsToSameAssignment': message('returns to assignment #{id} on {date}', 'wuxuu ku soo laabanayaa qoondaynta #{id} {date}'),

  // ---------------------------------------------------------------- the employee picker: Activate in place
  'action.company.activate': message('Activate', 'Hawlgeli'),
  'action.company.activateConfirm': message('Yes, activate', 'Haa, hawlgeli'),
  'confirm.company.activateTitle': message('Activate {name}?', 'Hawlgeli {name}?'),
  'confirm.company.activateBodyStatus': message(
    'This changes the HR status of {name} to Active, the same step as on the employee record. Nothing else changes, and what you typed in this form is kept.',
    'Tani waxay xaaladda HR ee {name} u beddelaysaa Shaqeynaya, isla tallaabadii diiwaanka shaqaalaha. Wax kale ma beddelmaan, wixii aad foomkan ku qortayna waa la ilaaliyaa.',
  ),
  'confirm.company.activateBodyRehire': message(
    'This rehires {name}: the separated employee becomes Active again, the same step as Rehire on the employee record. Nothing else changes, and what you typed in this form is kept.',
    'Tani waxay dib u shaqaalaysiinaysaa {name}: shaqaalihii laga fogeeyay wuxuu mar kale noqonayaa Shaqeynaya, isla tallaabadii Dib u shaqaalaysii ee diiwaanka shaqaalaha. Wax kale ma beddelmaan, wixii aad foomkan ku qortayna waa la ilaaliyaa.',
  ),
  'warning.company.rehireNotRecommended': message('HR marked this person as not recommended for rehire when they were separated.', 'HR waxay shaqaalahan ku calaamadisay in aan dib loo shaqaalaysiin lahayn markii laga fogeeyay.'),
  'notify.company.employeeActivated': message('{name} is now Active and can be chosen.', '{name} hadda waa Shaqeynaya oo waa la dooran karaa.'),
  'notify.company.couldNotActivate': message('Could not activate this employee.', 'Shaqaalahan lama hawlgelin karin.'),
});
