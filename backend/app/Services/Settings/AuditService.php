<?php

namespace App\Services\Settings;

use App\Contracts\Settings\Repositories\SettingsAuditRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Models\Admin;
use App\Models\SettingsAuditLog;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Collection;

class AuditService implements AuditServiceInterface
{
    public function __construct(private SettingsAuditRepositoryInterface $auditLogs) {}

    public function record(
        string $category,
        string $key,
        mixed $oldValue,
        mixed $newValue,
        Admin $admin,
        ?string $ipAddress,
        bool $sensitive = false,
    ): SettingsAuditLog {
        return $this->auditLogs->record([
            'category' => $category,
            'key' => $key,
            'old_value' => $sensitive ? $this->mask($oldValue) : $oldValue,
            'new_value' => $sensitive ? $this->mask($newValue) : $newValue,
            'changed_by' => $admin->id,
            'ip_address' => $ipAddress,
        ]);
    }

    public function logs(array $filters): Collection
    {
        return $this->auditLogs->filtered($filters);
    }

    private function mask(mixed $value): ?string
    {
        return $value === null ? null : SettingsRegistry::mask();
    }
}
