<?php

namespace Tests\Support;

use App\Http\Controllers\Api\V1\Admin\Hr\PayrollRunController;
use App\Http\Resources\Api\V1\Admin\Hr\PayrollRunResource;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollCompanyOverview;
use Illuminate\Testing\TestResponse;

/**
 * TEST SUPPORT ONLY. Release 1A removed the month-wide Generate and the month-level bulk Review from the API (owner decisions N-2a, D-2): payroll is now
 * generated and reviewed one company at a time. A great many older tests only need "the month's payroll exists" or "every company is reviewed", so
 * TestCase::postJson / patchJson route the two retired requests (POST payroll-runs {period}, PATCH payroll-runs/{id}/review) here, where they are
 * performed as what a user would now do: the real per-company requests, one for each company, with the same login. The production API has no such route.
 */
trait GeneratesEveryCompany
{
    /** @param  array<string, string>  $headers */
    protected function generateEveryCompany(string $period, array $headers): TestResponse
    {
        $last = null;
        $generated = 0;

        foreach (PayrollCompanyOverview::forPeriod($period)['companies'] as $company) {
            if (in_array($company['section_status'], PayrollRunSection::LOCKED_STATUSES, true)) {
                continue; // a protected company keeps its stored payroll, exactly as the old month-wide Generate kept it
            }

            $key = $company['client_company_id'] ?? 'none';
            $response = parent::postJson("/api/v1/admin/hr/payroll/{$period}/companies/{$key}/generate", [], $headers);

            if ($response->getStatusCode() !== 201) {
                return $response;
            }

            $generated++;
            $last = $response;
        }

        if ($last !== null) {
            return $last;
        }

        // Nothing to generate: the permission check of the real route still applies (a user who may not generate gets the 403 they always got).
        $probe = parent::postJson("/api/v1/admin/hr/payroll/{$period}/companies/none/generate", [], $headers);

        if (in_array($probe->getStatusCode(), [401, 403], true)) {
            return $probe;
        }

        $run = PayrollRun::query()->where('period', $period)->first();

        // Everything is protected (or nothing is assigned): the old answer for a locked month, or an empty draft month.
        if ($run !== null && $run->sections()->exists()) {
            return TestResponse::fromBaseResponse(ApiResponse::error("Payroll for {$period} is {$run->status} and can no longer be regenerated.", 'PAYROLL_RUN_LOCKED', 422));
        }

        $run ??= PayrollRun::query()->create(['period' => $period, 'status' => PayrollRun::STATUS_DRAFT, 'currency' => 'USD']);

        return TestResponse::fromBaseResponse(ApiResponse::success('Payroll draft generated.', new PayrollRunResource($run->loadCount(PayrollRunController::sectionCounts())), 201));
    }

    /** @param  array<string, string>  $headers */
    protected function reviewEveryCompany(int $runId, array $headers): TestResponse
    {
        $last = null;

        foreach (PayrollRunSection::query()->where('payroll_run_id', $runId)->where('status', PayrollRunSection::STATUS_DRAFT)->orderBy('id')->get() as $section) {
            $response = parent::patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review", [], $headers);

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            $last = $response;
        }

        return $last ?? TestResponse::fromBaseResponse(ApiResponse::error('Payroll is not draft.', 'PAYROLL_RUN_WRONG_STATE', 422));
    }
}
