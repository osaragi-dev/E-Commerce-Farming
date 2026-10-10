<?php

namespace App\Filament\Resources\StockWastes\Pages;

use App\Filament\Resources\StockWastes\StockWasteResource;
use App\Models\StockWaste;
use App\Services\SystemFreezeGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewStockWaste extends ViewRecord
{
    protected static string $resource = StockWasteResource::class;

    protected static ?string $title = 'Waste Records';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (StockWaste $record): bool => $record->isCancellable())
                ->authorize(fn (StockWaste $record): bool => $record->isCancellable()
                    && (auth()->user()?->can('update', $record) ?? false)
                    && ! app(SystemFreezeGuard::class)->isFrozen())
                ->requiresConfirmation()
                ->action(function (StockWaste $record): void {
                    $record->cancel(auth()->id());
                    $this->record = $record->fresh();

                    Notification::make()->title('Waste record cancelled and stock restored.')->success()->send();
                }),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadRecordRelations();
    }

    private function loadRecordRelations(): void
    {
        $this->record->loadMissing([
            'createdBy',
            'updatedBy',
            'postedBy',
            'productVariant.product',
            'productVariant.unit',
            'picUser',
            'stockMutation',
            'warehouse',
        ]);
    }
}
