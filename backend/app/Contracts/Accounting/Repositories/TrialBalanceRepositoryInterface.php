<?php

namespace App\Contracts\Accounting\Repositories;

use App\DataTransferObjects\Accounting\TrialBalanceRowData;
use Carbon\CarbonInterface;

/**
 * Aggregates trial balance rows directly from the journal entry lines of
 * posted journal entries. No cached balances, no ledger table.
 */
interface TrialBalanceRepositoryInterface
{
    /**
     * One aggregated row per account with posted activity in the optional
     * entry-date range, ordered by account code.
     *
     * @return list<TrialBalanceRowData>
     */
    public function rows(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array;
}
