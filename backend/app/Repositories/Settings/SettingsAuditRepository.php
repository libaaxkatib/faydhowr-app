<?php

namespace App\Repositories\Settings;

use App\Contracts\Settings\Repositories\SettingsAuditRepositoryInterface;
use App\Models\SettingsAuditLog;
use Illuminate\Support\Collection;

class SettingsAuditRepository implements SettingsAuditRepositoryInterface
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function record(array $attributes): SettingsAuditLog
    {
        return SettingsAuditLog::query()->create([
            ...$attributes,
            'changed_at' => now(),
        ]);
    }

    public function filtered(array $filters): Collection
    {
        $limit = min($filters['limit'] ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return SettingsAuditLog::query()
            ->with('changedBy')
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->forCategory($category))
            ->when($filters['changed_by'] ?? null, fn ($query, int $adminId) => $query->where('changed_by', $adminId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('changed_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('changed_at', '<=', $to))
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
