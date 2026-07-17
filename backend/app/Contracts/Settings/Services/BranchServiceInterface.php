<?php

namespace App\Contracts\Settings\Services;

use App\Exceptions\Settings\BranchNotActiveException;
use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Support\Collection;

interface BranchServiceInterface
{
    /**
     * @return Collection<int, Branch>
     */
    public function all(): Collection;

    public function findById(int $id): ?Branch;

    /**
     * Activate a branch (Super Admin only, enforced by policy). The change
     * is audit-logged under the branch category.
     */
    public function activate(Branch $branch, Admin $admin, ?string $ipAddress): Branch;

    /**
     * Make a branch the default. The target must be ACTIVE.
     *
     * @throws BranchNotActiveException
     */
    public function makeDefault(Branch $branch, Admin $admin, ?string $ipAddress): Branch;
}
