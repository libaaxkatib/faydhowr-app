<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminsAction
{
    /**
     * @return LengthAwarePaginator<int, Admin>
     */
    public function handle(
        ?AdminRole $role,
        ?AdminStatus $status,
        ?string $search,
        int $perPage,
    ): LengthAwarePaginator {
        return Admin::query()
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }
}
