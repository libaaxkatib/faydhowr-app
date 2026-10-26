<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateTemporaryReplacementAction;
use App\Actions\Hr\EndTemporaryReplacementAction;
use App\Actions\Hr\ListTemporaryReplacementsAction;
use App\Actions\Hr\RecordTemporaryReplacementPaymentAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\EndTemporaryReplacementRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreTemporaryReplacementPaymentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreTemporaryReplacementRequest;
use App\Http\Resources\Api\V1\Admin\Hr\TemporaryReplacementResource;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\AdminPermissionResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TemporaryReplacementController extends Controller
{
    public function index(ListTemporaryReplacementsAction $action): JsonResponse
    {
        return ApiResponse::success('Temporary replacements retrieved successfully.', TemporaryReplacementResource::collection($action->handle()));
    }

    public function store(StoreTemporaryReplacementRequest $request, CreateTemporaryReplacementAction $action): JsonResponse
    {
        $workAssignment = EmployeeWorkAssignment::query()->findOrFail($request->validated('work_assignment_id'));
        $replacementEmployee = Employee::query()->findOrFail($request->validated('replacement_employee_id'));

        $replacement = $action->handle($workAssignment, $replacementEmployee, $request->safe()->except(['work_assignment_id', 'replacement_employee_id']), $request->user());

        return ApiResponse::success('Temporary replacement recorded successfully.', new TemporaryReplacementResource($replacement), 201);
    }

    public function end(EndTemporaryReplacementRequest $request, TemporaryReplacement $temporaryReplacement, EndTemporaryReplacementAction $action): JsonResponse
    {
        $replacement = $action->handle($temporaryReplacement, $request->validated('end_date'), $request->validated('notes'), $request->user());

        return ApiResponse::success('Temporary replacement coverage ended.', new TemporaryReplacementResource($replacement));
    }

    public function addPayment(
        StoreTemporaryReplacementPaymentRequest $request,
        TemporaryReplacement $temporaryReplacement,
        RecordTemporaryReplacementPaymentAction $action,
        AdminPermissionResolver $permissions,
    ): JsonResponse {
        $result = $action->record($temporaryReplacement, $request->validated(), $request->user());

        // Calculated amounts are cost information (hr.payroll.view). A payer without it still gets the payment recorded, with the calculation kept.
        $seesCost = $permissions->has($request->user(), AdminPermission::HrPayrollView->value, $request);

        return ApiResponse::success('Payment recorded successfully.', [
            ...(new TemporaryReplacementResource($result['replacement']))->resolve($request),
            'payment_check' => $seesCost ? [
                'payment_id' => $result['check']['payment_id'],
                'calculated_amount' => $result['check']['calculated_amount'],
                'entered_amount' => $result['check']['entered_amount'],
                'differs' => $result['check']['differs'],
                'difference' => $result['check']['difference'],
                'warning' => $result['check']['warning'],
                'cost_approved' => $result['check']['calculation']['approved'] ?? null,
            ] : null,
        ], 201);
    }
}
