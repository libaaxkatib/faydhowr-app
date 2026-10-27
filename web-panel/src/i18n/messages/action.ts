// HR actions the owner approved (Batches 2A, 2B, 2E). The keys have no area segment: they are the shared vocabulary every HR screen reuses.
// One English label can have several keys when the same owner-approved action appears with a slightly different English wording
// (for example "Move to stage" and "Move to stage…").

import { approved, defineMessages } from '../catalog.ts';

export const action = defineMessages({
  // Employee record, status and stage
  'action.registerEmployee': approved('HR-ACT-001', 'Register Employee', 'Diiwaangeli Shaqaale'),
  'action.editEmployee': approved('HR-ACT-002', 'Edit Employee', 'Wax ka beddel Shaqaalaha'),
  'action.saveChanges': approved('HR-ACT-003', 'Save Changes', 'Keydi Isbeddellada'),
  'action.advanceTo': approved('HR-ACT-004', 'Advance to {status}', 'U gudub {status}', 'approved', 'The owner text wrote the variable as {Xaalad}; the variable name is kept as {status}.'),
  'action.moveToStage': approved('HR-ACT-005', 'Move to stage', 'U wareeji marxalad'),
  'action.moveToStageMore': approved('HR-ACT-005', 'Move to stage…', 'U wareeji marxalad…'),
  'action.addToSupervisorPool': approved('HR-ACT-006', 'Add to Supervisor Pool', 'Ku dar Kooxda Kormeerayaasha'),
  'action.removeFromSupervisorPool': approved('HR-ACT-007', 'Remove from Supervisor Pool', 'Ka saar Kooxda Kormeerayaasha'),
  'action.markInactive': approved('HR-ACT-008', 'Mark Inactive', 'Calaamadi inaanu shaqaynayn'),
  'action.rehire': approved('HR-ACT-009', 'Rehire', 'Dib u shaqaalaysii'),
  'action.overrideStatus': approved('HR-ACT-010', 'Override status', 'Beddel Xaaladda'),
  'action.overrideStatusMore': approved('HR-ACT-010', 'Override status…', 'Beddel Xaaladda…'),
  'action.restoreEmployee': approved('HR-ACT-011', 'Restore employee', 'Soo celi Shaqaalaha'),
  'action.restore': approved('HR-ACT-011', 'Restore', 'Soo celi Shaqaalaha'),

  // Damiin, contract, uniform
  'action.save': approved('HR-ACT-012', 'Save', 'Keydi'),
  'action.verify': approved('HR-ACT-013', 'Verify', 'Hubi'),
  'action.issueContract': approved('HR-ACT-014', 'Issue Contract', 'Soo saar Hishiis'),
  'action.markSigned': approved('HR-ACT-015', 'Mark Signed', 'Calaamadi in la saxiixay'),
  'action.markPurchased': approved('HR-ACT-016', 'Mark Purchased', 'Calaamadi in la iibsaday'),
  'action.markReceived': approved('HR-ACT-017', 'Mark Received', 'Calaamadi in la helay'),
  'action.confirmUniform': approved('HR-ACT-018', 'Confirm Uniform', 'Xaqiiji Uniformka'),

  // Practical decision
  'action.decisionApproved': approved('HR-ACT-019', 'Approved', 'La Ansixiyay'),
  'action.decisionKuCelis': approved('HR-ACT-020', 'Ku Celis Practical', 'KU celis Baraatiko'),
  'action.decisionRejected': approved('HR-ACT-021', 'Rejected', 'La Kansalay'),

  // Batches
  'action.createTrainingBatch': approved('HR-ACT-022', 'Create Training Batch', 'Samee Koox Tababar'),
  'action.createPracticalBatch': approved('HR-ACT-023', 'Create Practical Batch', 'Samee Koox Baraatiko'),
  'action.addParticipant': approved('HR-ACT-024', 'Add', 'Ku dar'),
  'action.absent': approved('HR-ACT-025', 'Absent', 'Maqan'),
  'action.markBatchCompleted': approved('HR-ACT-026', 'Mark Batch Completed', 'Calaamadi in Kooxdu Dhammaatay'),

  // Workforce requests
  'action.newWorkforceRequest': approved('HR-ACT-027', 'New Workforce Request', 'Codsi Shaqaale Cusub'),
  'action.viewCandidates': approved('HR-ACT-028', 'View Candidates', 'Eeg Musharaxiinta'),
  'action.confirmMatch': approved('HR-ACT-029', 'Confirm', 'Xaqiiji Doorashada'),
  'action.setWorkplace': approved('HR-ACT-030', 'Set workplace', 'Dooro Goobta Shaqada'),
  'action.cancelRequest': approved('HR-ACT-031', 'Cancel Request', 'Jooji Codsiga'),

  // Work assignments
  'action.assign': approved('HR-ACT-032', 'Assign', 'U qoondee Shirkad'),
  'action.assignBoth': approved('HR-ACT-033', 'Yes, assign to both', 'Haa, labadaba u qoondee'),
  'action.endAssignment': approved('HR-ACT-034', 'End Assignment', 'Soo Afjar Qoondaynta'),
  'action.addEmployee': approved('HR-ACT-035', 'Add employee', 'Ku dar shaqaale'),
  'action.replace': approved('HR-ACT-036a', 'Replace', 'Beddel'),
  'action.reviewReplacement': approved('HR-ACT-036b', 'Review', 'Dib u eeg Beddelka', 'approved', 'Approved for the Replace dialog; the same English "Review" in the Return dialog uses action.review.'),
  'action.confirmReplacement': approved('HR-ACT-036c', 'Confirm replacement', 'Xaqiiji Beddelka'),
  'action.returnOriginal': approved('HR-ACT-037a', 'Return original employee', 'Dib ugu celi shaqaalihii asalka ahaa'),
  'action.confirmReturn': approved('HR-ACT-037b', 'Confirm return', 'Xaqiiji Soo celinta'),
  'action.stop': approved('HR-ACT-038a', 'Stop', 'Jooji'),
  'action.continue': approved('HR-ACT-038b', 'Continue', 'Sii wad'),
  'action.stopYes': approved('HR-ACT-038c', 'Yes, stop this assignment', 'Haa, jooji qoondaynta'),

  // Leave and employee records
  'action.recordLeave': approved('HR-ACT-039', 'Record Leave', 'Diiwaangeli Fasax'),
  'action.approve': approved('HR-ACT-040', 'Approve', 'Ansixi'),
  'action.rejectLeave': approved('HR-ACT-041', 'Reject', 'Diid'),
  'action.addReview': approved('HR-ACT-042', 'Add Review', 'Ku dar Dib-u-eegis Waxqabad'),
  'action.recordPayment': approved('HR-ACT-043', 'Record Payment', 'Diiwaangeli Lacag-bixin'),
  'action.recordPenalty': approved('HR-ACT-044', 'Record Penalty', 'Diiwaangeli Ganaax'),
  'action.recordAdvance': approved('HR-ACT-045', 'Record Advance', 'Lacag hormaris', 'approved', 'Owner correction of the earlier draft.'),

  // Temporary replacement
  'action.newTemporaryReplacement': approved('HR-ACT-046', 'New Temporary Replacement', 'Beddelaad Ku-meel-gaar Cusub'),
  'action.recordDays': approved('HR-ACT-047', 'Record days', 'Diiwaangeli maalmaha'),
  'action.recordAndVerify': approved('HR-ACT-048', 'Record and verify', 'Diiwaangeli oo Hubi'),
  'action.verifyWorkday': approved('HR-ACT-049', 'Verify', 'Hubi'),
  'action.void': approved('HR-ACT-050a', 'Void', 'Ka noqo'),
  'action.voidDay': approved('HR-ACT-050b', 'Void day', 'Ka noqo'),
  'action.approveCost': approved('HR-ACT-051a', 'Approve cost', 'Ansixi Kharashka'),
  'action.approveReplacementCost': approved('HR-ACT-051b', 'Approve', 'Ansixi Kharashka'),
  'action.endCoverage': approved('HR-ACT-052', 'End Coverage', 'Soo Afjar Beddelaadka'),

  // Payroll
  'action.generateDraft': approved('HR-ACT-053', 'Generate / refresh draft', 'Samee / Cusboonaysii Qabyo'),
  'action.markReviewed': approved('HR-ACT-054', 'Mark reviewed', 'Calaamadi Dib loo eegay'),
  'action.approvePayrollButton': approved('HR-ACT-055a', 'Approve', 'Ansixi'),
  'action.approvePayroll': approved('HR-ACT-055b', 'Approve payroll', 'Ansixi Mushaharka'),
  'action.finalize': approved('HR-ACT-056a', 'Finalize', 'Dhammaystir'),
  'action.finalizePayroll': approved('HR-ACT-056b', 'Finalize payroll', 'Dhammaystir Mushaharka'),
  'action.reopen': approved('HR-ACT-057a', 'Reopen', 'Dib u fur'),
  'action.reopenPayroll': approved('HR-ACT-057b', 'Reopen payroll', 'Dib u fur'),
  'action.markPaid': approved('HR-ACT-058', 'Mark paid', 'Calaamadi in la bixiyey'),
  'action.markAsPaid': approved('HR-ACT-058', 'Mark as paid', 'Calaamadi in la bixiyey'),
  'action.voidPayment': approved('HR-ACT-059', 'Void payment', 'Ka noqo Lacag-bixinta'),
  'action.recordAdjustment': approved('HR-ACT-060', 'Record adjustment', 'Diiwaangeli Hagaajin'),

  // Excel imports
  'action.checkFile': approved('HR-ACT-061', 'Check file and show preview', 'Hubi faylka oo muuji horu-aragga'),
  'action.importRows.one': approved('HR-ACT-062a', 'Import {n} ready row', 'Soo geli Excel (safafka diyaarka ah: {n})', 'owner-term', 'Owner rule 2026-10-22: Excel imports use "Soo geli Excel"; the row count is kept. The count follows the noun, so one wording serves 1 and many rows.'),
  'action.importRows.other': approved('HR-ACT-062a', 'Import {n} ready rows', 'Soo geli Excel (safafka diyaarka ah: {n})', 'owner-term', 'Same wording as the singular form.'),
  'action.importYes': approved('HR-ACT-062b', 'Yes, import', 'Haa, soo geli'),
  'action.cancelImport': approved('HR-ACT-063', 'Cancel import', 'Jooji soo dejinta'),
  'action.continueImport': approved('HR-ACT-064', 'Continue importing', 'Sii wad soo dejinta'),

  // Common
  'action.cancel': approved('HR-ACT-065', 'Cancel', 'Jooji'),
  'action.back': approved('HR-ACT-067', 'Back', 'Dib u noqo'),
});
