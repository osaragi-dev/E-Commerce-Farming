<?php

namespace App\Filament\Resources\StockMovements\Tables\Actions;

use App\Enums\StockMovementStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Models\StockMovement;
use App\Models\SystemFreezeState;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ReceiveStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('receive')
            ->label('Receive')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (StockMovement $record): bool => ! $record->trashed()
                && $record->status === StockMovementStatus::Shipped)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->authorize(fn (StockMovement $record): bool => (auth()->user()?->can('view', $record) ?? false)
                && (auth()->user()?->can('Receive:StockMovement') ?? false))
            ->fillForm(fn (StockMovement $record): array => [
                'items' => $record->loadMissing([
                    'items.productVariant.product',
                ])->items->map(fn ($item): array => [
                    'item_id' => $item->id,
                    'item' => sprintf(
                        '%s - %s',
                        $item->productVariant?->product?->name ?? 'Unknown Product',
                        $item->productVariant?->name ?? 'Unknown Variant',
                    ),
                    'shipped_quantity' => $item->quantity_shipped ?? 0,
                    'quantity_received' => $item->quantity_shipped ?? 0,
                    'unit' => $item->unit ?? '-',
                ])->all(),
            ])
            ->schema([
                Repeater::make('items')
                    ->label('Items')
                    ->schema([
                        Hidden::make('item_id')->required(),
                        TextInput::make('item')->label('Item')->disabled()->dehydrated(false),
                        QuantityInput::make('shipped_quantity')->label('Shipped')->disabled()->dehydrated(false),
                        QuantityInput::make('quantity_received')
                            ->label('Received Qty')

                            ->minValue(0)
                            ->maxValue(fn (Get $get): string => (string) $get('shipped_quantity'))
                            ->required(),
                        TextInput::make('unit')->label('Unit')->disabled()->dehydrated(false),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->placeholder('Discrepancy reason...')
                            ->rows(2)
                            ->required(fn (Get $get): bool => Quantity::normalize($get('quantity_received')) != $get('shipped_quantity')),
                    ])
                    ->table([
                        TableColumn::make('Item'),
                        TableColumn::make('Shipped'),
                        TableColumn::make('Received Qty'),
                        TableColumn::make('Unit'),
                        TableColumn::make('Notes'),
                    ])
                    ->columns(5)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columnSpanFull(),
                Textarea::make('general_notes')
                    ->label('General Notes')
                    ->placeholder('Additional notes...')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->modalHeading('Receive Movement')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Receive')
            ->action(function (StockMovement $record, array $data, Component $livewire): void {
                $record->receive($data['items'] ?? [], Auth::id(), $data['general_notes'] ?? null);
                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Stock movement received successfully.')
                    ->success()
                    ->send();
            });
    }
}
