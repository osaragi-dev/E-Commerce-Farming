<?php

namespace App\Filament\Resources\StockWastes\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\StockWastes\StockWasteResource;
use App\Models\StockWaste;
use App\Services\SystemFreezeGuard;
use Filament\Resources\Pages\ListRecords;

class ListStockWastes extends ListRecords
{
    protected static string $resource = StockWasteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LifecycleCreateAction::make()
                ->databaseTransaction()
                ->after(function (StockWaste $record): void {
                    if ($record->isPostable()) {
                        $record->post(auth()->id());
                    }
                })
                ->disabled(fn (): bool => app(SystemFreezeGuard::class)->isFrozen()),
        ];
    }
}
