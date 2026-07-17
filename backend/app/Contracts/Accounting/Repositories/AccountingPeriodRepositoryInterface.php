<?php

namespace App\Contracts\Accounting\Repositories;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence and validation queries for accounting periods. Business
 * rules (overlap rejection, date ordering) are owned by the service.
 */
interface AccountingPeriodRepositoryInterface
{
    public function findById(int $id): ?AccountingPeriod;

    /**
     * Every period ordered by start date, newest first.
     *
     * @return Collection<int, AccountingPeriod>
     */
    public function all(): Collection;

    /**
     * The single period whose date range contains the given date, if any.
     */
    public function findByDate(CarbonInterface $date): ?AccountingPeriod;

    /**
     * Whether any period overlaps the given range.
     */
    public function hasOverlap(CarbonInterface $startDate, CarbonInterface $endDate): bool;

    /**
     * @param  array{name: string, start_date: string, end_date: string, status: AccountingPeriodStatus}  $attributes
     */
    public function create(array $attributes): AccountingPeriod;
}
