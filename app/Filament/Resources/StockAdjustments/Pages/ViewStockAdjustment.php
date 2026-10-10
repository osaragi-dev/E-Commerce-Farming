<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\SystemFreezeGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class ViewStockAdjustment extends ViewRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected static ?string $title = 'View Stock Adjustment';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            ApproveWorkflowAction::make(),
            Action::make('post')
                ->label('Post')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->visible(fn (StockAdjustment $record): bool => $record->isPostable())
                ->authorize(fn (StockAdjustment $record): bool => $record->isPostable()
                    && (auth()->user()?->can('update', $record) ?? false)
                    && ! app(SystemFreezeGuard::class)->isFrozen())
                ->requiresConfirmation()
                ->action(function (StockAdjustment $record): void {
                    $record->applyToStock(auth()->id());
                    $this->record = $record->fresh(array_keys($record->getRelations()));
                    $this->dispatch('refresh-page');

                    Notification::make()
                        ->title('Stock adjustment posted.')
                        ->success()
                        ->send();
                }),
            ReviseWorkflowAction::make(),
            RejectWorkflowAction::make(),
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
            'adjustedBy',
            'appliedBy',
            'approvalLogs.actedBy',
            'approvalSetting.levels',
            'createdBy',
            'productVariant.product',
            'productVariant.unit',
            'stockMutations',
            'stockOpnameItems.session',
            'stockOpnameItems.productVariant.product',
            'stockOpnameItems.productVariant.unit',
            'updatedBy',
            'warehouse',
        ]);
    }
}
