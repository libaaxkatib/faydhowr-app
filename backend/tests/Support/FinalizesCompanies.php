<?php

namespace Tests\Support;

use App\Models\PayrollRun;
use App\Models\PayrollRunSection;

/**
 * Test helper: takes a month all the way to Finalized the only way the application allows it now - company by company. (There is no month-level
 * Finalize.) The month then finalizes itself when the last company is finalized.
 */
trait FinalizesCompanies
{
    /**
     * Reviews, approves and finalizes EVERY company section of the run, each through its own company-level endpoint.
     *
     * @param  string  $token  an admin token allowed to review and approve payroll
     */
    protected function finalizeEveryCompany(int $runId, string $token): PayrollRun
    {
        foreach (PayrollRunSection::query()->where('payroll_run_id', $runId)->orderBy('id')->get() as $section) {
            foreach (['review', 'approve', 'finalize'] as $step) {
                if ($step === 'review' && $section->fresh()->status !== PayrollRunSection::STATUS_DRAFT) {
                    continue;
                }

                if ($step === 'approve' && $section->fresh()->status !== PayrollRunSection::STATUS_REVIEWED) {
                    continue;
                }

                $this->app['auth']->forgetGuards();
                $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/{$step}")->assertOk();
            }
        }

        return PayrollRun::query()->findOrFail($runId);
    }
}
