<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected static ?string $title = 'View Goods Receipt';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [ApproveWorkflowAction::make(), ReviseWorkflowAction::make(), RejectWorkflowAction::make()];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadRelations();
    }

    private function loadRelations(): void
    {
        $this->record->loadMissing([
            'purchaseOrder.warehouse', 'purchaseOrder.vendor', 'items.purchaseOrderItem.item.unit',
            'approvalLogs.actedBy', 'approvalSetting.levels', 'checkedBy',
        ]);
    }
}
