<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GenerateCompanyPayrollAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollRunResource;
use App\Models\ClientCompany;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\DeductionInputsFingerprint;
use App\Support\Hr\Payroll\GenerationWarnings;
use App\Support\Hr\Payroll\PayrollCompanyOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Company-level payroll for a month (owner decisions N-2, N-2a, N-2b, N-4, N-4a): Generate ONE company, the Temporary Replacement warnings of one company
 * (warnings only - never blocking), and the month's list of every assigned company (generated or not) with its state. There is no month-wide Generate.
 * {company} is a company id, or the word "none" for the lines whose assignment names no company.
 */
class PayrollCompanyController extends Controller
{
    /** Generate (or regenerate) the DRAFT payroll of one company. Needs hr.payroll.manage (route group). */
    public function generate(Request $request, string $period, string $company, GenerateCompanyPayrollAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), ['expected_status' => ['nullable', 'in:draft,reviewed']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $run = $action->handle($period, $this->companyId($company), $request->user(), $request->input('expected_status'));
        $run->loadCount(PayrollRunController::sectionCounts());
        $run->load(['generatedBy', 'sections.clientCompany', 'sections.reviewedBy', 'sections.approvedBy', 'sections.finalizedBy', 'sections.generatedBy']);

        return ApiResponse::success('Company payroll draft generated.', new PayrollRunResource($run), 201);
    }

    /**
     * The Temporary Replacement warnings of one company and month, for the screen to show BEFORE Generate and at Review / Finalize. Read-only; the answer
     * has no effect on whether any action is allowed (the warnings never block).
     */
    public function warnings(string $period, string $company): JsonResponse
    {
        $companyId = $this->companyId($company);
        $model = $companyId === null ? null : ClientCompany::query()->findOrFail($companyId);
        $run = PayrollRun::query()->where('period', $period)->first();
        $section = $run?->sections()->when($companyId === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $companyId))->first();

        return ApiResponse::success('Warnings retrieved successfully.', [
            'period' => $period,
            'client_company_id' => $companyId,
            'company_name' => $model?->name,
            'blocking' => false,
            'generated' => $section instanceof PayrollRunSection,
            'section_status' => $section?->status,
            // Release 1B: a penalty / advance changed after this company was generated. Unlike the Temporary warnings this one DOES stop Review / Finalize (PAYROLL_INPUTS_CHANGED, 409).
            'deduction_inputs_changed' => $section instanceof PayrollRunSection && ! $section->isLocked() && DeductionInputsFingerprint::changed($section, $period),
            'warnings' => GenerationWarnings::forDisplay($period, $companyId, $model?->name, $section?->generation_warnings ?? []),
        ]);
    }

    /** Every company assigned to the month, generated or not, with its state and amounts (the month's company-level report). */
    public function overview(string $period): JsonResponse
    {
        return ApiResponse::success('Payroll companies retrieved successfully.', PayrollCompanyOverview::forPeriod($period));
    }

    private function companyId(string $company): ?int
    {
        return $company === 'none' ? null : (int) $company;
    }
}
