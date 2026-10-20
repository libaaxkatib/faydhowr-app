<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreatePayrollAdjustmentAction;
use App\Actions\Hr\GeneratePayrollRunAction;
use App\Actions\Hr\MarkPayrollItemPaidAction;
use App\Actions\Hr\TransitionPayrollRunAction;
use App\Actions\Hr\VoidPayrollPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\CreatePayrollAdjustmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\GeneratePayrollRunRequest;
use App\Http\Requests\Api\V1\Admin\Hr\MarkPayrollItemPaidRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ReopenPayrollRunRequest;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollItemResource;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollRunResource;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollRunSheetMapper;
use App\Support\Hr\Payroll\PayrollWorkbookExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollRunController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success('Payroll runs retrieved successfully.', PayrollRunResource::collection(PayrollRun::query()->orderByDesc('period')->get()));
    }

    public function show(PayrollRun $payrollRun): JsonResponse
    {
        $payrollRun->load(['items' => fn ($q) => $q->orderBy('company_name')->orderBy('employee_name'), 'items.deductions', 'items.paymentVoids', 'generatedBy', 'reviewedBy', 'approvedBy', 'finalizedBy']);

        return ApiResponse::success('Payroll run retrieved successfully.', new PayrollRunResource($payrollRun));
    }

    public function store(GeneratePayrollRunRequest $request, GeneratePayrollRunAction $action): JsonResponse
    {
        return ApiResponse::success('Payroll draft generated.', new PayrollRunResource($action->handle($request->validated('period'), $request->user())), 201);
    }

    public function review(PayrollRun $payrollRun, TransitionPayrollRunAction $action, Request $request): JsonResponse
    {
        return ApiResponse::success('Payroll marked reviewed.', new PayrollRunResource($action->review($payrollRun, $request->user())));
    }

    public function approve(PayrollRun $payrollRun, TransitionPayrollRunAction $action, Request $request): JsonResponse
    {
        return ApiResponse::success('Payroll approved.', new PayrollRunResource($action->approve($payrollRun, $request->user())));
    }

    public function reopen(ReopenPayrollRunRequest $request, PayrollRun $payrollRun, TransitionPayrollRunAction $action): JsonResponse
    {
        return ApiResponse::success('Payroll reopened to draft.', new PayrollRunResource($action->reopen($payrollRun, $request->validated('reason'), $request->user())));
    }

    public function finalize(PayrollRun $payrollRun, TransitionPayrollRunAction $action, Request $request): JsonResponse
    {
        return ApiResponse::success('Payroll finalized.', new PayrollRunResource($action->finalize($payrollRun, $request->user())));
    }

    public function export(PayrollRun $payrollRun, PayrollRunSheetMapper $mapper, PayrollWorkbookExporter $exporter): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'payroll').'.xlsx';
        $exporter->write($mapper->map($payrollRun), $path, 'Month-end payroll ('.ucfirst($payrollRun->status).')');

        $response = response()->download($path, "payroll-{$payrollRun->period}.xlsx")->deleteFileAfterSend(true);
        // File responses are public by default; this file lists everyone's pay, so it must be private and never stored.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function adjust(CreatePayrollAdjustmentRequest $request, PayrollItem $payrollItem, CreatePayrollAdjustmentAction $action): JsonResponse
    {
        $adjustment = $action->handle(
            $payrollItem,
            $request->validated('kind'),
            $request->validated('amount'),
            $request->validated('reason'),
            $request->validated('applies_in_period'),
            $request->user(),
        );

        return ApiResponse::success('Payroll adjustment recorded; it will apply in '.$adjustment->applies_in_period.'.', [
            'id' => $adjustment->id, 'kind' => $adjustment->kind, 'amount_cents' => $adjustment->amount_cents, 'applies_in_period' => $adjustment->applies_in_period, 'status' => $adjustment->status,
        ], 201);
    }

    public function pay(MarkPayrollItemPaidRequest $request, PayrollItem $payrollItem, MarkPayrollItemPaidAction $action): JsonResponse
    {
        return ApiResponse::success('Payroll line marked paid.', new PayrollItemResource($action->handle($payrollItem, $request->validated('payment_date'), $request->user())));
    }

    /** Voids a payment marked paid (Super Admin / HR Manager, reason required): a linked reversal is added, nothing is edited or deleted. */
    public function voidPayment(Request $request, PayrollItem $payrollItem, VoidPayrollPaymentAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $item = $action->handle($payrollItem, (string) $request->input('reason'), $request->user());

        return ApiResponse::success('Payment voided. A linked reversal was recorded; the line can be paid again.', new PayrollItemResource($item->load('paymentVoids')));
    }
}
