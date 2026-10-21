<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\SetHrPolicyAction;
use App\Enums\WorkAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = $request->user()->role;

        $rows = HrPolicy::query()->with('changedBy')->get()->keyBy('key');

        $data = collect(HrPolicy::CATALOG)->map(function (array $meta, string $key) use ($rows, $role): array {
            $row = $rows->get($key);

            return [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => (bool) $row?->enabled,
                'changed_by' => $row?->changedBy?->full_name,
                'changed_at' => $row?->updated_at?->toIso8601String(),
                'reason' => $row?->reason,
                // Lets the panel show the switch only to roles that may use it; the server still re-checks every change.
                'can_change' => HrPolicy::roleMayChange($key, $role),
            ];
        })->values();

        return ApiResponse::success('HR policies retrieved successfully.', [
            'policies' => $data,
            // Informational: employees who hold more than one open assignment now (switching the policy off never touches them).
            'employees_with_concurrent_assignments' => EmployeeWorkAssignment::query()
                ->where('status', WorkAssignmentStatus::Active)->select('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->get()->count(),
        ]);
    }

    public function update(Request $request, string $key, SetHrPolicyAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), ['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $policy = $action->handle($request->user(), $key, $request->boolean('enabled'), (string) $request->input('reason'));

        return ApiResponse::success('HR policy updated.', ['key' => $policy->key, 'enabled' => $policy->enabled]);
    }
}
