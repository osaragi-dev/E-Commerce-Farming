<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Filament\Forms\Components\QuantityInput;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Quantity;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema, bool $withItemsRelationship = true): Schema
    {
        $itemsRepeater = Repeater::make('items')
            ->required()
            ->minItems(1)
            ->schema([
                Select::make('product_variant_id')
                    ->label('Item')
                    ->options(fn (): array => self::variantOptions())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $unit = ProductVariant::query()->with('unit')->find($state)?->unit;
                        $set('unit', $unit?->code ?? $unit?->name);
                    }),
                Placeholder::make('available_stock')
                    ->label('Available Stock')
                    ->content(fn (Get $get): string => (string) self::availableStock(
                        $get('../../from_warehouse_id'),
                        $get('product_variant_id'),
                    )),
                QuantityInput::make('quantity')
                    ->label('Quantity')

                    ->minValue(0.001)
                    ->required()
                    ->rules([
                        function (Get $get): Closure {
                            return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $availableStock = self::availableStock(
                                    $get('../../from_warehouse_id'),
                                    $get('product_variant_id'),
                                );

                                if (Quantity::normalize($value) > $availableStock) {
                                    $fail("The requested quantity cannot exceed the available stock ({$availableStock}).");
                                }
                            };
                        },
                    ]),
                TextInput::make('unit')
                    ->label('Unit')
                    ->disabled()
                    ->dehydrated()
                    ->required(),
            ])
            ->table([
                TableColumn::make('Item'),
                TableColumn::make('Available Stock'),
                TableColumn::make('Quantity'),
                TableColumn::make('Unit'),
            ])
            ->columns(4)
            ->addActionLabel('Add Item')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();

        if ($withItemsRelationship) {
            $itemsRepeater->relationship('items');
        }

        return $schema
            ->components([
                Section::make('Movement Information')
                    ->schema([
                        TextInput::make('movement_number')
                            ->label('Movement Number')
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('movement_date')
                            ->label('Movement Date')
                            ->default(now())
                            ->required(),
                        Select::make('from_warehouse_id')
                            ->label('From Location')
                            ->options(fn (): array => self::warehouseOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('to_warehouse_id')
                            ->label('To Location')
                            ->options(fn (Get $get): array => self::warehouseOptions($get('from_warehouse_id')))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('requested_by')
                            ->label('Requested By')
                            ->options(fn (): array => self::userOptions())
                            ->default(fn (): ?string => auth()->id())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->placeholder('Optional notes...')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Items')
                    ->schema([
                        $itemsRepeater,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, string> */
    private static function warehouseOptions(?string $excludedId = null): array
    {
        return Warehouse::query()
            ->saved()
            ->where('is_active', true)
            ->when($excludedId, fn ($query) => $query->whereKeyNot($excludedId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /** @return array<string, string> */
    private static function userOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /** @return array<string, string> */
    private static function variantOptions(): array
    {
        return ProductVariant::query()
            ->inventoryVisible()
            ->with('product')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => sprintf(
                    '%s - %s',
                    $variant->product?->name ?? 'Unknown Product',
                    $variant->name,
                ),
            ])
            ->toArray();
    }

    private static function availableStock(?string $warehouseId, ?string $variantId): string
    {
        if (blank($warehouseId) || blank($variantId)) {
            return '0';
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        $variant = ProductVariant::query()->find($variantId);

        if (! $warehouse || ! $variant) {
            return '0';
        }

        return StockMutation::availableForWarehouse($variant, $warehouse);
    }
}
