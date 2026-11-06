<?php

namespace App\Actions\Marketing;

use App\Models\Admin;
use App\Models\CommissionRecord;
use App\Support\Marketing\MarketingScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCommissionRecordsAction
{
    public function handle(array $filters, Admin $actor): LengthAwarePaginator
    {
        $query = CommissionRecord::query()->with(['admin', 'marketingRecord']);

        if (! MarketingScope::unrestricted($actor)) {
            $query->where('admin_id', $actor->id);
        }

        if (! empty($filters['admin_id'])) {
            $query->where('admin_id', $filters['admin_id']);
        }

        if (! empty($filters['month'])) {
            // The calendar month 'YYYY-MM' as a date range, so it works on every database. A value that is not a month matches nothing, as before.
            if (is_string($filters['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['month']) === 1) {
                $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month']);
                $query->whereDate('reference_date', '>=', $month->startOfMonth()->toDateString())
                    ->whereDate('reference_date', '<=', $month->endOfMonth()->toDateString());
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query
            ->orderByDesc('reference_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
