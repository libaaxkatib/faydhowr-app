<?php

namespace App\Actions\GoodsReceipt;

use App\Actions\Inventory\ProcessGoodsReceiptStockAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateGoodsReceiptAction
{
    public function __construct(
        private ProcessGoodsReceiptStockAction $processGoodsReceiptStock,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    /**
     * @param  array{
     *     purchase_order_id: int,
     *     notes?: string|null,
     *     received_at?: string|null,
     *     items: list<array{
     *         purchase_order_item_id: int,
     *         quantity_received: int,
     *         unit_cost?: float|int|string|null
     *     }>
     * }  $data
     */
    public function handle(array $data): GoodsReceipt
    {
        $goodsReceipt = DB::transaction(function () use ($data): GoodsReceipt {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($data['purchase_order_id'])
                ->lockForUpdate()
                ->first();

            if ($purchaseOrder === null) {
                throw new DomainException('Purchase order not found.');
            }

            if (! in_array($purchaseOrder->status, [
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new DomainException(
                    'Goods receipts can only be created for approved or partially received purchase orders.',
                );
            }

            $purchaseOrderItems = $purchaseOrder->items()
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($purchaseOrderItems->isEmpty()) {
                throw new DomainException('The purchase order has no items to receive.');
            }

            $previouslyReceived = GoodsReceiptItem::query()
                ->whereIn('purchase_order_item_id', $purchaseOrderItems->keys())
                ->selectRaw('purchase_order_item_id, SUM(quantity_received) as quantity_received')
                ->groupBy('purchase_order_item_id')
                ->pluck('quantity_received', 'purchase_order_item_id');

            $lineSnapshots = $this->buildLineSnapshots(
                $data['items'],
                $purchaseOrderItems,
                $previouslyReceived,
            );

            $goodsReceipt = GoodsReceipt::query()->create([
                'gr_number' => $this->nextGrNumber(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'purchase_order_id' => $purchaseOrder->id,
                'received_at' => $data['received_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lineSnapshots as $lineSnapshot) {
                $goodsReceipt->items()->create($lineSnapshot);
            }

            $goodsReceipt->load('items');

            $this->processGoodsReceiptStock->handle($goodsReceipt);

            $this->syncPurchaseOrderStatus($purchaseOrder, $purchaseOrderItems, $previouslyReceived, $lineSnapshots);

            return $goodsReceipt->load(['supplier', 'purchaseOrder', 'items']);
        });

        $this->dashboardCache->invalidate();

        return $goodsReceipt;
    }

    /**
     * @param  list<array{purchase_order_item_id: int, quantity_received: int, unit_cost?: float|int|string|null}>  $items
     * @param  Collection<int, PurchaseOrderItem>  $purchaseOrderItems
     * @param  Collection<int, int|string>  $previouslyReceived
     * @return list<array<string, mixed>>
     */
    private function buildLineSnapshots(
        array $items,
        Collection $purchaseOrderItems,
        Collection $previouslyReceived,
    ): array {
        $seenItemIds = [];
        $lineSnapshots = [];

        foreach ($items as $item) {
            $purchaseOrderItemId = (int) $item['purchase_order_item_id'];

            if (isset($seenItemIds[$purchaseOrderItemId])) {
                throw new DomainException('Duplicate purchase order items are not allowed on a goods receipt.');
            }

            $seenItemIds[$purchaseOrderItemId] = true;

            /** @var PurchaseOrderItem|null $purchaseOrderItem */
            $purchaseOrderItem = $purchaseOrderItems->get($purchaseOrderItemId);

            if ($purchaseOrderItem === null) {
                throw new DomainException('One or more purchase order items do not belong to the purchase order.');
            }

            $quantityReceived = (int) $item['quantity_received'];
            $alreadyReceived = (int) ($previouslyReceived->get($purchaseOrderItemId) ?? 0);
            $remaining = $purchaseOrderItem->quantity - $alreadyReceived;

            if ($quantityReceived <= 0) {
                throw new DomainException('Received quantity must be greater than zero.');
            }

            if ($quantityReceived > $remaining) {
                throw new DomainException(
                    'Received quantity cannot exceed the remaining purchase order quantity.',
                );
            }

            $unitCost = array_key_exists('unit_cost', $item) && $item['unit_cost'] !== null
                ? number_format((float) $item['unit_cost'], 2, '.', '')
                : number_format((float) $purchaseOrderItem->unit_cost, 2, '.', '');

            $lineSnapshots[] = [
                'purchase_order_item_id' => $purchaseOrderItem->id,
                'product_id' => $purchaseOrderItem->product_id,
                'sku' => $purchaseOrderItem->sku,
                'product_name' => $purchaseOrderItem->product_name,
                'quantity_received' => $quantityReceived,
                'unit_cost' => $unitCost,
            ];
        }

        return $lineSnapshots;
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $purchaseOrderItems
     * @param  Collection<int, int|string>  $previouslyReceived
     * @param  list<array<string, mixed>>  $lineSnapshots
     */
    private function syncPurchaseOrderStatus(
        PurchaseOrder $purchaseOrder,
        Collection $purchaseOrderItems,
        Collection $previouslyReceived,
        array $lineSnapshots,
    ): void {
        $receivedThisReceipt = collect($lineSnapshots)
            ->mapWithKeys(fn (array $line): array => [
                $line['purchase_order_item_id'] => $line['quantity_received'],
            ]);

        $isFullyReceived = $purchaseOrderItems->every(function (PurchaseOrderItem $item) use ($previouslyReceived, $receivedThisReceipt): bool {
            $totalReceived = (int) ($previouslyReceived->get($item->id) ?? 0)
                + (int) ($receivedThisReceipt->get($item->id) ?? 0);

            return $totalReceived >= $item->quantity;
        });

        if ($isFullyReceived) {
            $purchaseOrder->update([
                'status' => PurchaseOrderStatus::Completed,
                'completed_at' => now(),
            ]);

            return;
        }

        $purchaseOrder->update([
            'status' => PurchaseOrderStatus::PartiallyReceived,
        ]);
    }

    private function nextGrNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["goods-receipt-number-{$year}"]);
        }

        $latestGrNumber = GoodsReceipt::withTrashed()
            ->where('gr_number', 'like', "GR-{$year}-%")
            ->orderByDesc('gr_number')
            ->lockForUpdate()
            ->value('gr_number');

        $nextSequence = $latestGrNumber === null
            ? 1
            : ((int) substr($latestGrNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The goods receipt number range for this year is exhausted.');
        }

        return sprintf('GR-%s-%06d', $year, $nextSequence);
    }
}
