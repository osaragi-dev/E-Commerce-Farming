<?php

namespace App\Observers;

use App\Enums\RecordStatus;
use App\Models\WarehouseStock;
use App\Notifications\StockLevelAlertForAdmins;

class WarehouseStockObserver
{
    public function created(WarehouseStock $stock): void
    {
        $this->syncAlertStatus($stock);
    }

    public function retrieved(WarehouseStock $stock): void
    {
        $this->syncAlertStatus($stock);
    }

    public function updated(WarehouseStock $stock): void
    {
        if (! $stock->wasChanged('stock_available')) {
            return;
        }

        $this->syncAlertStatus($stock);
    }

    private function syncAlertStatus(WarehouseStock $stock): void
    {
        $warehouse = $stock->warehouse;
        $variant = $stock->productVariant;

        if ($warehouse === null
            || ! $warehouse->is_pos
            || ! $warehouse->is_active
            || ($warehouse->record_status !== RecordStatus::Saved
                && $warehouse->record_status !== RecordStatus::Saved->value)
            || $variant === null) {
            return;
        }

        $after = (string) $stock->stock_available;
        $threshold = (string) $variant->low_stock_threshold;
        $isLowStock = $after > 0 && $after <= $threshold;
        $alertStatus = $after == 0
            ? 'out_of_stock'
            : ($isLowStock ? 'low_stock' : null);

        if ($alertStatus === null) {
            if ($stock->stock_alert_status !== null) {
                $stock->forceFill(['stock_alert_status' => null])->saveQuietly();
            }

            return;
        }

        if ($stock->stock_alert_status === $alertStatus) {
            return;
        }

        StockLevelAlertForAdmins::send($stock, outOfStock: $alertStatus === 'out_of_stock');
        $stock->forceFill(['stock_alert_status' => $alertStatus])->saveQuietly();
    }
}
