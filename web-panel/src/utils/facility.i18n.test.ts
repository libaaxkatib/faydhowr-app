import test from 'node:test';
import assert from 'node:assert/strict';

import { setActiveLanguage, tValueNow } from '../i18n/active.ts';
import {
  blankComplaint,
  blankItems,
  complaintAnswerText,
  COMPLAINT_STATUS_LABELS,
  dueText,
  satisfactionText,
  validateComplaintSection,
  validateItems,
  validateUniformIssues,
  blankUniformIssue,
} from './facility.ts';

test('facility messages are English by default and Somali while an HR screen shows Somali', () => {
  assert.equal(COMPLAINT_STATUS_LABELS.open, 'New / open');
  assert.equal(validateItems(blankItems()).windows, 'Answer this item: satisfactory, unsatisfactory or not applicable.');
  try {
    setActiveLanguage('so');
    assert.equal(validateItems(blankItems()).windows, 'Ka jawaab shayggan: la aqbali karo, lama aqbali karo ama ma khuseyso.');
    assert.deepEqual(validateUniformIssues([blankUniformIssue()]), { 0: 'Dooro shaqaalaha.' });
    assert.equal(dueText('overdue', -1), '1 maalin ayuu dhaafay');
    assert.equal(dueText('overdue', -3), '3 maalmood ayuu dhaafay');
    assert.equal(dueText('due', 0), 'Waqtigiisu waa maanta');
    assert.equal(COMPLAINT_STATUS_LABELS.resolved, 'La xalliyey');
    assert.equal(tValueNow('option.facility.item', 'windows'), 'Daaqadaha');
    assert.equal(tValueNow('option.facility.state', 'overdue'), 'Waqtigii ka dhaafay');
    assert.equal(satisfactionText(null), 'Lama diiwaangelin');
    assert.equal(complaintAnswerText(true, 2, 'staff'), '2 cabashooyin shaqaale');
    assert.equal(complaintAnswerText(false, 0, 'customer'), 'Cabasho macmiil ma jirto');
    assert.equal(validateComplaintSection({ answer: 'unanswered', rows: [] }, 'customer').answer, 'Sheeg haddii ay jirtay cabasho macmiil.');
    const staff = { answer: 'yes' as const, rows: [blankComplaint('staff', '2026-10-10')] };
    assert.equal(validateComplaintSection(staff, 'staff')['0.employeeId'], 'Dooro shaqaalaha cabashada soo gudbiyey.');
  } finally {
    setActiveLanguage('en');
  }
  assert.equal(dueText('overdue', -3), '3 days overdue');
  assert.equal(tValueNow('option.facility.item', 'windows'), undefined);
});
