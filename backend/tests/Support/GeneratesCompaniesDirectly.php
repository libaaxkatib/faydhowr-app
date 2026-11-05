<?php

namespace Tests\Support;

use App\Actions\Hr\GenerateCompanyPayrollAction;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\PayrollCompanyOverview;

/**
 * TEST SUPPORT ONLY. Generates EVERY assigned company of a month through the real per-company action (there is no month-wide action any more), for the tests
 * that exercise the action directly instead of through HTTP. Companies whose section is approved or finalized are skipped, exactly as a user could not
 * regenerate them.
 */
trait GeneratesCompaniesDirectly
{
    protected function generateAllCompanies(string $period, Admin $admin, ?GenerateCompanyPayrollAction $action = null): PayrollRun
    {
        $action ??= app(GenerateCompanyPayrollAction::class);
        $run = null;

        foreach (PayrollCompanyOverview::forPeriod($period)['companies'] as $company) {
            if (in_array($company['section_status'], PayrollRunSection::LOCKED_STATUSES, true)) {
                continue;
            }

            $run = $action->handle($period, $company['client_company_id'], $admin);
        }

        return $run ?? PayrollRun::query()->where('period', $period)->firstOrFail();
    }
}
