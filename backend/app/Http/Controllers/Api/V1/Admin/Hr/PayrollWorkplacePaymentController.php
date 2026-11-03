<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordWorkplacePaymentAction;
use App\Actions\Hr\VoidWorkplacePaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreWorkplacePaymentRequest;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplacePayment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\WorkplacePayroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Payments per WORKPLACE for a payroll month (see WorkplacePayroll): each workplace's total, paid, outstanding balance, status and payment history,
 * and recording a full or partial payment for one workplace. Viewing needs hr.payroll.view; recording and voiding need hr.payroll.approve (the same
 * permission as marking a single line paid), and voiding is further limited to Super Admin / HR Manager in the action.
 */
class PayrollWorkplacePaymentController extends Controller
{
    public function index(PayrollRun $payrollRun): JsonResponse
    {
        return ApiResponse::success('Workplace payments retrieved successfully.', WorkplacePayroll::present($payrollRun, WorkplacePayroll::summaries($payrollRun)));
    }

    public function store(StoreWorkplacePaymentRequest $request, PayrollRun $payrollRun, RecordWorkplacePaymentAction $action): JsonResponse
    {
        $result = $action->handle(
            $payrollRun,
            $request->validated('client_company_id') === null ? null : (int) $request->validated('client_company_id'),
            $request->validated('work_location_id') === null ? null : (int) $request->validated('work_location_id'),
            $request->validated('mode'),
            $request->amountCents(),
            $request->validated('payment_date'),
            $request->validated('note'),
            $request->validated('idempotency_key'),
            $request->user(),
        );

        return ApiResponse::success(
            $result['replayed'] ? 'This payment was already recorded; nothing was added.' : 'Workplace payment recorded.',
            ['payment_id' => $result['payment']->id, 'replayed' => $result['replayed'], 'workplace' => WorkplacePayroll::presentOne($result['summary'])],
            $result['replayed'] ? 200 : 201,
        );
    }

    public function void(Request $request, PayrollWorkplacePayment $workplacePayment, VoidWorkplacePaymentAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        return ApiResponse::success('Payment voided. It no longer counts toward the workplace; the record is kept.', ['workplace' => WorkplacePayroll::presentOne($action->handle($workplacePayment, (string) $request->input('reason'), $request->user()))]);
    }
}
