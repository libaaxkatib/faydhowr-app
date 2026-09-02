<?php

namespace App\Actions\Marketing;

use App\Models\CommissionRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCommissionRecordsAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = CommissionRecord::query()->with(['admin', 'marketingRecord']);

        if (! empty($filters['admin_id'])) {
            $query->where('admin_id', $filters['admin_id']);
        }

        if (! empty($filters['month'])) {
            $query->whereRaw("to_char(reference_date, 'YYYY-MM') = ?", [$filters['month']]);
        }

        return $query
            ->orderByDesc('reference_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
