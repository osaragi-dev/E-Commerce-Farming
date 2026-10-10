<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\StockAdjustmentSource;
use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockAdjustment;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewStockOpname extends ViewRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected static ?string $title = 'View Stock Opname';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createStockAdjustments')
                ->label('Create Adjustment')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (StockOpnameSession $record): bool => $record->status === StockOpnameStatus::Approved
                    && $record->approval_status === StockOpnameApprovalStatus::Approved
                    && $record->items()
                        ->where('variance_qty', '!=', 0)
                        ->whereNull('stock_adjustment_id')
                        ->exists())
                ->authorize(fn (StockOpnameSession $record): bool => $record->status === StockOpnameStatus::Approved
                    && $record->approval_status === StockOpnameApprovalStatus::Approved
                    && auth()->user()?->can('create', StockAdjustment::class) === true
                    && ! SystemFreezeState::current()->is_frozen)
                ->requiresConfirmation()
                ->modalHeading('Create Adjustment')
                ->modalDescription('Create stock adjustments for the remaining variance items in this ended stock opname?')
                ->action(fn (StockOpnameSession $record) => redirect()->to(StockAdjustmentResource::getUrl('create', [
                    'source' => StockAdjustmentSource::StockOpname->value,
                    'stock_opname_session_id' => $record->id,
                ]))),
            ApproveWorkflowAction::make(),
            RejectWorkflowAction::make(),
            ReviseWorkflowAction::make(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing([
            'warehouse',
            'checker',
            'witness',
            'createdBy',
            'updatedBy',
            'items.productVariant.product',
            'items.productVariant.unit',
            'items.stockAdjustment',
            'logs.changedBy',
            'approvalLogs.actedBy',
        ]);
    }
}
