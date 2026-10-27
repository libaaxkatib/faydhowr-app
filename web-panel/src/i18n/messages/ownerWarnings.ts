// The two warnings whose Somali wording the owner supplied and approved (decisions of 2026-10-27, U1). They are NOT part of the 161 locked messages of
// the first review list: they have their own ids and their own pinned test (`ownerApprovedAdditions` in lockedApproved.test.ts). The Somali is also
// written in the backend (`EmploymentTypeChange::MESSAGE`, `CoverConflicts`); a test requires the three copies to stay identical.
// The English text is the faithful equivalent shown when English is chosen.

import { approved, defineMessages } from '../catalog.ts';

export const ownerWarnings = defineMessages({
  'warning.employees.typeChangeBlocked': approved(
    'OWN-U1-001',
    'This employee has a Permanent assignment that is still active. To avoid losing their payroll data, first stop the earlier assignment, assign another person to that position, and only then create the new Temporary assignment. The system does not change the earlier assignment automatically.',
    'Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon. Si looga fogaado in xogta mushaharkiisu lunto, marka hore jooji shaqadii hore, qof kale u qoondee booskaas, kadibna samee assignment-ka cusub ee Temporary. Nidaamku si toos ah uma beddelayo shaqada hore.',
  ),
  'warning.pipeline.coverOwnAssignmentOverlap': approved(
    'OWN-U1-002',
    'This employee is already in a Permanent job whose time overlaps this work. If you want to move them to this work as a Temporary, first stop/cancel the earlier assignment, then assign someone else to the earlier position. Different shifts are allowed if their times do not overlap.',
    'Shaqaalahaan wuxuu hore ugu jiraa shaqo Permanent ah oo waqtigeedu isku dhacayo shaqadan. Haddii aad rabto inaad shaqadan Temporary ahaan ugu wareejiso, marka hore jooji/cancel garee shaqadii hore, kadibna qof kale u qoondee booskii hore. Shift-yo kala duwan waa la oggol yahay haddii waqtigoodu isma saarna.',
  ),
});
