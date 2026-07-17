<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\FinancialReportRepositoryInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\DataTransferObjects\Accounting\BalanceSheetData;
use App\DataTransferObjects\Accounting\IncomeStatementData;
use App\Enums\Accounting\AccountCategory;
use App\Models\AccountingPeriod;
use App\Support\Accounting\Money;
use Carbon\CarbonInterface;

/**
 * Financial statement orchestration. Category aggregation is owned by the
 * repository; the service derives each statement from the category nets:
 * revenue, liabilities, and equity accrue on the credit side, while assets
 * and expenses accrue on the debit side.
 */
class FinancialReportService implements FinancialReportServiceInterface
{
    public function __construct(
        private FinancialReportRepositoryInterface $financialReportRepository,
    ) {}

    public function incomeStatement(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): IncomeStatementData
    {
        $totals = $this->financialReportRepository->categoryTotals($startDate, $endDate);

        $revenueCents = $this->creditNetCents($totals, AccountCategory::Revenue);
        $expenseCents = $this->debitNetCents($totals, AccountCategory::Expenses);

        return new IncomeStatementData(
            totalRevenue: Money::fromCents($revenueCents),
            totalExpenses: Money::fromCents($expenseCents),
            netProfit: Money::fromCents($revenueCents - $expenseCents),
            startDate: $startDate?->toDateString(),
            endDate: $endDate?->toDateString(),
        );
    }

    public function incomeStatementForPeriod(AccountingPeriod $period): IncomeStatementData
    {
        return $this->incomeStatement($period->start_date, $period->end_date);
    }

    public function balanceSheet(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): BalanceSheetData
    {
        $totals = $this->financialReportRepository->categoryTotals($startDate, $endDate);

        $assetCents = $this->debitNetCents($totals, AccountCategory::Assets);
        $liabilityCents = $this->creditNetCents($totals, AccountCategory::Liabilities);
        $equityCents = $this->creditNetCents($totals, AccountCategory::Equity);

        $currentEarningsCents = $this->creditNetCents($totals, AccountCategory::Revenue)
            - $this->debitNetCents($totals, AccountCategory::Expenses);

        $totalEquityCents = $equityCents + $currentEarningsCents;

        return new BalanceSheetData(
            totalAssets: Money::fromCents($assetCents),
            totalLiabilities: Money::fromCents($liabilityCents),
            totalEquity: Money::fromCents($totalEquityCents),
            currentEarnings: Money::fromCents($currentEarningsCents),
            isBalanced: $assetCents === $liabilityCents + $totalEquityCents,
            startDate: $startDate?->toDateString(),
            endDate: $endDate?->toDateString(),
        );
    }

    public function balanceSheetForPeriod(AccountingPeriod $period): BalanceSheetData
    {
        return $this->balanceSheet($period->start_date, $period->end_date);
    }

    /**
     * @param  array<string, array{debit_cents: int, credit_cents: int}>  $totals
     */
    private function debitNetCents(array $totals, AccountCategory $category): int
    {
        $categoryTotals = $totals[$category->value] ?? ['debit_cents' => 0, 'credit_cents' => 0];

        return $categoryTotals['debit_cents'] - $categoryTotals['credit_cents'];
    }

    /**
     * @param  array<string, array{debit_cents: int, credit_cents: int}>  $totals
     */
    private function creditNetCents(array $totals, AccountCategory $category): int
    {
        return -$this->debitNetCents($totals, $category);
    }
}
