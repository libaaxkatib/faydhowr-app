<?php

namespace App\Repositories\Settings;

use App\Contracts\Settings\Repositories\BranchRepositoryInterface;
use App\Enums\Settings\BranchStatus;
use App\Models\Branch;
use Illuminate\Support\Collection;

class BranchRepository implements BranchRepositoryInterface
{
    public function all(): Collection
    {
        return Branch::query()->orderBy('id')->get();
    }

    public function findById(int $id): ?Branch
    {
        return Branch::query()->find($id);
    }

    public function findByCode(string $code): ?Branch
    {
        return Branch::query()->where('code', $code)->first();
    }

    public function findDefault(): ?Branch
    {
        return Branch::query()->where('is_default', true)->first();
    }

    public function markActive(Branch $branch, int $adminId): Branch
    {
        $branch->forceFill([
            'status' => BranchStatus::Active,
            'activated_at' => now(),
            'activated_by' => $adminId,
        ])->save();

        return $branch;
    }

    public function makeDefault(Branch $branch): Branch
    {
        Branch::query()
            ->where('is_default', true)
            ->whereKeyNot($branch->id)
            ->update(['is_default' => false]);

        $branch->forceFill(['is_default' => true])->save();

        return $branch;
    }

    public function restoreState(Branch $branch, array $attributes): Branch
    {
        $branch->forceFill([
            'status' => $attributes['status'],
            'is_default' => $attributes['is_default'],
        ])->save();

        return $branch;
    }
}
