<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListStockAdjustments extends ListRecords
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create Stock Adjustment')
                ->icon('heroicon-o-plus')
                ->url(StockAdjustmentResource::getUrl('create'))
                ->authorize(fn (): bool => StockAdjustmentResource::canCreate())
                ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen),
        ];
    }
}
