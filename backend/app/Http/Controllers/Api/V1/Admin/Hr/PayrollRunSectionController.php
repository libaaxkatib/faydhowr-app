<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\TransitionPayrollRunSectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\ReopenPayrollRunRequest;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollRunResource;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review / approve / reopen / finalize ONE company's section of a payroll month. Each action changes only the section it names; the answer is the
 * month (with its sections) so the screen shows every company's status at once. Review needs hr.payroll.manage; approve, reopen and finalize need
 * hr.payroll.approve (the same permissions as the month-level actions).
 */
class PayrollRunSectionController extends Controller
{
    public function review(PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action, Request $request): JsonResponse
    {
        return $this->answer('Company payroll marked reviewed.', $action->review($payrollRunSection, $request->user()));
    }

    public function approve(PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action, Request $request): JsonResponse
    {
        return $this->answer('Company payroll approved.', $action->approve($payrollRunSection, $request->user()));
    }

    public function reopen(ReopenPayrollRunRequest $request, PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action): JsonResponse
    {
        return $this->answer('Company payroll reopened to draft.', $action->reopen($payrollRunSection, $request->validated('reason'), $request->user()));
    }

    public function finalize(PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action, Request $request): JsonResponse
    {
        return $this->answer('Company payroll finalized.', $action->finalize($payrollRunSection, $request->user()));
    }

    private function answer(string $message, PayrollRunSection $section): JsonResponse
    {
        $run = PayrollRun::query()->with(['sections.clientCompany', 'sections.reviewedBy', 'sections.approvedBy', 'sections.finalizedBy'])->findOrFail($section->payroll_run_id);

        return ApiResponse::success($message, new PayrollRunResource($run));
    }
}
