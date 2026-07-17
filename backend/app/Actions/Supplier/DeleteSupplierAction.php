<?php

namespace App\Actions\Supplier;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class DeleteSupplierAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier): void {
            $supplier = Supplier::query()
                ->whereKey($supplier)
                ->lockForUpdate()
                ->firstOrFail();

            $supplier->delete();
        });

        $this->dashboardCache->invalidate();
    }
}
