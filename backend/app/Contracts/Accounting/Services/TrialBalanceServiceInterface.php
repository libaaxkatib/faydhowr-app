<?php

namespace App\Contracts\Accounting\Services;

use App\DataTransferObjects\Accounting\TrialBalanceData;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;

/**
 * Contract for the trial balance service. The trial balance is derived on
 * demand from posted journal entry lines for an optional date range or an
 * accounting period.
 */
interface TrialBalanceServiceInterface
{
    public function generate(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): TrialBalanceData;

    public function generateForPeriod(AccountingPeriod $period): TrialBalanceData;
}
