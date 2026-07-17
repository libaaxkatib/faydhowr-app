<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\AccountingPeriodRepositoryInterface;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class AccountingPeriodRepository implements AccountingPeriodRepositoryInterface
{
    public function findById(int $id): ?AccountingPeriod
    {
        return AccountingPeriod::query()->find($id);
    }

    public function all(): Collection
    {
        return AccountingPeriod::query()
            ->orderByDesc('start_date')
            ->get();
    }

    public function findByDate(CarbonInterface $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    public function hasOverlap(CarbonInterface $startDate, CarbonInterface $endDate): bool
    {
        return AccountingPeriod::query()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();
    }

    public function create(array $attributes): AccountingPeriod
    {
        return AccountingPeriod::query()->create($attributes);
    }
}
