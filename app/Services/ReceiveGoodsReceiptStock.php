<?php

namespace App\Services;

use App\Enums\GoodsReceiptApprovalStatus;
use App\Enums\RecordStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ItemStockMutation;
use App\Models\ItemWarehouseStock;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiveGoodsReceiptStock
{
    public function handle(GoodsReceipt $receipt, ?string $actorId): void
    {
        if ($receipt->record_status !== RecordStatus::Saved
            || $receipt->approval_status !== GoodsReceiptApprovalStatus::Approved) {
            throw ValidationException::withMessages(['approval' => 'Only approved Goods Receipts can update stock.']);
        }

        app(SystemFreezeGuard::class)->assertNotFrozen();

        DB::transaction(function () use ($receipt, $actorId): void {
            $receipt = GoodsReceipt::query()
                ->with(['purchaseOrder.warehouse', 'items.purchaseOrderItem.item'])
                ->lockForUpdate()
                ->findOrFail($receipt->getKey());

            if ($receipt->record_status !== RecordStatus::Saved
                || $receipt->approval_status !== GoodsReceiptApprovalStatus::Approved) {
                throw ValidationException::withMessages(['approval' => 'Only approved Goods Receipts can update stock.']);
            }

            $warehouse = $receipt->purchaseOrder?->warehouse;

            if (! $warehouse) {
                throw ValidationException::withMessages(['purchase_order_id' => 'The Purchase Order has no stock location.']);
            }

            foreach ($receipt->items as $receiptItem) {
                $quantity = (string) $receiptItem->accepted_quantity;
                $itemId = $receiptItem->purchaseOrderItem?->item_id;

                if (blank($itemId) || (float) $quantity <= 0) {
                    continue;
                }

                if (ItemStockMutation::query()
                    ->where('reference_type', GoodsReceiptItem::class)
                    ->where('reference_id', $receiptItem->id)
                    ->exists()) {
                    continue;
                }

                DB::table('item_warehouse_stocks')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'warehouse_id' => $warehouse->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $stock = ItemWarehouseStock::query()
                    ->where('item_id', $itemId)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (ItemStockMutation::query()
                    ->where('reference_type', GoodsReceiptItem::class)
                    ->where('reference_id', $receiptItem->id)
                    ->exists()) {
                    continue;
                }

                $before = (string) $stock->stock_available;
                $after = Quantity::add($before, $quantity);

                $stock->forceFill(['stock_available' => $after])->save();

                ItemStockMutation::query()->create([
                    'item_id' => $itemId,
                    'warehouse_id' => $warehouse->id,
                    'source' => 'goods_receipt',
                    'reference_type' => GoodsReceiptItem::class,
                    'reference_id' => $receiptItem->id,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'quantity_change' => $quantity,
                    'recorded_by' => $actorId,
                ]);
            }
        });
    }
}
