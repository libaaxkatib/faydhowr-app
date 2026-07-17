<?php

namespace App\Contracts\Accounting\Services;

use App\Exceptions\Accounting\InvalidAccountingPeriodException;
use App\Exceptions\Accounting\OverlappingAccountingPeriodException;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for the accounting period service. Closing and reopening
 * workflows are defined in later phases.
 */
interface AccountingPeriodServiceInterface
{
    /**
     * Create a new open accounting period.
     *
     * @throws InvalidAccountingPeriodException if the start date is after the end date
     * @throws OverlappingAccountingPeriodException if any existing period overlaps the range
     */
    public function create(string $name, CarbonInterface $startDate, CarbonInterface $endDate): AccountingPeriod;

    /**
     * The single period containing the given date, if any.
     */
    public function periodContaining(CarbonInterface $date): ?AccountingPeriod;

    public function findById(int $id): ?AccountingPeriod;

    /**
     * Every period ordered by start date, newest first.
     *
     * @return Collection<int, AccountingPeriod>
     */
    public function all(): Collection;
}
