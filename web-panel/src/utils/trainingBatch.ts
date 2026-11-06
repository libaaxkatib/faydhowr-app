// Pure helpers of the Training Batches screen (no React, no network, no `@/` imports) so Node's test runner can test them.

/**
 * What "Mark batch completed" does (CompleteTrainingBatchAction): every participant NOT ticked absent is marked completed and moves on to Need Practical;
 * the ones ticked absent are marked absent and stay in Need Training. There is no undo, so the screen says these two numbers before it asks to confirm.
 */
export function completionSummary(participants: readonly { employee_id: number }[], absentIds: ReadonlySet<number> | undefined): { go: number; absent: number } {
  const absent = participants.filter((participant) => absentIds?.has(participant.employee_id) === true).length;

  return { go: participants.length - absent, absent };
}

/** "Name (EMP-000123)": the number tells two people with the same name apart in the add-participant list. */
export function participantOptionLabel(employee: { full_name: string; employee_number: string }): string {
  return `${employee.full_name} (${employee.employee_number})`;
}

/** True when more people are in Need Training than the list shows, so the screen must say that searching finds the others. */
export function needTrainingTruncated(shown: number, total: number): boolean {
  return total > shown;
}
