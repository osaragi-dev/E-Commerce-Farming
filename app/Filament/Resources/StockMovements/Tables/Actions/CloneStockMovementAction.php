<?php

namespace App\Filament\Resources\StockMovements\Tables\Actions;

use App\Enums\StockMovementStatus;
use App\Filament\Resources\StockMovements\Schemas\StockMovementForm;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class CloneStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('clone')
            ->label('Clone')
            ->icon('heroicon-o-document-duplicate')
            ->color('primary')
            ->databaseTransaction()
            ->visible(fn (StockMovement $record): bool => ! $record->trashed())
            ->authorize(fn (StockMovement $record): bool => (auth()->user()?->can('view', $record) ?? false)
                && (auth()->user()?->can('create', StockMovement::class) ?? false))
            ->fillForm(fn (StockMovement $record): array => [
                'movement_date' => now()->toDateString(),
                'from_warehouse_id' => $record->from_warehouse_id,
                'to_warehouse_id' => $record->to_warehouse_id,
                'requested_by' => $record->requested_by ?? auth()->id(),
                'notes' => $record->notes ?? $record->note,
                'items' => $record->loadMissing('items')->items->map(fn ($item): array => [
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                ])->all(),
            ])
            ->schema(fn (Schema $schema): Schema => StockMovementForm::configure($schema, withItemsRelationship: false))
            ->modalHeading('Clone Stock Movement')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Create')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                    ->label('Save as Draft')
                    ->icon('heroicon-o-document')
                    ->color('gray'),
            ])
            ->action(function (array $data, array $arguments): void {
                $isDraft = (bool) ($arguments['draft'] ?? false);

                $clone = StockMovement::query()->create([
                    'movement_date' => $data['movement_date'],
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id' => $data['to_warehouse_id'],
                    'requested_by' => $data['requested_by'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                    'status' => $isDraft ? StockMovementStatus::Draft : StockMovementStatus::Requested,
                ]);

                foreach ($data['items'] ?? [] as $item) {
                    $clone->items()->create([
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'] ?? null,
                    ]);
                }

                if (! $isDraft) {
                    $clone->reserveRequestedStock(auth()->id());
                }

                Notification::make()
                    ->title($isDraft ? 'Stock movement cloned as draft.' : 'Stock movement cloned successfully.')
                    ->success()
                    ->send();
            });
    }
}
