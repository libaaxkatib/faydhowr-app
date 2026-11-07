// Messages added for the ten owner decisions of 2026-11-06 (E-7 feedback is appended, E-8 team is optional, Q-STATUS quotation status, B-Q4 cancelling a
// fulfilled workforce request, TRANSLATION-POLICY proposals). Every message is `proposed`: written for this implementation, the Somali wording is for the
// owner to review. No existing message is changed or approved here: where wording had to change, a NEW key is used and the screen switched to it.

import { defineMessages, message } from '../catalog.ts';

export const ownerDecisions1c = defineMessages({
  // ---------------------------------------------------------------- B-Q4: cancel a FULFILLED workforce request
  'confirm.ownerDecisions1c.cancelFulfilledBody': message(
    'The request for {name} is already fulfilled. Cancelling it keeps the request, its confirmed matches and every employee record and assignment exactly as they are; only its status becomes Cancelled, and it cannot be opened again.',
    'Codsiga {name} horey ayaa loo dhammaystiray. Jebintiisu waxay u daynaysaa codsiga, isku-dhacyada la xaqiijiyey iyo diiwaan kasta iyo meelqorid kasta sida ay yihiin; xaaladdiisa keliya ayaa noqonaysa La jebiyey, mana la furi karo mar kale.',
  ),

  // ---------------------------------------------------------------- E-7: feedback is appended to the history, never overwritten
  'option.ownerDecisions1c.newFeedback': message('New feedback', 'Jawaab-celin cusub'),
  'warning.ownerDecisions1c.feedbackAppendHint': message(
    'Your text is added after the feedback already saved. The earlier feedback stays as it is.',
    'Qoraalkaaga waxaa lagu daraa jawaab-celintii hore loo kaydiyey. Jawaab-celintii hore sideeda ayay u hadhaysaa.',
  ),
  'option.ownerDecisions1c.feedbackSoFar': message('Feedback so far', 'Jawaab-celinta ilaa hadda'),

  // ---------------------------------------------------------------- E-8: a team is optional for a Marketing team member
  'option.ownerDecisions1c.teamOptional': message('No team (optional)', 'Kooxna ma jirto (ikhtiyaari)'),

  // ---------------------------------------------------------------- B-Q2: a Temporary employee may receive a Permanent assignment (the notice only says what stays the same)
  'warning.ownerDecisions1c.temporaryPermanentAssignment': message(
    'This employee is a Temporary employee. A Permanent assignment does not change the employee type, and no Permanent monthly payroll line is created while the employee stays Temporary: Temporary pay stays the daily rate (Permanent monthly salary ÷ 30) for the days covered. Changing the type is a separate HR action.',
    'Shaqaalahan waa Temporary. Meelqoridda Permanent ah ma beddesho nooca shaqaalaha, mana abuurto khad mushahar bille ah oo Permanent ah inta uu Temporary ahaado: mushaharka Temporary wuxuu ku sii jirayaa qiimaha maalinlaha ah (mushaharka bille ee Permanent ÷ 30) maalmaha lagu beddelay. Beddelidda nooca waa tallaabo HR oo gaar ah.',
  ),

  // ---------------------------------------------------------------- O-1: a Temporary cover may not share a day with a Replacement of the same position (proposed wording, not owner-approved yet)
  'warning.ownerDecisions1c.coverOverlapsReplacementHeading': message('This position is already held by a Replacement on some of these days', 'Jagadan waxaa hore u haysta Beddelaad (Replacement) maalmahan qaar ah'),
  'warning.ownerDecisions1c.coverOverlapsReplacement': message(
    'This position is already held by {record}. A position has one holder at a time. End or correct that Replacement first, or choose dates that do not overlap it.',
    'Jagadan waxaa hore u haysta {record}. Jagadu waxay leedahay hal qof markiiba. Marka hore soo gaabi ama sax Beddelaaddaas, ama dooro taariikho aan is-dulsaarin.',
  ),
  'warning.ownerDecisions1c.conflictRecordReplacement': message('Replacement #{id} at {where}, from {start} until {end}', 'Beddelaadda (Replacement) #{id} ee {where}, laga bilaabo {start} ilaa {end}'),

  // F-A (owner-approved 2026-11-07): a Replacement ends no later than the day before the original employee returns
  'warning.ownerDecisions1c.replacementPastReturn': message(
    'A Replacement must end no later than the day before the original employee returns. The return date is {returnDate}, so the latest last day is {latestEnd}. Nothing was changed.',
    'Beddelaadku (Replacement) waa inuu dhammaado ugu dambayn maalinta ka horreysa maalinta uu qofkii asalka ahaa soo laabto. Taariikhda soo laabashada waa {returnDate}, sidaa darteed maalinta ugu dambeysa ee la oggol yahay waa {latestEnd}. Waxba lama beddelin.',
  ),

  // A7 (existing owner rule: a day of a position is paid once): a Replacement may not hold days another Replacement of the same position already holds
  'warning.ownerDecisions1c.replacementOverlapsReplacement': message(
    'This Replacement cannot be saved: another Replacement of the same position already holds some of these days: {record}. A position has one holder each day and a day can be paid only once. End or correct the other Replacement first, or choose dates that do not overlap it. Nothing was changed.',
    'Beddelaaddan lama keydin karo: beddelaad kale oo isla jagadan ah ayaa hore u haysa maalmahan qaar: {record}. Jagadu waxay leedahay hal hayste maalin kasta, maalin kastana mar keliya ayaa la bixin karaa. Marka hore soo gaabi ama sax beddelaadka kale, ama dooro taariikho aan is-dulsaarin. Waxba lama beddelin.',
  ),

  // O-1, the other direction: a Replacement may not hold days a Temporary cover of the position already has (create / re-date / reopen / restore)
  'warning.ownerDecisions1c.replacementOverlapsCover': message(
    'This Replacement cannot be saved: the position already has a Temporary cover over some of these days: {record}. A position has one holder at a time. End or correct that cover first, or choose dates that do not overlap it. Nothing was changed.',
    'Beddelaaddan lama keydin karo: jagada waxaa hore u haysta beddelaad ku-meel-gaar ah maalmahan qaar ah: {record}. Jagadu waxay leedahay hal qof markiiba. Marka hore soo gaabi ama sax beddelaadkaas ku-meel-gaarka ah, ama dooro taariikho aan is-dulsaarin. Waxba lama beddelin.',
  ),

  // ---------------------------------------------------------------- TRANSLATION-POLICY: HR / Marketing Manager propose, only the Super Admin approves
  'warning.ownerDecisions1c.translationsProposeIntro': message(
    'You can propose a change to the wording of any message. A proposal does not change the wording in use: only a Super Admin can approve it.',
    'Waxaad soo jeedin kartaa beddelka qoraalka fariin kasta. Soo jeedintu ma beddesho qoraalka hadda la isticmaalayo: Super Admin keliya ayaa ansixin kara.',
  ),
  'action.ownerDecisions1c.proposeChange': message('Propose change', 'Soo jeedi beddel'),
  'option.ownerDecisions1c.proposeTitle': message('Propose new wording', 'Soo jeedi qoraal cusub'),
  'option.ownerDecisions1c.proposalReason': message('Why is this change needed? (optional)', 'Maxay beddelkani uga baahan yahay? (ikhtiyaari)'),
  'action.ownerDecisions1c.sendProposal': message('Send proposal', 'Dir soo jeedinta'),
  'validation.ownerDecisions1c.proposalNoChange': message('Change the English or the Somali wording to propose it.', 'Beddel qoraalka Ingiriisiga ama Soomaaliga si aad u soo jeedisid.'),
  'notify.ownerDecisions1c.proposalSent': message('Proposal sent. The wording in use has not changed.', 'Soo jeedinta waa la diray. Qoraalka hadda la isticmaalayo ma beddelmin.'),
  'notify.ownerDecisions1c.proposalFailed': message('The proposal could not be sent.', 'Soo jeedinta lama dirin karo.'),
  'notify.ownerDecisions1c.proposalAlreadyPending': message('You already have a pending proposal for this message. Withdraw it first to propose different wording.', 'Waxaad hore u haysaa soo jeedin sugaysa fariintan. Marka hore ka noqo si aad qoraal kale u soo jeedisid.'),
  'option.ownerDecisions1c.proposalsMine': message('My proposals', 'Soo jeedimahayga'),
  'option.ownerDecisions1c.proposalsAll': message('Proposals', 'Soo jeedimaha'),
  'warning.ownerDecisions1c.proposalsNone': message('No proposals yet.', 'Weli soo jeedin ma jirto.'),
  'status.ownerDecisions1c.proposalPending': message('Waiting for approval', 'Waxay sugaysaa ansixin'),
  'status.ownerDecisions1c.proposalApproved': message('Approved', 'La ansixiyey'),
  'status.ownerDecisions1c.proposalRejected': message('Rejected', 'La diiday'),
  'status.ownerDecisions1c.proposalWithdrawn': message('Withdrawn', 'La celiyey'),
  'action.ownerDecisions1c.approveProposal': message('Approve', 'Ansixi'),
  'action.ownerDecisions1c.rejectProposal': message('Reject', 'Diid'),
  'action.ownerDecisions1c.withdrawProposal': message('Withdraw', 'Ka noqo'),
  'option.ownerDecisions1c.rejectNote': message('Reason for rejecting (optional)', 'Sababta diidmada (ikhtiyaari)'),
  'option.ownerDecisions1c.proposedBy': message('Proposed by {name}', 'Waxaa soo jeediyey {name}'),
  'option.ownerDecisions1c.proposedWording': message('Proposed wording', 'Qoraalka la soo jeediyey'),
  'option.ownerDecisions1c.wordingInUse': message('Wording in use', 'Qoraalka hadda la isticmaalayo'),
  'option.ownerDecisions1c.translationLocked': message('Locked (owner-approved)', 'La xiray (mulkiilaha ayaa ansixiyey)'),
  'notify.ownerDecisions1c.proposalApproved': message('Proposal approved. The new wording is now in use.', 'Soo jeedinta waa la ansixiyey. Qoraalka cusub hadda waa la isticmaalayaa.'),
  'notify.ownerDecisions1c.proposalRejected': message('Proposal rejected. The wording in use is unchanged.', 'Soo jeedinta waa la diiday. Qoraalka hadda la isticmaalayo ma beddelmin.'),
  'notify.ownerDecisions1c.proposalWithdrawn': message('Proposal withdrawn.', 'Soo jeedinta waa la celiyey.'),
  'notify.ownerDecisions1c.proposalActionFailed': message('The proposal could not be updated. Refresh the page and try again.', 'Soo jeedinta lama cusboonaysiin karo. Cusboonaysii bogga oo isku day mar kale.'),
});
