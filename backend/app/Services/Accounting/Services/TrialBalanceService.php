<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\TrialBalanceRepositoryInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;
use App\DataTransferObjects\Accounting\TrialBalanceData;
use App\DataTransferObjects\Accounting\TrialBalanceRowData;
use App\Models\AccountingPeriod;
use App\Support\Accounting\Money;
use Carbon\CarbonInterface;

/**
 * Trial balance orchestration. Row aggregation is owned by the repository;
 * the service derives the totals and the balanced invariant.
 */
class TrialBalanceService implements TrialBalanceServiceInterface
{
    public function __construct(
        private TrialBalanceRepositoryInterface $trialBalanceRepository,
    ) {}

    public function generate(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): TrialBalanceData
    {
        $rows = $this->trialBalanceRepository->rows($startDate, $endDate);

        $totalDebitCents = array_sum(array_map(
            fn (TrialBalanceRowData $row): int => Money::toCents($row->totalDebit),
            $rows,
        ));
        $totalCreditCents = array_sum(array_map(
            fn (TrialBalanceRowData $row): int => Money::toCents($row->totalCredit),
            $rows,
        ));

        return new TrialBalanceData(
            rows: $rows,
            totalDebit: Money::fromCents($totalDebitCents),
            totalCredit: Money::fromCents($totalCreditCents),
            isBalanced: $totalDebitCents === $totalCreditCents,
            startDate: $startDate?->toDateString(),
            endDate: $endDate?->toDateString(),
        );
    }

    public function generateForPeriod(AccountingPeriod $period): TrialBalanceData
    {
        return $this->generate($period->start_date, $period->end_date);
    }
}
