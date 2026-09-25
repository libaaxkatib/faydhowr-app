<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\MarkEmployeeSeparatedAction;
use App\Actions\Hr\RehireEmployeeAction;
use App\Actions\Hr\ToggleEmployeeSupervisorAction;
use App\Enums\EmployeeSeparationReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MarkEmployeeSeparatedRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RehireEmployeeRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ToggleEmployeeSupervisorRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeSeparationController extends Controller
{
    public function separate(MarkEmployeeSeparatedRequest $request, Employee $employee, MarkEmployeeSeparatedAction $action): JsonResponse
    {
        $employee = $action->handle(
            $employee,
            EmployeeSeparationReason::from($request->validated('reason')),
            $request->validated('separation_date'),
            $request->validated('rehire_eligible', true),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::success('Employee separated successfully.', new EmployeeResource($employee));
    }

    public function rehire(RehireEmployeeRequest $request, Employee $employee, RehireEmployeeAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated('note'), $request->user());

        return ApiResponse::success('Employee rehired successfully.', new EmployeeResource($employee));
    }

    public function supervisor(ToggleEmployeeSupervisorRequest $request, Employee $employee, ToggleEmployeeSupervisorAction $action): JsonResponse
    {
        $employee = $action->handle($employee, (bool) $request->validated('is_supervisor'), $request->user());

        return ApiResponse::success('Supervisor status updated successfully.', new EmployeeResource($employee));
    }
}
