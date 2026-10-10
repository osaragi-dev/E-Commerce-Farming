<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Enums\StockMovementStatus;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\StockMovement;
use App\Models\SystemFreezeState;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->databaseTransaction()
                ->modalWidth(Width::FiveExtraLarge)
                ->mutateDataUsing(fn (array $data, array $arguments): array => [
                    ...$data,
                    'status' => ($arguments['draft'] ?? false)
                        ? StockMovementStatus::Draft
                        : StockMovementStatus::Requested,
                ])
                ->extraModalFooterActions(fn (CreateAction $action): array => [
                    $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                        ->label('Save Draft')
                        ->icon('heroicon-o-document')
                        ->color('gray'),
                ])
                ->after(function (StockMovement $record): void {
                    if ($record->status !== StockMovementStatus::Draft) {
                        $record->reserveRequestedStock();
                    }
                })
                ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen),
        ];
    }
}
