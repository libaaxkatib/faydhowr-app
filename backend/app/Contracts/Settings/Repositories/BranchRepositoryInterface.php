<?php

namespace App\Contracts\Settings\Repositories;

use App\Models\Branch;
use Illuminate\Support\Collection;

interface BranchRepositoryInterface
{
    /**
     * @return Collection<int, Branch>
     */
    public function all(): Collection;

    public function findById(int $id): ?Branch;

    public function findByCode(string $code): ?Branch;

    public function findDefault(): ?Branch;

    public function markActive(Branch $branch, int $adminId): Branch;

    /**
     * Clear the current default flag and set the given branch as default.
     */
    public function makeDefault(Branch $branch): Branch;

    /**
     * Overwrite branch state from a backup snapshot row.
     *
     * @param  array{status: string, is_default: bool}  $attributes
     */
    public function restoreState(Branch $branch, array $attributes): Branch;
}
