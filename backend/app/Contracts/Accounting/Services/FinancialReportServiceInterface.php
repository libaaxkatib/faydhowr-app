<?php

namespace App\Contracts\Accounting\Services;

use App\DataTransferObjects\Accounting\BalanceSheetData;
use App\DataTransferObjects\Accounting\IncomeStatementData;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;

/**
 * Contract for the financial report service of the Accounting module.
 * Statements are derived on demand from posted journal entry lines for an
 * optional date range or an accounting period.
 */
interface FinancialReportServiceInterface
{
    public function incomeStatement(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): IncomeStatementData;

    public function incomeStatementForPeriod(AccountingPeriod $period): IncomeStatementData;

    public function balanceSheet(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): BalanceSheetData;

    public function balanceSheetForPeriod(AccountingPeriod $period): BalanceSheetData;
}
