<?php

namespace App\Filament\Resources\StockMovements\Tables\Actions;

use App\Enums\StockMovementApprovalStatus;
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

class ShipStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('ship')
            ->label('Ship')
            ->icon('heroicon-o-truck')
            ->color('success')
            ->visible(fn (StockMovement $record): bool => ! $record->trashed() && in_array(
                $record->status,
                [StockMovementStatus::Requested, StockMovementStatus::Approved],
                true,
            ) && $record->approval_status === StockMovementApprovalStatus::Approved)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->authorize(fn (StockMovement $record): bool => (auth()->user()?->can('view', $record) ?? false)
                && (auth()->user()?->can('Ship:StockMovement') ?? false))
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
                    'requested_quantity' => $item->quantity,
                    'quantity_shipped' => $item->quantity,
                    'unit' => $item->unit ?? '-',
                ])->all(),
            ])
            ->schema([
                Repeater::make('items')
                    ->label('Items')
                    ->schema([
                        Hidden::make('item_id')->required(),
                        TextInput::make('item')
                            ->label('Item')
                            ->disabled()
                            ->dehydrated(false),
                        QuantityInput::make('requested_quantity')
                            ->label('Requested')
                            ->disabled()
                            ->dehydrated(false),
                        QuantityInput::make('quantity_shipped')
                            ->label('Shipped Qty')

                            ->minValue(0)
                            ->maxValue(fn (Get $get): string => (string) $get('requested_quantity'))
                            ->required(),
                        TextInput::make('unit')
                            ->label('Unit')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->placeholder('Discrepancy reason...')
                            ->rows(2)
                            ->required(fn (Get $get): bool => Quantity::normalize($get('quantity_shipped')) != $get('requested_quantity')),
                    ])
                    ->table([
                        TableColumn::make('Item'),
                        TableColumn::make('Requested'),
                        TableColumn::make('Shipped Qty'),
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
            ->modalHeading('Ship Movement')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Ship')
            ->action(function (StockMovement $record, array $data, Component $livewire): void {
                $record->ship($data['items'] ?? [], Auth::id(), $data['general_notes'] ?? null);
                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Stock movement is now in transit.')
                    ->success()
                    ->send();
            });
    }
}
