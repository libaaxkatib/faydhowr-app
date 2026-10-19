<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\Position;
use App\Models\WorkforceRequest;
use App\Support\Hr\ReferenceUsage;
use Illuminate\Support\Facades\DB;

class DeletePositionAction
{
    /**
     * A position that anything still points at is never deleted (the foreign keys are ON DELETE SET NULL, so the database
     * would otherwise clear the reference silently). Archived employees and ended/cancelled assignments count: they are
     * history. The check and the delete run in one transaction behind a row lock on the position; on PostgreSQL a
     * concurrent insert that references the position takes a conflicting lock on that row, so it either finishes first
     * (and is counted here) or waits until this delete is over and then fails its own foreign-key check - it can never
     * be left blank.
     */
    public function handle(Position $position, Admin $actor): void
    {
        [$name, $id] = DB::transaction(function () use ($position): array {
            $locked = Position::query()->whereKey($position->getKey())->lockForUpdate()->firstOrFail();

            $employees = Employee::withTrashed()->where('position_id', $locked->id)->count();
            $archived = Employee::onlyTrashed()->where('position_id', $locked->id)->count();

            $employeePhrase = ReferenceUsage::phrase($employees, 'employee');
            if ($employeePhrase !== null && $archived > 0) {
                $employeePhrase .= " ({$archived} archived)";
            }

            $usedBy = ReferenceUsage::join([
                $employeePhrase,
                ReferenceUsage::phrase(EmployeeWorkAssignment::query()->where('position_id', $locked->id)->count(), 'work assignment'),
                ReferenceUsage::phrase(WorkforceRequest::query()->where('position_id', $locked->id)->count(), 'workforce request'),
            ]);

            if ($usedBy !== null) {
                ReferenceUsage::refuse('position', 'POSITION_IN_USE', $usedBy);
            }

            $name = $locked->name;
            $id = $locked->id;
            $locked->delete();

            return [$name, $id];
        });

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Position '{$name}' deleted.",
            entityType: Position::class,
            entityId: $id,
        ));
    }
}
