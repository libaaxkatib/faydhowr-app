<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\BulkActivateEmployeesAction;
use App\Actions\Hr\ConfirmEmployeeGuarantorAction;
use App\Actions\Hr\UpdateEmployeeStatusAction;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\BulkActivateEmployeesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateEmployeeStatusRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeStatusController extends Controller
{
    public function update(UpdateEmployeeStatusRequest $request, Employee $employee, UpdateEmployeeStatusAction $action): JsonResponse
    {
        $employee = $action->handle(
            $employee,
            EmployeeStatus::from($request->validated('status')),
            $request->validated('note'),
            $request->user(),
            $request->validated('override_reason'),
        );

        return ApiResponse::success('Employee status updated successfully.', new EmployeeResource($employee));
    }

    /**
     * Moves an explicit list of approved employees to Active. Dry run by
     * default; a real run needs dry_run=false AND confirm=true. Each employee is
     * validated and applied separately and the response reports every result.
     */
    public function bulkActivate(BulkActivateEmployeesRequest $request, BulkActivateEmployeesAction $action): JsonResponse
    {
        $dryRun = $request->boolean('dry_run', true);
        $result = $action->handle(
            $request->validated('records'),
            $request->validated('note'),
            $request->validated('joining_date'),
            $dryRun,
            $request->user(),
            $request->validated('override_reason'),
        );

        return ApiResponse::success($dryRun ? 'Dry run completed; nothing was changed.' : 'Bulk activation completed.', $result);
    }

    public function confirmGuarantor(Employee $employee, ConfirmEmployeeGuarantorAction $action): JsonResponse
    {
        $employee = $action->handle($employee, request()->user());

        return ApiResponse::success('Guarantor confirmed successfully.', new EmployeeResource($employee));
    }
}
