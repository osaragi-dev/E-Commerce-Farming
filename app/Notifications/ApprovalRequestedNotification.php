<?php

namespace App\Notifications;

use App\Enums\ApprovalModule;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\Vendors\VendorResource;
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
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class ApprovalRequestedNotification extends Notification
{
    public function __construct(public Model $record) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $module = ApprovalModule::forModel($this->record);
        $recordLabel = $this->recordLabel();

        return [
            'format' => 'filament',
            'title' => 'Approval requested',
            'body' => "{$recordLabel} requires your approval at level {$this->record->approval_level}.",
            'icon' => 'heroicon-o-check-circle',
            'color' => 'warning',
            'duration' => 'persistent',
            'module' => $module->value,
            'record_label' => $recordLabel,
            'approval_level' => $this->record->approval_level,
            'actions' => [
                Action::make('reviewApproval')
                    ->label('Review request')
                    ->url($this->recordUrl())
                    ->markAsRead()
                    ->toArray(),
            ],
        ];
    }

    private function recordLabel(): string
    {
        return match (true) {
            $this->record instanceof StockAdjustment => 'Stock Adjustment '.($this->record->adjustment_number ?? ''),
            $this->record instanceof StockMovement => 'Stock Movement '.($this->record->movement_number ?? ''),
            $this->record instanceof StockOpnameSession => 'Stock Opname '.($this->record->opname_number ?? ''),
            $this->record instanceof PaymentVoidRequest => 'Void Payment '.($this->record->payment?->order?->order_number ?? ''),
            $this->record instanceof PurchaseRequest => 'Purchase Request '.($this->record->pr_number ?? ''),
            $this->record instanceof DirectOrder => 'Direct Order '.($this->record->do_number ?? ''),
            $this->record instanceof Vendor => 'Vendor '.$this->record->vendor_code,
            $this->record instanceof PurchaseOrder => 'Purchase Order '.($this->record->po_number ?? ''),
            $this->record instanceof PurchaseOrderCloseRequest => 'Close Purchase Order '.($this->record->purchaseOrder?->po_number ?? ''),
            $this->record instanceof GoodsReceipt => 'Goods Receipt '.($this->record->receipt_number ?? ''),
            default => 'Approval request',
        };
    }

    private function recordUrl(): string
    {
        return match (true) {
            $this->record instanceof StockAdjustment => StockAdjustmentResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof StockMovement => StockMovementResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof StockOpnameSession => StockOpnameResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof PaymentVoidRequest => PaymentResource::getUrl('view', ['record' => $this->record->payment]),
            $this->record instanceof PurchaseRequest => PurchaseRequestResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof DirectOrder => DirectOrderResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof Vendor => VendorResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof PurchaseOrder => PurchaseOrderResource::getUrl('view', ['record' => $this->record]),
            $this->record instanceof PurchaseOrderCloseRequest => PurchaseOrderResource::getUrl('view', ['record' => $this->record->purchaseOrder]),
            $this->record instanceof GoodsReceipt => GoodsReceiptResource::getUrl('view', ['record' => $this->record]),
            default => url('/admin'),
        };
    }
}
