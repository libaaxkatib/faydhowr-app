<?php

namespace App\Services\Settings;

use App\Contracts\Settings\Repositories\BranchRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Contracts\Settings\Services\BranchServiceInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Enums\Settings\SettingCategory;
use App\Exceptions\Settings\BranchNotActiveException;
use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchService implements BranchServiceInterface
{
    public function __construct(
        private BranchRepositoryInterface $branches,
        private SettingsServiceInterface $settings,
        private AuditServiceInterface $audit,
    ) {}

    public function all(): Collection
    {
        return $this->branches->all();
    }

    public function findById(int $id): ?Branch
    {
        return $this->branches->findById($id);
    }

    public function activate(Branch $branch, Admin $admin, ?string $ipAddress): Branch
    {
        return DB::transaction(function () use ($branch, $admin, $ipAddress): Branch {
            $previousStatus = $branch->status->value;

            $activated = $this->branches->markActive($branch, $admin->id);

            $this->audit->record(
                category: SettingCategory::Branch->value,
                key: 'status',
                oldValue: ['code' => $branch->code, 'status' => $previousStatus],
                newValue: ['code' => $branch->code, 'status' => $activated->status->value],
                admin: $admin,
                ipAddress: $ipAddress,
            );

            return $activated;
        });
    }

    public function makeDefault(Branch $branch, Admin $admin, ?string $ipAddress): Branch
    {
        if (! $branch->isActive()) {
            throw BranchNotActiveException::forDefault($branch);
        }

        return DB::transaction(function () use ($branch, $admin, $ipAddress): Branch {
            $default = $this->branches->makeDefault($branch);

            $this->settings->updateCategory(
                SettingCategory::Branch,
                ['branch.default' => $branch->code],
                $admin,
                $ipAddress,
            );

            return $default;
        });
    }
}
