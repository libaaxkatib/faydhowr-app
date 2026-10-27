// Pure helpers for the registration workflow screens (no React, no network, no `@/` imports) so they can be unit-tested by
// Node's native test runner (registrationWorkflow.test.ts). The BACKEND is the real guard (role, origin, status and records are
// re-checked on every request); these only give the user quick feedback and hide actions that would be refused.

import { tNow } from '../i18n/active.ts';

export const STAGE_MOVE_MIN_LENGTH = 10;
export const STAGE_MOVE_MAX_LENGTH = 500;

export type RegistrationOrigin = 'existing' | 'new' | 'office_staff';

/** The stages an existing person can be moved to. Active is deliberately not one of them. */
export const STAGE_MOVE_TARGETS = [
  { value: 'damiin_needed', label: 'Damiin Needed', labelKey: 'status.stage.damiin_needed', group: 'Pipeline' },
  { value: 'contract_pending', label: 'Contract Pending', labelKey: 'status.stage.contract_pending', group: 'Pipeline' },
  { value: 'uniform_pending', label: 'Uniform Pending', labelKey: 'status.stage.uniform_pending', group: 'Pipeline' },
  { value: 'need_training', label: 'Training (Need Training)', labelKey: 'status.stageMove.need_training', group: 'Pipeline' },
  { value: 'need_practical', label: 'Practice (Need Practical)', labelKey: 'status.stageMove.need_practical', group: 'Pipeline' },
  { value: 'practical_repeat', label: 'Practical Repeat', labelKey: 'status.stage.practical_repeat', group: 'Pipeline' },
  { value: 'waiting', label: 'Waiting List', labelKey: 'status.stageMove.waiting', group: 'Waiting' },
] as const;

export type StageMoveTarget = (typeof STAGE_MOVE_TARGETS)[number]['value'];

/** Same clean-up the server applies: surrounding and repeated whitespace do not count towards the length. */
export function normalizeStageReason(reason: string): string {
  return reason.replace(/\s+/g, ' ').trim();
}

export function stageMoveReasonError(reason: string): string | null {
  const text = normalizeStageReason(reason);
  if (text.length < STAGE_MOVE_MIN_LENGTH) return tNow('validation.employees.reasonMin', { min: STAGE_MOVE_MIN_LENGTH });
  if (text.length > STAGE_MOVE_MAX_LENGTH) return tNow('validation.employees.reasonMax', { max: STAGE_MOVE_MAX_LENGTH });
  return null;
}

/** Only the Super Admin and the HR Manager see the action (the server checks the role again). */
export function canMoveStageRole(role: string | undefined | null): boolean {
  return role === 'super_admin' || role === 'hr_manager';
}

/** The stage the person is in right now, in the same words as the targets (null when it is not one of them). */
export function currentStageValue(status: string, pipelineStage: string | null): string | null {
  if (status === 'waiting') return 'waiting';
  if (status === 'applicant' && pipelineStage) return pipelineStage;
  return null;
}

/**
 * Whether to offer "Move to stage" for this person: an existing record that is not working yet (Active / Inactive people use
 * separation and rehire), for a Super Admin or an HR Manager.
 */
export function canOfferStageMove(role: string | undefined | null, origin: RegistrationOrigin | undefined, status: string): boolean {
  return canMoveStageRole(role) && origin === 'existing' && status !== 'active' && status !== 'inactive';
}

export function stageMoveOptions(status: string, pipelineStage: string | null) {
  const current = currentStageValue(status, pipelineStage);
  return STAGE_MOVE_TARGETS.filter((target) => target.value !== current);
}

export function canSubmitStageMove(status: string, pipelineStage: string | null, target: string, reason: string): boolean {
  return stageMoveOptions(status, pipelineStage).some((option) => option.value === target) && stageMoveReasonError(reason) === null;
}

export interface WorkflowSummaryLike {
  applies: boolean;
  complete: boolean;
  override_recorded: boolean;
  missing: string[];
}

/** A NEW registration that has not completed the steps (and has no recorded override) cannot be advanced to Waiting / Active. */
export function advanceBlockedByWorkflow(workflow: WorkflowSummaryLike | undefined, nextStatus: string | undefined): boolean {
  if (!workflow || !nextStatus) return false;
  if (!['waiting', 'approved', 'active'].includes(nextStatus)) return false;
  return workflow.applies && !workflow.complete && !workflow.override_recorded;
}
