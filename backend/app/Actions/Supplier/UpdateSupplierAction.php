<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class UpdateSupplierAction
{
    /**
     * @param  array{
     *     name?: string,
     *     contact_person?: string|null,
     *     phone?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     *     status?: string|SupplierStatus
     * }  $data
     */
    public function handle(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data): Supplier {
            $supplier = Supplier::query()
                ->whereKey($supplier)
                ->lockForUpdate()
                ->firstOrFail();

            $supplier->fill($data);
            $supplier->save();

            return $supplier->refresh();
        });
    }
}
