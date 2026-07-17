<?php

namespace App\Contracts\Settings\Services;

use App\DataTransferObjects\Settings\BackupData;
use App\Exceptions\Settings\BackupNotFoundException;
use App\Models\Admin;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface BackupServiceInterface
{
    /**
     * Newest-first list of stored backups.
     *
     * @return list<BackupData>
     */
    public function all(): array;

    /**
     * Create a configuration snapshot (system settings + branches) on disk
     * and stamp backup.last_run_at.
     */
    public function create(Admin $admin, ?string $ipAddress): BackupData;

    /**
     * @throws BackupNotFoundException
     */
    public function download(string $id): StreamedResponse;

    /**
     * Restore settings and branches from a stored snapshot. Destructive;
     * Super Admin only (enforced by policy) and audit-logged.
     *
     * @throws BackupNotFoundException
     */
    public function restore(string $id, Admin $admin, ?string $ipAddress): void;
}
