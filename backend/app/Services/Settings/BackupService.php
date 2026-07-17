<?php

namespace App\Services\Settings;

use App\Contracts\Settings\Repositories\BranchRepositoryInterface;
use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Contracts\Settings\Services\BackupServiceInterface;
use App\DataTransferObjects\Settings\BackupData;
use App\Enums\Settings\SettingCategory;
use App\Exceptions\Settings\BackupNotFoundException;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\SystemSetting;
use App\Support\Settings\SettingsCache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configuration snapshots (system settings + branches) stored as JSON
 * archives on the local disk. The system_settings table only tracks backup
 * metadata (backup.last_run_at).
 */
class BackupService implements BackupServiceInterface
{
    private const string DIRECTORY = 'settings-backups';

    public function __construct(
        private SystemSettingRepositoryInterface $settings,
        private BranchRepositoryInterface $branches,
        private AuditServiceInterface $audit,
        private SettingsCache $cache,
    ) {}

    public function all(): array
    {
        $backups = [];

        foreach ($this->disk()->files(self::DIRECTORY) as $path) {
            $snapshot = json_decode($this->disk()->get($path), true);

            if (! is_array($snapshot) || ! isset($snapshot['id'], $snapshot['created_at'])) {
                continue;
            }

            $backups[] = new BackupData(
                id: $snapshot['id'],
                sizeBytes: $this->disk()->size($path),
                createdBy: $snapshot['created_by'] ?? null,
                createdAt: Carbon::parse($snapshot['created_at']),
            );
        }

        usort($backups, fn (BackupData $a, BackupData $b): int => $b->createdAt <=> $a->createdAt);

        return $backups;
    }

    public function create(Admin $admin, ?string $ipAddress): BackupData
    {
        $createdAt = now();
        $id = 'backup-'.$createdAt->format('Ymd-His').'-'.Str::lower(Str::random(6));

        $snapshot = [
            'id' => $id,
            'created_at' => $createdAt->toIso8601String(),
            'created_by' => $admin->full_name,
            'settings' => $this->settings->all()
                ->map(fn (SystemSetting $setting): array => [
                    'category' => $setting->category,
                    'key' => $setting->key,
                    'value' => $setting->value,
                ])
                ->values()
                ->all(),
            'branches' => $this->branches->all()
                ->map(fn (Branch $branch): array => [
                    'code' => $branch->code,
                    'status' => $branch->status->value,
                    'is_default' => $branch->is_default,
                ])
                ->values()
                ->all(),
        ];

        $this->disk()->put($this->path($id), (string) json_encode($snapshot, JSON_PRETTY_PRINT));

        DB::transaction(function () use ($admin, $ipAddress, $createdAt, $id): void {
            $lastRun = $this->settings->find(SettingCategory::Backup, 'last_run_at');

            if ($lastRun !== null) {
                $this->settings->setValue($lastRun, $createdAt->toIso8601String(), $admin->id);
            }

            $this->audit->record(
                category: SettingCategory::Backup->value,
                key: 'run',
                oldValue: null,
                newValue: ['backup_id' => $id],
                admin: $admin,
                ipAddress: $ipAddress,
            );
        });

        $this->cache->forget(SettingCategory::Backup);

        return new BackupData(
            id: $id,
            sizeBytes: $this->disk()->size($this->path($id)),
            createdBy: $admin->full_name,
            createdAt: $createdAt,
        );
    }

    public function download(string $id): StreamedResponse
    {
        $this->assertExists($id);

        return $this->disk()->download($this->path($id), $id.'.json');
    }

    public function restore(string $id, Admin $admin, ?string $ipAddress): void
    {
        $this->assertExists($id);

        $snapshot = json_decode($this->disk()->get($this->path($id)), true);

        if (! is_array($snapshot) || ! isset($snapshot['id'])) {
            throw BackupNotFoundException::corrupt($id);
        }

        // DB::transaction rolls everything back on any exception, so a failed
        // restore never leaves partially restored data behind.
        DB::transaction(function () use ($snapshot, $admin, $ipAddress, $id): void {
            foreach ($snapshot['settings'] ?? [] as $row) {
                $category = SettingCategory::tryFrom($row['category']);

                if ($category === null) {
                    continue;
                }

                $setting = $this->settings->find($category, $row['key']);

                if ($setting !== null && $setting->value !== $row['value']) {
                    // Snapshot values are the stored representation (sensitive
                    // values are already ciphertext), so write them verbatim.
                    $this->settings->setStoredValue($setting, $row['value'], $admin->id);
                }
            }

            foreach ($snapshot['branches'] ?? [] as $row) {
                $branch = $this->branches->findByCode($row['code']);

                if ($branch !== null) {
                    $this->branches->restoreState($branch, [
                        'status' => $row['status'],
                        'is_default' => $row['is_default'],
                    ]);
                }
            }

            $this->audit->record(
                category: SettingCategory::Backup->value,
                key: 'restore',
                oldValue: null,
                newValue: ['backup_id' => $id],
                admin: $admin,
                ipAddress: $ipAddress,
            );
        });

        $this->cache->forgetAll();
    }

    private function assertExists(string $id): void
    {
        if (! $this->disk()->exists($this->path($id))) {
            throw BackupNotFoundException::forId($id);
        }
    }

    private function path(string $id): string
    {
        return self::DIRECTORY.'/'.$id.'.json';
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
