<?php

namespace Tests\Support;

use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\Hr\Facility\FacilityCalendar;

/**
 * Test helper: takes a month all the way to Finalized the only way the application allows it now - company by company. (There is no month-level
 * Finalize, and no Approve step at all: a company is approved by being paid in full.) The month then finalizes itself when the last company is finalized.
 */
trait FinalizesCompanies
{
    /**
     * Reviews and finalizes EVERY company section of the run, each through its own company-level endpoint.
     *
     * @param  string  $token  an admin token allowed to review and finalize payroll
     */
    protected function finalizeEveryCompany(int $runId, string $token): PayrollRun
    {
        foreach (PayrollRunSection::query()->where('payroll_run_id', $runId)->orderBy('id')->get() as $section) {
            foreach (['review', 'finalize'] as $step) {
                if ($step === 'review' && $section->fresh()->status !== PayrollRunSection::STATUS_DRAFT) {
                    continue;
                }

                $this->app['auth']->forgetGuards();
                $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/{$step}")->assertOk();
            }
        }

        return PayrollRun::query()->findOrFail($runId);
    }

    /** Pays one finalized company's whole outstanding balance through "Approve Paid", which approves it (test shortcut). */
    protected function approvePaid(int $sectionId, string $token, string $key = 'approve-paid-0001', ?string $date = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-run-sections/{$sectionId}/payments", ['mode' => 'full', 'payment_date' => $date ?? FacilityCalendar::today()->toDateString(), 'idempotency_key' => $key]);
    }
}
