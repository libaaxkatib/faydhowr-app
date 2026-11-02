<?php

namespace App\Support\Hr\Recovery;

/**
 * Plain-language explanations of the blocked-action errors an HR user meets, with the existing workflow that resolves each one.
 * Read-only: nothing here changes data, and the links only point at screens that already exist. Matching is by the API error code the screen showed,
 * or by a fragment of its message when only the text was copied.
 */
final class RecoveryErrorCatalogue
{
    /**
     * @return list<array{code: string, title: string, why: string, what_to_do: string, link: ?string, recovery_workflow: ?string, needs_super_admin: bool}>
     */
    public static function all(): array
    {
        $company = '/hr/companies';
        $payroll = '/hr/payroll';

        return [
            self::row('SUCCESSION_OVERLAP', 'A successor would overlap this assignment', 'Two people cannot hold the same position on the same day. The assignment that takes over starts before the previous one ends (or the reverse).', 'Move the end date of the earlier assignment, or the start date of the next, so they do not share a day. Inside one calendar month use the Recovery Center "Correct the start or end date" workflow.', $company, AssignmentRecovery::CORRECT_DATES),
            self::row('SUCCESSION_PREDECESSOR_STILL_OPEN', 'The previous assignment is still open', 'A new holder can only follow an assignment that has an end date.', 'End the previous assignment first (Stop / End assignment on the company page).', $company),
            self::row('SUCCESSION_ALREADY_REPLACED', 'This position already has a successor', 'Someone already took over this position, so it cannot be taken over a second time.', 'Retire or end the existing successor first, then continue.', $company),
            self::row('SUCCESSION_SAME_EMPLOYEE', 'Same employee on both sides', 'An employee cannot take over their own position.', 'Choose a different employee, or use Reopen if the same employee simply continues.', null, AssignmentRecovery::REOPEN),
            self::row('SUCCESSION_DIFFERENT_COMPANY', 'Different company', 'A successor must work at the same company as the assignment it follows.', 'Use the company the position belongs to, or create a new assignment instead.', $company),
            self::row('SUCCESSION_PREDECESSOR_CANCELLED', 'The previous assignment is cancelled', 'A cancelled assignment cannot have a successor.', 'Restore the cancelled assignment in the Recovery Center, or enter the person as a new assignment.', null, AssignmentRecovery::RESTORE),
            self::row('EMPLOYEE_NOT_ACTIVE', 'The employee is not active', 'Work cannot be added, reopened or restored for an employee whose status is not Active.', 'Open the employee and change the status to Active (or complete the hiring steps), then repeat.', '/hr/employees'),
            self::row('WORK_ASSIGNMENT_NOT_ACTIVE', 'The assignment is not active', 'The action needs an active assignment, but this one is ended or cancelled.', 'If it was ended or cancelled by mistake, use the Recovery Center to reopen or restore it.', null, AssignmentRecovery::REOPEN),
            self::row('WORK_ASSIGNMENT_ALREADY_CANCELLED', 'The assignment is already cancelled', 'It cannot be cancelled again.', 'If it should be active, restore it in the Recovery Center.', null, AssignmentRecovery::RESTORE),
            self::row('WORK_ASSIGNMENT_HAS_PAYROLL', 'The assignment already appears in payroll', 'An assignment that was paid for cannot be cancelled: it would erase history. End it instead.', 'End the assignment with its real last day. Pay differences are corrected with a payroll adjustment; in closed payroll the Super Admin payroll correction applies.', $payroll, null, true),
            self::row('WORK_ASSIGNMENT_HAS_REPLACEMENTS', 'Replacements are attached to this assignment', 'There are covers recorded for this assignment, so it cannot be removed.', 'Cancel or end the replacements first on the company page.', $company),
            self::row('USE_STOP_FOR_ABSENT_ASSIGNMENT', 'The holder is away: use Stop Employee', 'This assignment has an absence recorded, so a plain end date would leave the cycle inconsistent.', 'Use Stop Employee on the company page; to move the last working day or the return date use the Recovery Center "Correct a Replacement cycle".', $company, AssignmentRecovery::CORRECT_ABSENCE),
            self::row('USE_REPLACE_EMPLOYEE_FOR_ABSENT_ASSIGNMENT', 'The holder is away: use Replace Employee', 'The position has an open absence, so the next cover must be entered as a Replacement.', 'Use Replace Employee on the company page.', $company),
            self::row('USE_REPLACE_EMPLOYEE', 'Use Replace Employee', 'The change you tried must go through the Replace Employee workflow so pay and history stay correct.', 'Open the company page and use Replace Employee.', $company),
            self::row('RETURN_BEFORE_REPLACEMENT_STARTED', 'The return is before the cover started', 'The original holder cannot return before the Replacement began.', 'Pick a return date after the Replacement start, or correct the Replacement start date.', $company, AssignmentRecovery::CORRECT_ABSENCE),
            self::row('RETURN_ORIGINAL_TERMINATED', 'The original holder was already ended', 'Nobody can return to an assignment that was ended while its holder was away.', 'Enter the person again as a new assignment, or reopen the original if it was ended by mistake.', $company, AssignmentRecovery::REOPEN),
            self::row('REPLACEMENT_COVERS_AN_ABSENCE', 'This Replacement covers an absence', 'It is part of a Replacement cycle, so it is changed only through the cycle workflows.', 'Use Return Original Employee or Replace Employee; to fix a date in the same month use "Correct a Replacement cycle".', $company, AssignmentRecovery::CORRECT_ABSENCE),
            self::row('REPLACEMENT_BEFORE_ASSIGNMENT_START', 'The cover starts before the assignment does', 'A Replacement cannot start before the position it covers.', 'Correct the start date of the assignment (same month only) or enter the Replacement from the right day.', $company, AssignmentRecovery::CORRECT_DATES),
            self::row('START_DATE_PASSES_SALARY_CHANGE', 'The start date passes a salary change', 'Moving the start to or beyond a later salary change would remove the first salary row.', 'Correct the salary history first, then the start date.', $company),
            self::row('STOP_BEFORE_ASSIGNMENT_START', 'The end date is before the start', 'An assignment cannot end before it began.', 'Pick an end date on or after the start date; if the start date is wrong, correct it (same month) in the Recovery Center.', null, AssignmentRecovery::CORRECT_DATES),
            self::row('TERMINATION_BEFORE_LAST_WORKING_DAY', 'The end is before the last working day', 'The assignment cannot end before the last day the holder worked.', 'Use a date after the last working day, or correct that day in the Recovery Center.', null, AssignmentRecovery::CORRECT_ABSENCE),
            self::row('EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION', 'Already assigned at this workplace', 'The employee already holds an assignment here for those days.', 'Use the existing assignment, or end it first. If it was ended by mistake, reopen it.', $company, AssignmentRecovery::REOPEN),
            self::row('WORK_LOCATION_CAPACITY_EXCEEDED', 'The workplace is full', 'The number of positions of this workplace is already taken.', 'Raise the capacity of the workplace, or end an assignment first.', $company),
            self::row('PAYROLL_PERIOD_LOCKED', 'That payroll is approved or finalized', 'Approved and finalized payroll is never rewritten; the change you tried would alter it.', 'The Super Admin payroll correction records the difference as a correction (reason required) without rewriting the closed month. The Recovery Center only explains this; it never edits closed payroll.', $payroll, null, true),
            self::row('PAYROLL_RUN_LOCKED', 'The payroll run cannot be regenerated', 'Generate only works on a draft or reviewed payroll.', 'If the payroll is approved or finalized use the Super Admin payroll correction. If it is a draft, check the status on the Payroll page.', $payroll, null, true),
            self::row('PAYROLL_RUN_WRONG_STATE', 'The payroll is in a different state', 'The step you tried is not available in the payroll\'s current state (for example approving a draft that has not been reviewed).', 'Open the Payroll page and follow the next step shown for that month.', $payroll),
            self::row('PAYROLL_RUN_NOT_FINALIZED', 'The payroll is not finalized yet', 'Payments can only be recorded once the payroll is finalized.', 'Finish the review, approval and finalization on the Payroll page first.', $payroll),
            self::row('ADJUSTMENT_PERIOD_LOCKED', 'That month is already closed for adjustments', 'Adjustments cannot be added to an approved or finalized month.', 'Choose a later month for the adjustment.', $payroll),
            self::row('PAYROLL_UNRESOLVED_TEMPORARY_REPLACEMENT', 'A Temporary worker is registered as a Replacement', 'Payroll cannot be calculated until the classification is correct.', 'Correct the employee type or the assignment type on the company page, then regenerate the draft.', $company),
            self::row('TEMPORARY_REGISTERED_AS_REPLACEMENT', 'A Temporary worker is registered as a Replacement', 'Temporary workers are paid through the Temporary Replacements module.', 'Record the cover as a Temporary Replacement instead.', '/hr/temporary-replacements'),
        ];
    }

    /**
     * @return array{code: string, title: string, why: string, what_to_do: string, link: ?string, recovery_workflow: ?string, needs_super_admin: bool}
     */
    private static function row(string $code, string $title, string $why, string $whatToDo, ?string $link = null, ?string $workflow = null, bool $superAdmin = false): array
    {
        return ['code' => $code, 'title' => $title, 'why' => $why, 'what_to_do' => $whatToDo, 'link' => $link, 'recovery_workflow' => $workflow, 'needs_super_admin' => $superAdmin];
    }

    /**
     * Finds the entry for a pasted error code or message fragment. Returns null when nothing matches (the screen then says so honestly).
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $text): ?array
    {
        $needle = strtoupper(trim($text));

        if ($needle === '') {
            return null;
        }

        foreach (self::all() as $row) {
            if ($row['code'] === $needle) {
                return $row;
            }
        }

        $lower = mb_strtolower(trim($text));

        foreach (self::all() as $row) {
            $codeWords = str_replace('_', ' ', mb_strtolower($row['code']));

            if (str_contains($lower, $codeWords) || str_contains($lower, mb_strtolower($row['title']))) {
                return $row;
            }
        }

        return null;
    }
}
