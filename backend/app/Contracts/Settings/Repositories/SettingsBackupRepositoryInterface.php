<?php

namespace App\Contracts\Settings\Repositories;

use App\Models\SettingsBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface SettingsBackupRepositoryInterface
{
    /**
     * Every stored backup, newest first.
     *
     * @return Collection<int, SettingsBackup>
     */
    public function all(): Collection;

    public function find(string $id): ?SettingsBackup;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function create(string $id, array $snapshot, int $sizeBytes, ?string $createdBy, Carbon $createdAt): SettingsBackup;
}
