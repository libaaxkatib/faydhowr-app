<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\PermanentSuccession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two - and only two - kinds of replacement HR/Management may choose when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1):
 * the Temporary employees who have been covering that very assignment, and people on the HR Waiting List (searchable, never listed in full). No
 * salary is returned here: a Temporary inherits the departing salary automatically and a Waiting List person gets the salary HR enters.
 */
class AssignmentSuccessorOptionsController extends Controller
{
    public function __invoke(Request $request, EmployeeWorkAssignment $workAssignment): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        $temporary = TemporaryReplacement::query()
            ->with('replacementEmployee:id,employee_number,full_name,status,employment_type')
            ->where('work_assignment_id', $workAssignment->id)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (TemporaryReplacement $c): bool => $c->replacementEmployee !== null
                && $c->replacementEmployee->employment_type === 'temporary'
                && $c->replacementEmployee->status === EmployeeStatus::Active)
            ->unique('replacement_employee_id')
            ->map(fn (TemporaryReplacement $c): array => [
                'employee_id' => $c->replacement_employee_id,
                'employee_number' => $c->replacementEmployee->employee_number,
                'name' => $c->replacementEmployee->full_name,
                'cover_id' => $c->id,
                'cover_start_date' => $c->start_date->toDateString(),
                'cover_end_date' => $c->end_date?->toDateString(),
            ])
            ->values();

        $waiting = Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->where('id', '!=', $workAssignment->employee_id)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->whereRaw('LOWER(full_name) LIKE ?', ['%'.$q.'%'])->orWhereRaw('LOWER(employee_number) LIKE ?', ['%'.$q.'%'])))
            ->orderBy('waiting_since')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'employee_number', 'full_name', 'waiting_since'])
            ->map(fn (Employee $e): array => ['employee_id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->full_name, 'waiting_since' => $e->waiting_since?->toDateString()]);

        $recorded = PermanentSuccession::recorded($workAssignment);

        return ApiResponse::success('Replacement options retrieved successfully.', [
            'assignment_id' => $workAssignment->id,
            'choice_required' => PermanentSuccession::isRequired($workAssignment),
            'recorded' => $recorded === null ? null : [
                'type' => $recorded->successor_type,
                'employee_id' => $recorded->successor_employee_id,
                'effective_date' => $recorded->successor_start_date->toDateString(),
            ],
            'temporary' => $temporary,
            'waiting_list' => $waiting,
        ]);
    }
}
