<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;

class GetSupplierAction
{
    public function handle(int $supplierId): ?Supplier
    {
        return Supplier::query()
            ->whereKey($supplierId)
            ->first();
    }
}
