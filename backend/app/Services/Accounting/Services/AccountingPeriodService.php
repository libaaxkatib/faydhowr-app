<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\AccountingPeriodRepositoryInterface;
use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Enums\Accounting\AccountingPeriodStatus;
use App\Exceptions\Accounting\InvalidAccountingPeriodException;
use App\Exceptions\Accounting\OverlappingAccountingPeriodException;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Accounting period business rules: periods never overlap, so only one
 * period may contain a given date. Validation queries are owned by the
 * repository; closing logic arrives in a later phase.
 */
class AccountingPeriodService implements AccountingPeriodServiceInterface
{
    public function __construct(
        private AccountingPeriodRepositoryInterface $accountingPeriodRepository,
    ) {}

    public function create(string $name, CarbonInterface $startDate, CarbonInterface $endDate): AccountingPeriod
    {
        if ($startDate->greaterThan($endDate)) {
            throw InvalidAccountingPeriodException::inverseDateRange($startDate, $endDate);
        }

        if ($this->accountingPeriodRepository->hasOverlap($startDate, $endDate)) {
            throw OverlappingAccountingPeriodException::forRange($startDate, $endDate);
        }

        return $this->accountingPeriodRepository->create([
            'name' => $name,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ]);
    }

    public function periodContaining(CarbonInterface $date): ?AccountingPeriod
    {
        return $this->accountingPeriodRepository->findByDate($date);
    }

    public function findById(int $id): ?AccountingPeriod
    {
        return $this->accountingPeriodRepository->findById($id);
    }

    public function all(): Collection
    {
        return $this->accountingPeriodRepository->all();
    }
}
