<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMovements\Tables\Actions\CancelStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\ReceiveStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\RejectStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\ShipStockMovementAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class ViewStockMovement extends ViewRecord
{
    protected static string $resource = StockMovementResource::class;

    protected static ?string $title = 'View Stock Movement';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            ApproveWorkflowAction::make(),
            RejectWorkflowAction::make(),
            ReviseWorkflowAction::make(),
            ShipStockMovementAction::make(),
            ReceiveStockMovementAction::make(),
            RejectStockMovementAction::make(),
            CancelStockMovementAction::make(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadRecordRelations();
    }

    #[On('refresh-page')]
    public function refreshPage(): void
    {
        $this->record = $this->getRecord()->fresh();
        $this->loadRecordRelations();
    }

    private function loadRecordRelations(): void
    {
        $this->record->loadMissing([
            'fromWarehouse',
            'toWarehouse',
            'requestedBy',
            'createdBy',
            'updatedBy',
            'deletedBy',
            'items.productVariant.product',
            'items.productVariant.unit',
            'logs.changedBy',
            'approvalLogs.actedBy',
        ]);
    }
}
