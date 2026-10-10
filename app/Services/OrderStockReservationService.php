<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OrderStockReservationService
{
    public function pendingQuantity(ProductVariant $variant, Warehouse $warehouse): string
    {
        $hasDistributedStock = WarehouseStock::query()
            ->where('product_variant_id', $variant->id)
            ->exists();

        return (string) OrderItem::query()
            ->where('product_variant_id', $variant->id)
            ->whereHas('order', function (Builder $query) use ($warehouse, $hasDistributedStock): void {
                $query->where('stock_reservation_status', StockReservationStatus::Pending->value);

                if ($hasDistributedStock) {
                    $query->where('fulfillment_warehouse_id', $warehouse->id);
                }
            })
            ->sum('quantity');
    }

    public function availableForReservation(ProductVariant $variant, Warehouse $warehouse): string
    {
        return (string) max(0, Quantity::subtract(StockMutation::availableForWarehouse($variant, $warehouse),
            $this->pendingQuantity($variant, $warehouse)));
    }

    /**
     * @return array{reserved: bool, message: ?string}
     */
    public function reservePending(Order $order, ?string $actorId = null): array
    {
        app(SystemFreezeGuard::class)->assertNotFrozen();

        return DB::transaction(function () use ($order, $actorId): array {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->find($order->id);

            if ($lockedOrder === null) {
                return [
                    'reserved' => false,
                    'message' => 'The order could not be found.',
                ];
            }

            if ($lockedOrder->stock_reservation_status !== StockReservationStatus::Pending) {
                return [
                    'reserved' => false,
                    'message' => 'This order no longer has a pending stock reservation.',
                ];
            }

            if ($lockedOrder->status === OrderStatus::Cancelled) {
                $lockedOrder->forceFill([
                    'stock_reservation_status' => StockReservationStatus::Released,
                    'stock_reservation_error' => null,
                    'updated_by' => $actorId,
                ])->save();

                return [
                    'reserved' => false,
                    'message' => 'Cancelled orders cannot reserve stock.',
                ];
            }

            $warehouse = Warehouse::query()
                ->lockForUpdate()
                ->find($lockedOrder->fulfillment_warehouse_id);

            if ($warehouse === null) {
                return $this->markPending(
                    $lockedOrder,
                    'The fulfillment warehouse is no longer available.',
                    $actorId,
                );
            }

            $items = OrderItem::query()
                ->whereBelongsTo($lockedOrder)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $this->markPending($lockedOrder, 'The order has no items to reserve.', $actorId);
            }

            $variants = ProductVariant::query()
                ->with('product')
                ->whereIn('id', $items->pluck('product_variant_id')->filter()->unique())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $quantities = [];

            foreach ($items as $item) {
                $variant = $variants->get($item->product_variant_id);

                if ($variant === null) {
                    return $this->markPending(
                        $lockedOrder,
                        "Product variant for {$item->product_name} is no longer available.",
                        $actorId,
                    );
                }

                $quantities[$variant->id] = Quantity::add($quantities[$variant->id] ?? 0, $item->quantity);
            }

            foreach ($quantities as $variantId => $quantity) {
                $variant = $variants->get($variantId);
                $warehouseStock = WarehouseStock::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();
                $available = $warehouseStock?->stock_available
                    ?? StockMutation::availableForWarehouse($variant, $warehouse);

                if ($available < $quantity) {
                    return $this->markPending(
                        $lockedOrder,
                        "Insufficient stock for {$variant->product?->name} - {$variant->name} in {$warehouse->name} (available: {$available}, required: {$quantity}).",
                        $actorId,
                    );
                }
            }

            foreach ($items as $item) {
                $variant = $variants->get($item->product_variant_id);

                StockMutation::reserveForOrder(
                    $variant,
                    $item->quantity,
                    $item->id,
                    $actorId,
                    $warehouse,
                );
            }

            $lockedOrder->forceFill([
                'stock_reservation_status' => StockReservationStatus::Reserved,
                'stock_reservation_error' => null,
                'updated_by' => $actorId,
            ])->save();

            return [
                'reserved' => true,
                'message' => null,
            ];
        });
    }

    /**
     * @return array{reserved: int, pending: int}
     */
    public function processPending(?string $actorId = null): array
    {
        $reserved = 0;
        $pending = 0;

        $orderIds = Order::query()
            ->where('stock_reservation_status', StockReservationStatus::Pending->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($orderIds as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null) {
                continue;
            }

            $result = $this->reservePending($order, $actorId);

            if ($result['reserved']) {
                $reserved++;
            } else {
                $pending++;
            }
        }

        return [
            'reserved' => $reserved,
            'pending' => $pending,
        ];
    }

    /**
     * @return array{reserved: bool, message: string}
     */
    private function markPending(Order $order, string $message, ?string $actorId): array
    {
        $order->forceFill([
            'stock_reservation_status' => StockReservationStatus::Pending,
            'stock_reservation_error' => $message,
            'updated_by' => $actorId,
        ])->save();

        return [
            'reserved' => false,
            'message' => $message,
        ];
    }
}
