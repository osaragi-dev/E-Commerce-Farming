<?php

namespace App\Filament\Resources\StockWastes\Schemas;

use App\Enums\StockWasteType;
use App\Filament\Forms\Components\QuantityInput;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockWasteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('waste_number')
                ->label('Waste Number')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('Generated after creation'),
            Select::make('warehouse_id')
                ->label('Location')
                ->options(fn (): array => Warehouse::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('product_variant_id', null);
                    $set('quantity', null);
                }),
            DatePicker::make('waste_date')->required()->default(today()),
            Select::make('pic_user_id')
                ->label('PIC User')
                ->options(fn (): array => User::query()->saved()->where('is_active', true)
                    ->whereHas('roles', fn ($query) => $query->where('name', '!=', 'customer'))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            Select::make('product_variant_id')
                ->label('Item')
                ->options(function (Get $get): array {
                    $warehouseId = $get('warehouse_id');

                    if (blank($warehouseId)) {
                        return [];
                    }

                    $isFarmingWarehouse = Warehouse::query()
                        ->whereKey($warehouseId)
                        ->where('code', 'WH-FARMING')
                        ->exists();

                    return ProductVariant::query()
                        ->inventoryVisible()
                        ->where(function ($query) use ($warehouseId, $isFarmingWarehouse): void {
                            $query->whereHas('warehouseStocks', fn ($stockQuery) => $stockQuery
                                ->where('warehouse_id', $warehouseId)
                                ->where('stock_available', '>', 0));

                            if ($isFarmingWarehouse) {
                                $query->orWhere(fn ($legacyQuery) => $legacyQuery
                                    ->where('stock_available', '>', 0)
                                    ->whereDoesntHave('warehouseStocks'));
                            }
                        })
                        ->with([
                            'product',
                            'warehouseStocks' => fn ($query) => $query->where('warehouse_id', $warehouseId),
                        ])
                        ->withCount('warehouseStocks')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(function (ProductVariant $variant) use ($isFarmingWarehouse): array {
                            $available = $variant->warehouseStocks->first()?->stock_available
                                ?? ($isFarmingWarehouse && $variant->warehouse_stocks_count === 0 ? $variant->stock_available : 0);

                            return [
                                $variant->id => sprintf(
                                    '%s - %s (available: %s)',
                                    $variant->product->name,
                                    $variant->name,
                                    Quantity::format($available),
                                ),
                            ];
                        })
                        ->all();
                })
                ->searchable()
                ->live()
                ->required(),
            QuantityInput::make('quantity')
                ->required()
                ->rules(fn (Get $get): array => [
                    ...Quantity::rules(),
                    ...(($available = self::availableStock($get('warehouse_id'), $get('product_variant_id'))) === null
                        ? []
                        : ['max:'.$available]),
                ])
                ->dehydrateStateUsing(fn ($state): string => Quantity::validated($state)),
            Placeholder::make('unit_display')
                ->label('Unit')
                ->content(fn (Get $get): string => ProductVariant::query()->with('unit')->find($get('product_variant_id'))?->unit?->name ?? '-'),
            Select::make('waste_type')
                ->options(collect(StockWasteType::cases())
                    ->reject(fn (StockWasteType $type): bool => $type === StockWasteType::Overproduction)
                    ->mapWithKeys(fn (StockWasteType $type): array => [$type->value => $type->getLabel()])
                    ->all())
                ->required(),
            Textarea::make('reason')->rows(3)->maxLength(2000)->columnSpanFull(),
            FileUpload::make('photo_path')
                ->label('Photo')
                ->image()
                ->maxSize(4096)
                ->disk('public')
                ->directory('stock-wastes')
                ->columnSpanFull(),
            Textarea::make('notes')->rows(3)->maxLength(4000)->columnSpanFull(),
        ]);
    }

    private static function availableStock(mixed $warehouseId, mixed $productVariantId): ?string
    {
        if (blank($warehouseId) || blank($productVariantId)) {
            return null;
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        $variant = ProductVariant::query()
            ->inventoryVisible()
            ->withCount('warehouseStocks')
            ->find($productVariantId);

        if (! $warehouse || ! $variant) {
            return null;
        }

        $warehouseStock = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available');

        if ($warehouseStock !== null) {
            return (string) $warehouseStock;
        }

        if ($warehouse->code !== 'WH-FARMING' || $variant->warehouse_stocks_count > 0) {
            return '0.000';
        }

        return (string) $variant->stock_available;
    }
}
