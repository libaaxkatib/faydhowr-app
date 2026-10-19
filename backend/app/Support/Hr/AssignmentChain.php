<?php

namespace App\Support\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;
use Illuminate\Support\Collection;

/**
 * The assignments of one position over time: an assignment, the one it replaces (replaces_assignment_id), the one that replaced it, and
 * so on in both directions (Permanent -> Replacement -> returned Permanent). Cancelled assignments are not part of the chain.
 */
final class AssignmentChain
{
    /** @return Collection<int, EmployeeWorkAssignment> keyed by assignment id */
    public static function members(EmployeeWorkAssignment $assignment): Collection
    {
        $members = [$assignment->id => $assignment];
        $queue = [$assignment];

        while ($queue !== []) {
            $current = array_shift($queue);
            $linked = EmployeeWorkAssignment::query()
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->where(fn ($q) => $q->where('replaces_assignment_id', $current->id)
                    ->when($current->replaces_assignment_id !== null, fn ($q2) => $q2->orWhere('id', $current->replaces_assignment_id)))
                ->get();

            foreach ($linked as $next) {
                if (! isset($members[$next->id])) {
                    $members[$next->id] = $next;
                    $queue[] = $next;
                }
            }
        }

        return new Collection($members);
    }

    /** @return list<int> */
    public static function employeeIds(EmployeeWorkAssignment $assignment): array
    {
        return self::members($assignment)->pluck('employee_id')->unique()->values()->all();
    }

    /**
     * The monthly salary of the PERMANENT employee whose position this assignment takes over: walks back along replaces_assignment_id to
     * the nearest Permanent assignment and returns its salary row in effect on $date (but not later than that assignment's own last
     * day). Returns null when there is no Permanent assignment to inherit from.
     *
     * @return array{assignment_id: int, amount: string, currency: string, frequency: string, effective_from: string}|null
     */
    public static function inheritedSalary(EmployeeWorkAssignment $predecessor, \DateTimeInterface $date): ?array
    {
        $current = $predecessor;
        $guard = 0;

        while ($current !== null && $guard++ < 25) {
            if (($current->assignment_type?->value ?? 'permanent') === 'permanent') {
                $asOf = $current->end_date !== null && $current->end_date->lt($date) ? $current->end_date : $date;
                $rows = $current->salaryHistories()->get();
                $row = $rows->filter(fn ($r) => $r->effective_from->lte($asOf))->last() ?? $rows->first();

                return $row === null ? null : [
                    'assignment_id' => $current->id,
                    'amount' => (string) $row->amount,
                    'currency' => $row->currency,
                    'frequency' => $row->frequency->value,
                    'effective_from' => $row->effective_from->toDateString(),
                ];
            }

            $current = $current->replaces_assignment_id === null ? null : EmployeeWorkAssignment::query()->find($current->replaces_assignment_id);
        }

        return null;
    }
}
