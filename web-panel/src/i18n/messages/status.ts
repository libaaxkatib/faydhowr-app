// Workflow statuses and pipeline stages. The stored values (`applicant`, `damiin_needed`, ...) never change: only the label shown for them does.
// `status.stage.*` are the labels of the pipeline stages keyed by their stored value; they repeat the sidebar wording (nav.*) on purpose and a
// test requires the two to stay identical.

import { approved, defineMessages } from '../catalog.ts';

export const status = defineMessages({
  // Employee status (EmployeeStatus). Recruitment, Practical and Approved are legacy values the current workflow skips.
  'status.applicant': approved('HR-STS-001', 'Applicant', 'Codsade'),
  'status.recruitment': approved('HR-STS-002', 'Recruitment', 'Qorista', 'approved', 'Legacy status; not verified against live data.'),
  'status.practical': approved('HR-STS-003', 'Practical', 'Baraatiko', 'approved', 'Legacy status; the owner prefers the term Baraatiko for Practical.'),
  'status.waiting': approved('HR-NAV-013', 'Waiting', 'Shaqa sugayaal', 'owner-term', 'Owner wording; the shared phrase for Waiting and Waiting List is still undecided.'),
  'status.approved': approved('HR-STS-004', 'Approved', 'La Ansixiyay', 'approved', 'Legacy status; not verified against live data.'),
  'status.active': approved('HR-STS-005', 'Active', 'Shaqeynaya'),
  'status.inactive': approved('HR-STS-006', 'Inactive', 'Aan Shaqeynayn'),

  // Pipeline stage (EmployeePipelineStage), keyed by the stored value.
  'status.stage.damiin_needed': approved('HR-NAV-005', 'Damiin Needed', 'Damiin Loo Baahan Yahay'),
  'status.stage.contract_pending': approved('HR-NAV-006', 'Contract Pending', 'Hishiiska Shaqaalaha', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stage.uniform_pending': approved('HR-NAV-007', 'Uniform Pending', 'Uniformka laga sugayo', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stage.need_training': approved('HR-NAV-008', 'Need Training', 'Ubaahan Tababar', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stage.need_practical': approved('HR-NAV-009', 'Need Practical', 'Ubaahan Baraatiko', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stage.practical_repeat': approved('HR-NAV-010', 'Practical Repeat', 'KU celis Baraatiko', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stage.rejected': approved('HR-NAV-011', 'Rejected', 'Dadka la kansalay', 'owner-term', 'Owner wording; its review is still open.'),

  // The "Move to stage" choices (their English wording differs from the sidebar's for two stages and for Waiting).
  'status.stageMove.need_training': approved('HR-STG-001', 'Training (Need Training)', 'Ubaahan Tababar', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stageMove.need_practical': approved('HR-STG-002', 'Practice (Need Practical)', 'Ubaahan Baraatiko', 'owner-term', 'Owner wording; its review is still open.'),
  'status.stageMove.waiting': approved(
    'HR-STG-003',
    'Waiting List',
    'Liiska Sugitaanka',
    'approved',
    'Differs from the owner wording for Waiting (Shaqa sugayaal); the shared phrase is still undecided.',
  ),
});
