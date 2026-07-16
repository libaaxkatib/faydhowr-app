<?php

namespace App\Actions\Admin;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAuditLogsAction
{
    /**
     * @param  array{
     *     action?: string|null,
     *     entity_type?: string|null,
     *     admin_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     per_page?: int|null
     * }  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return AuditLog::query()
            ->with('admin')
            ->when(
                filled($filters['action'] ?? null),
                fn ($query) => $query->where('action', $filters['action']),
            )
            ->when(
                filled($filters['entity_type'] ?? null),
                fn ($query) => $query->where('entity_type', $filters['entity_type']),
            )
            ->when(
                array_key_exists('admin_id', $filters) && $filters['admin_id'] !== null,
                fn ($query) => $query->where('admin_id', $filters['admin_id']),
            )
            ->when(
                filled($filters['date_from'] ?? null),
                fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }
}
