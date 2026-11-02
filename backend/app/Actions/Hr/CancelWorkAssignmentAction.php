<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an assignment that was created by mistake, keeping the row and the
 * reason for history (assignments are never deleted). A cancelled assignment is
 * ignored by payroll, capacity counts and duplicate checks. It is refused once
 * the assignment has real consequences - payroll lines or Temporary
 * replacements - because then the right tool is to END it (employment really
 * happened) and correct money with payroll adjustments.
 */
class CancelWorkAssignmentAction
{
    public function handle(EmployeeWorkAssignment $assignment, string $reason, Admin $actor): EmployeeWorkAssignment
    {
        if ($assignment->status === WorkAssignmentStatus::Cancelled) {
            $this->fail('This assignment is already cancelled.', 'WORK_ASSIGNMENT_ALREADY_CANCELLED');
        }

        // A cover cancelled as a mistake also closes the absence it was recorded in (with zero days), so the holder is not left "away" with nobody.
        $coveredAbsence = AbsenceCycles::boundAbsence($assignment);

        if (PayrollItem::query()->where('work_assignment_id', $assignment->id)->exists()) {
            $this->fail('This assignment already appears in payroll; end it instead and correct pay with a payroll adjustment.', 'WORK_ASSIGNMENT_HAS_PAYROLL');
        }

        if (TemporaryReplacement::query()->where('work_assignment_id', $assignment->id)->exists()) {
            $this->fail('This assignment has Temporary replacement records; end it instead of cancelling.', 'WORK_ASSIGNMENT_HAS_REPLACEMENTS');
        }

        $wasUnresolved = UnresolvedTemporaryReplacements::isUnresolved($assignment);

        DB::transaction(function () use ($assignment, $reason, $coveredAbsence, $actor): void {
            $assignment->update([
                'status' => WorkAssignmentStatus::Cancelled,
                'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '').'Cancelled: '.$reason),
            ]);

            if ($coveredAbsence !== null) {
                AbsenceCycles::cancelCover($coveredAbsence, $assignment, $actor, $reason);
            }
        });

        event(AuditEvent::record(
            action: AuditAction::Cancel,
            admin: $actor,
            description: "Work assignment #{$assignment->id} cancelled.",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
            metadata: ['reason' => $reason],
        ));

        if ($wasUnresolved) {
            UnresolvedTemporaryReplacements::auditResolved($assignment, 'replacement_cancelled', $actor, ['reason' => $reason]);
        }

        return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position']);
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
