<?php

namespace App\Actions\Supplier;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class CreateSupplierAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array{
     *     name: string,
     *     contact_person?: string|null,
     *     phone?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     *     status?: string|SupplierStatus|null
     * }  $data
     */
    public function handle(array $data): Supplier
    {
        $supplier = DB::transaction(function () use ($data): Supplier {
            return Supplier::query()->create([
                'name' => $data['name'],
                'contact_person' => $data['contact_person'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => $data['status'] ?? SupplierStatus::Active,
            ]);
        });

        $this->dashboardCache->invalidate();

        return $supplier;
    }
}
