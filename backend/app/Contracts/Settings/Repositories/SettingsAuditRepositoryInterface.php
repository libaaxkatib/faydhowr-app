<?php

namespace App\Contracts\Settings\Repositories;

use App\Models\SettingsAuditLog;
use Illuminate\Support\Collection;

interface SettingsAuditRepositoryInterface
{
    /**
     * @param  array{category: string, key: string, old_value: mixed, new_value: mixed, changed_by: int, ip_address: string|null}  $attributes
     */
    public function record(array $attributes): SettingsAuditLog;

    /**
     * Latest-first audit entries with the changing admin eager loaded.
     *
     * @param  array{category?: string|null, changed_by?: int|null, from?: string|null, to?: string|null, limit?: int|null}  $filters
     * @return Collection<int, SettingsAuditLog>
     */
    public function filtered(array $filters): Collection;
}
