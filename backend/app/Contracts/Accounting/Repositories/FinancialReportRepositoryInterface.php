<?php

namespace App\Contracts\Accounting\Repositories;

use Carbon\CarbonInterface;

/**
 * Aggregates financial statement figures directly from the journal entry
 * lines of posted journal entries. No cached balances, no ledger table.
 */
interface FinancialReportRepositoryInterface
{
    /**
     * Total posted debits and credits per account category within the
     * optional entry-date range, in exact integer cents.
     *
     * @return array<string, array{debit_cents: int, credit_cents: int}> keyed by AccountCategory value
     */
    public function categoryTotals(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array;
}
