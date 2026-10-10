<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ApproveTemporaryReplacementAction;
use App\Actions\Hr\RecordReplacementWorkdaysAction;
use App\Actions\Hr\ReviewReplacementWorkdayAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\RecordReplacementWorkdaysRequest;
use App\Http\Requests\Api\V1\Admin\Hr\VoidReplacementWorkdayRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ReplacementWorkdayResource;
use App\Http\Resources\Api\V1\Admin\Hr\TemporaryReplacementResource;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\ReplacementCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemporaryReplacementWorkdayController extends Controller
{
    public function index(TemporaryReplacement $temporaryReplacement): JsonResponse
    {
        return ApiResponse::success(
            'Replacement workdays retrieved successfully.',
            ReplacementWorkdayResource::collection($temporaryReplacement->workdays()->with(['recordedBy', 'verifiedBy'])->get()),
        );
    }

    public function store(RecordReplacementWorkdaysRequest $request, TemporaryReplacement $temporaryReplacement, RecordReplacementWorkdaysAction $action): JsonResponse
    {
        $workdays = $action->handle(
            $temporaryReplacement,
            $request->validated('dates'),
            (bool) $request->validated('verify', false),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::success('Replacement workdays recorded successfully.', ReplacementWorkdayResource::collection($workdays), 201);
    }

    public function verify(TemporaryReplacement $temporaryReplacement, TemporaryReplacementWorkday $workday, ReviewReplacementWorkdayAction $action, Request $request): JsonResponse
    {
        $this->assertBelongs($temporaryReplacement, $workday);

        return ApiResponse::success('Workday verified.', new ReplacementWorkdayResource($action->verify($workday, $request->user())));
    }

    public function void(VoidReplacementWorkdayRequest $request, TemporaryReplacement $temporaryReplacement, TemporaryReplacementWorkday $workday, ReviewReplacementWorkdayAction $action): JsonResponse
    {
        $this->assertBelongs($temporaryReplacement, $workday);

        return ApiResponse::success('Workday voided.', new ReplacementWorkdayResource($action->void($workday, $request->validated('reason'), $request->user())));
    }

    public function approve(TemporaryReplacement $temporaryReplacement, ApproveTemporaryReplacementAction $action, Request $request): JsonResponse
    {
        return ApiResponse::success('Temporary replacement approved.', new TemporaryReplacementResource($action->handle($temporaryReplacement, $request->user())));
    }

    /**
     * Live cost preview for one month from the verified workdays: the derived
     * daily rate, verified days and amount. Needs payroll visibility because
     * the rate reveals the covered employee's salary.
     */
    public function cost(Request $request, TemporaryReplacement $temporaryReplacement, ReplacementCostService $service): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $cost = $service->forPeriod($temporaryReplacement, $data['period']);

        return ApiResponse::success('Replacement cost calculated.', [
            ...$cost,
            'currency' => PayrollMoney::CURRENCY,
            'amount' => PayrollMoney::format($cost['amount_cents']),
            'approved' => $temporaryReplacement->approved_at !== null,
        ]);
    }

    private function assertBelongs(TemporaryReplacement $replacement, TemporaryReplacementWorkday $workday): void
    {
        abort_unless((int) $workday->temporary_replacement_id === (int) $replacement->id, 404);
    }
}
