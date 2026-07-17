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

class CreatePurchaseOrderAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array{
     *     supplier_id: int,
     *     currency: string,
     *     notes?: string|null,
     *     items: list<array{product_id: int, quantity: int, unit_cost: float|int|string}>
     * }  $data
     */
    public function handle(array $data): PurchaseOrder
    {
        $purchaseOrder = DB::transaction(function () use ($data): PurchaseOrder {
            $supplier = Supplier::query()
                ->whereKey($data['supplier_id'])
                ->lockForUpdate()
                ->first();

            if ($supplier === null) {
                throw new DomainException('Supplier not found.');
            }

            [$lineSnapshots, $subtotal] = $this->buildLineSnapshots($data['items']);

            $purchaseOrder = PurchaseOrder::query()->create([
                'po_number' => $this->nextPoNumber(),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrderStatus::Draft,
                'currency' => $data['currency'],
                'subtotal' => $subtotal,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lineSnapshots as $lineSnapshot) {
                $purchaseOrder->items()->create($lineSnapshot);
            }

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

    private function nextPoNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["purchase-order-number-{$year}"]);
        }

        $latestPoNumber = PurchaseOrder::withTrashed()
            ->where('po_number', 'like', "PO-{$year}-%")
            ->orderByDesc('po_number')
            ->lockForUpdate()
            ->value('po_number');

        $nextSequence = $latestPoNumber === null
            ? 1
            : ((int) substr($latestPoNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The purchase order number range for this year is exhausted.');
        }

        return sprintf('PO-%s-%06d', $year, $nextSequence);
    }
}
