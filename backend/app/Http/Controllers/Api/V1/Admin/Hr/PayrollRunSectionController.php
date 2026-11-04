<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordCompanyPaymentAction;
use App\Actions\Hr\TransitionPayrollRunSectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\ReopenPayrollRunRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreCompanyPaymentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollRunResource;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review / reopen / finalize ONE company's section of a payroll month, and record its payment ("Approve Paid" / "Approve Partial"). Each action changes
 * only the section it names; the answer is the month (with its sections) so the screen shows every company's status at once. There is no Approve step:
 * a company is approved by being paid in full (PayrollApproval). Review needs hr.payroll.manage; reopen, finalize and payments need hr.payroll.approve.
 */
class PayrollRunSectionController extends Controller
{
    public function review(PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action, Request $request): JsonResponse
    {
        return $this->answer('Company payroll marked reviewed.', $action->review($payrollRunSection, $request->user()));
    }

    public function reopen(ReopenPayrollRunRequest $request, PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action): JsonResponse
    {
        return $this->answer('Company payroll reopened to draft.', $action->reopen($payrollRunSection, $request->validated('reason'), $request->user()));
    }

    public function finalize(PayrollRunSection $payrollRunSection, TransitionPayrollRunSectionAction $action, Request $request): JsonResponse
    {
        return $this->answer('Company payroll finalized.', $action->finalize($payrollRunSection, $request->user()));
    }

    /** Approve Paid (the whole outstanding balance) or Approve Partial (an amount) for this company. */
    public function pay(StoreCompanyPaymentRequest $request, PayrollRunSection $payrollRunSection, RecordCompanyPaymentAction $action): JsonResponse
    {
        $result = $action->handle(
            $payrollRunSection,
            $request->validated('mode'),
            $request->amountCents(),
            $request->validated('payment_date'),
            $request->validated('note'),
            $request->validated('idempotency_key'),
            $request->user(),
        );

        $run = PayrollRun::query()->findOrFail($payrollRunSection->payroll_run_id);
        $sections = PayrollApproval::sections($run);
        $company = collect($sections)->firstWhere('section_id', $payrollRunSection->id);

        return ApiResponse::success(
            $result['replayed']
                ? 'This payment was already recorded; nothing was added.'
                : (($company['approved'] ?? false) ? 'Payment recorded. The company is fully paid and approved.' : 'Partial payment recorded. The company is not approved until it is fully paid.'),
            [
                'replayed' => $result['replayed'],
                'payment_ids' => array_map(fn ($p): int => $p->id, $result['payments']),
                'company' => $company,
                'month' => PayrollApproval::month($run, $sections),
            ],
            $result['replayed'] ? 200 : 201,
        );
    }

    private function answer(string $message, PayrollRunSection $section): JsonResponse
    {
        $run = PayrollRun::query()->with(['sections.clientCompany', 'sections.reviewedBy', 'sections.approvedBy', 'sections.finalizedBy'])->findOrFail($section->payroll_run_id);

        return ApiResponse::success($message, new PayrollRunResource($run));
    }
}
