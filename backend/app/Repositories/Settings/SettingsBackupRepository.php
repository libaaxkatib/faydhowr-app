<?php

namespace App\Repositories\Settings;

use App\Contracts\Settings\Repositories\SettingsBackupRepositoryInterface;
use App\Models\SettingsBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SettingsBackupRepository implements SettingsBackupRepositoryInterface
{
    public function all(): Collection
    {
        return SettingsBackup::query()
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id): ?SettingsBackup
    {
        return SettingsBackup::query()->find($id);
    }

    public function create(string $id, array $snapshot, int $sizeBytes, ?string $createdBy, Carbon $createdAt): SettingsBackup
    {
        return SettingsBackup::query()->create([
            'id' => $id,
            'snapshot' => $snapshot,
            'size_bytes' => $sizeBytes,
            'created_by' => $createdBy,
            'created_at' => $createdAt,
        ]);
    }
}
