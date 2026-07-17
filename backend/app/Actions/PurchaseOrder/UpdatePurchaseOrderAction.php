<?php

namespace App\Actions\PurchaseOrder;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdatePurchaseOrderAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array{
     *     supplier_id?: int,
     *     currency?: string,
     *     notes?: string|null,
     *     items?: list<array{product_id: int, quantity: int, unit_cost: float|int|string}>
     * }  $data
     */
    public function handle(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $purchaseOrder = DB::transaction(function () use ($purchaseOrder, $data): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($purchaseOrder)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException('Only draft purchase orders can be updated.');
            }

            if (array_key_exists('supplier_id', $data)) {
                $supplier = Supplier::query()
                    ->whereKey($data['supplier_id'])
                    ->lockForUpdate()
                    ->first();

                if ($supplier === null) {
                    throw new DomainException('Supplier not found.');
                }

                $purchaseOrder->supplier_id = $supplier->id;
            }

            if (array_key_exists('currency', $data)) {
                $purchaseOrder->currency = $data['currency'];
            }

            if (array_key_exists('notes', $data)) {
                $purchaseOrder->notes = $data['notes'];
            }

            if (array_key_exists('items', $data)) {
                [$lineSnapshots, $subtotal] = $this->buildLineSnapshots($data['items']);

                $purchaseOrder->items()->delete();

                foreach ($lineSnapshots as $lineSnapshot) {
                    $purchaseOrder->items()->create($lineSnapshot);
                }

                $purchaseOrder->subtotal = $subtotal;
            }

            $purchaseOrder->save();

            return $purchaseOrder->load(['supplier', 'items.product']);
        });

        $this->dashboardCache->invalidate();

        return $purchaseOrder;
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_cost: float|int|string}>  $items
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function buildLineSnapshots(array $items): array
    {
        $productIds = array_column($items, 'product_id');

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lineSnapshots = [];
        $subtotal = '0.00';

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if ($product === null) {
                throw new DomainException('One or more products could not be found.');
            }

            $quantity = (int) $item['quantity'];
            $unitCost = number_format((float) $item['unit_cost'], 2, '.', '');
            $lineTotal = bcmul($unitCost, (string) $quantity, 2);

            $lineSnapshots[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
            ];

            $subtotal = bcadd($subtotal, $lineTotal, 2);
        }

        return [$lineSnapshots, $subtotal];
    }
}
