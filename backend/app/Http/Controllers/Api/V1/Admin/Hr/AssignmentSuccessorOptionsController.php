<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\PermanentSuccession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What HR/Management may choose when a Permanent assignment is removed (docs/HR_PAYROLL_RULES.md N1, amended 2026-11-02): the Temporary employees who
 * have been covering that very assignment, or ANY other person in the HR system, found by name or employee number (never listed in full). The Waiting
 * List is still returned (`waiting_list`) as a convenient shortlist but is no longer a requirement. No salary is returned here: a Temporary inherits the
 * departing salary automatically and any other person gets the salary HR enters.
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

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $matches = fn ($query) => $query->where(fn ($w) => $w->whereRaw("LOWER(full_name) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(employee_number) LIKE ? ESCAPE '\\'", [$like]));

        $waiting = Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->where('id', '!=', $workAssignment->employee_id)
            ->when($q !== '', $matches)
            ->orderBy('waiting_since')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'employee_number', 'full_name', 'waiting_since'])
            ->map(fn (Employee $e): array => ['employee_id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->full_name, 'waiting_since' => $e->waiting_since?->toDateString()]);

        // ANY person in the HR system, found by name or employee number (owner decision 2026-11-02: the Waiting List is not required). Nothing is listed
        // without a search term, so the whole register is never dumped. Each result carries what tells people with similar names apart.
        $people = $q === '' ? collect() : Employee::query()
            ->with(['category:id,name'])
            ->where('id', '!=', $workAssignment->employee_id)
            ->where($matches)
            ->orderByRaw('LOWER(full_name)')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $current = EmployeeWorkAssignment::query()
            ->with(['clientCompany:id,name', 'workLocation.clientCompany:id,name'])
            ->whereIn('employee_id', $people->pluck('id')->all() ?: [0])
            ->where('status', WorkAssignmentStatus::Active)
            ->get()
            ->groupBy('employee_id');

        $people = $people->map(fn (Employee $e): array => [
            'employee_id' => $e->id,
            'employee_number' => $e->employee_number,
            'name' => $e->full_name,
            'status' => $e->status->value,
            'employment_type' => $e->employment_type ?? 'permanent',
            'category' => $e->category?->name,
            // The last three characters only: enough to tell two people with the same name apart, never the whole number.
            'phone_hint' => $e->phone === null || $e->phone === '' ? null : '...'.substr((string) $e->phone, -3),
            'current_assignments' => ($current->get($e->id) ?? collect())->map(fn (EmployeeWorkAssignment $a): string => ($a->clientCompany ?? $a->workLocation?->clientCompany)?->name ?? '-')->unique()->values()->all(),
            'waiting_since' => $e->waiting_since?->toDateString(),
        ])->values();

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
            'people' => $people,
        ]);
    }
}
