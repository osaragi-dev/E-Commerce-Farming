<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMutationSource: string implements HasColor, HasLabel
{
    case Order = 'order';
    case OrderShipped = 'order_shipped';
    case CancelOrder = 'cancel_order';
    case ManualAdjustment = 'manual_adjustment';
    case InitialStock = 'initial_stock';
    case Transfer = 'transfer';
    case CancelTransfer = 'cancel_transfer';
    case Stocktake = 'stocktake';
    case Repack = 'repack';
    case CancelRepack = 'cancel_repack';
    case StockWaste = 'stock_waste';
    case CancelStockWaste = 'cancel_stock_waste';

    /** Retained for historical records created before fulfillment logging was removed. */
    case OrderFulfilled = 'order_fulfilled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Order => 'Order',
            self::OrderShipped => 'Order Shipped',
            self::CancelOrder => 'Cancel Order',
            self::ManualAdjustment => 'Direct Adjustment',
            self::InitialStock => 'Initial Stock',
            self::Transfer => 'Warehouse Transfer',
            self::CancelTransfer => 'Cancel Transfer',
            self::Stocktake => 'Stock Opname',
            self::Repack => 'Convert',
            self::CancelRepack => 'Cancel Convert',
            self::StockWaste => 'Out Waste',
            self::CancelStockWaste => 'Cancel Waste',
            self::OrderFulfilled => 'Order Fulfilled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Order => 'info',
            self::OrderShipped => 'info',
            self::CancelOrder => 'success',
            self::ManualAdjustment => 'warning',
            self::InitialStock => 'gray',
            self::Transfer => 'info',
            self::CancelTransfer => 'gray',
            self::Stocktake => 'warning',
            self::Repack => 'info',
            self::CancelRepack => 'success',
            self::StockWaste => 'danger',
            self::CancelStockWaste => 'success',
            self::OrderFulfilled => 'success',
        };
    }
}
