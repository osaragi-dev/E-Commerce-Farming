<?php

namespace App\Enums;

use App\Models\DirectOrder;
use App\Models\GoodsReceipt;
use App\Models\PaymentVoidRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use App\Models\PurchaseRequest;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\Vendor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

enum ApprovalModule: string implements HasLabel
{
    case VoidPayment = 'void_payment';
    case StockAdjustment = 'stock_adjustment';
    case StockMovement = 'stock_movement';
    case StockOpname = 'stock_opname';
    case PurchaseRequest = 'purchase_request';
    case DirectOrder = 'direct_order';
    case Vendor = 'vendor';
    case PurchaseOrder = 'purchase_order';
    case PurchaseOrderClose = 'purchase_order_close';
    case GoodsReceipt = 'goods_receipt';

    public function getLabel(): string
    {
        return match ($this) {
            self::VoidPayment => 'Void Payment',
            self::StockAdjustment => 'Stock Adjustment',
            self::StockMovement => 'Stock Movement',
            self::StockOpname => 'Stock Opname',
            self::PurchaseRequest => 'Purchase Request',
            self::DirectOrder => 'Direct Order',
            self::Vendor => 'Vendor',
            self::PurchaseOrder => 'Purchase Order',
            self::PurchaseOrderClose => 'Purchase Order Close',
            self::GoodsReceipt => 'Goods Receipt',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $module): array => [$module->value => $module->getLabel()])
            ->all();
    }

    public static function forModel(Model $model): self
    {
        return match (true) {
            $model instanceof PaymentVoidRequest => self::VoidPayment,
            $model instanceof StockAdjustment => self::StockAdjustment,
            $model instanceof StockMovement => self::StockMovement,
            $model instanceof StockOpnameSession => self::StockOpname,
            $model instanceof PurchaseRequest => self::PurchaseRequest,
            $model instanceof DirectOrder => self::DirectOrder,
            $model instanceof Vendor => self::Vendor,
            $model instanceof PurchaseOrder => self::PurchaseOrder,
            $model instanceof PurchaseOrderCloseRequest => self::PurchaseOrderClose,
            $model instanceof GoodsReceipt => self::GoodsReceipt,
            default => throw new \InvalidArgumentException('This model has no approval module.'),
        };
    }
}
