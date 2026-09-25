<?php

namespace App\Actions\Hr;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\WorkforceRequest;
use Illuminate\Support\Collection;

/**
 * "Candidate recommendations" + "Waiting priority" (docs/HRM_MARKETING_SRS.md
 * HR Phase 2), implemented as a deterministic filter + sort - not a numeric
 * score, per the roadmap's explicit "do not invent numeric scoring" caution.
 * Every Waiting employee is returned (matching criteria are advisory only,
 * per the confirmed Phase 2 scope), annotated with match flags, oldest
 * waiting_since first. Employees already matched to THIS request are
 * excluded (they're already shown via the request's own `matches`).
 */
class GetWorkforceRequestCandidatesAction
{
    public function handle(WorkforceRequest $request): Collection
    {
        $alreadyMatchedEmployeeIds = $request->matches()->pluck('employee_id');

        return Employee::query()
            ->with(['category', 'position'])
            ->where('status', EmployeeStatus::Waiting)
            ->whereNotIn('id', $alreadyMatchedEmployeeIds)
            ->orderByRaw('waiting_since IS NULL, waiting_since ASC')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'phone' => $employee->phone,
                'gender' => $employee->gender?->value,
                'location' => $employee->location,
                'employee_category_id' => $employee->employee_category_id,
                'employee_category_name' => $employee->category?->name,
                'position_id' => $employee->position_id,
                'position_name' => $employee->position?->name,
                'waiting_since' => $employee->waiting_since?->toIso8601String(),
                'gender_match' => $request->gender_requirement === null || $request->gender_requirement === $employee->gender,
                'category_match' => $request->employee_category_id === null || $request->employee_category_id === $employee->employee_category_id,
                'position_match' => $request->position_id === null || $request->position_id === $employee->position_id,
            ]);
    }
}
