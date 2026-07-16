<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class DeleteSupplierAction
{
    public function handle(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier): void {
            $supplier = Supplier::query()
                ->whereKey($supplier)
                ->lockForUpdate()
                ->firstOrFail();

            $supplier->delete();
        });
    }
}
