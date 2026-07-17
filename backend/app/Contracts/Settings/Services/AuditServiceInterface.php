<?php

namespace App\Contracts\Settings\Services;

use App\Models\Admin;
use App\Models\SettingsAuditLog;
use Illuminate\Support\Collection;

interface AuditServiceInterface
{
    /**
     * Record one settings change. When $sensitive is true both values are
     * masked before persisting.
     */
    public function record(
        string $category,
        string $key,
        mixed $oldValue,
        mixed $newValue,
        Admin $admin,
        ?string $ipAddress,
        bool $sensitive = false,
    ): SettingsAuditLog;

    /**
     * @param  array{category?: string|null, changed_by?: int|null, from?: string|null, to?: string|null, limit?: int|null}  $filters
     * @return Collection<int, SettingsAuditLog>
     */
    public function logs(array $filters): Collection;
}
