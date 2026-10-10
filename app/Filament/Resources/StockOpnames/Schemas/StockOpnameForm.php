<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Models\ProductVariant;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('opname_number')
                                    ->label('Opname Number')
                                    ->disabled()
                                    ->dehydrated(false),
                                Select::make('period')
                                    ->label('Period')
                                    ->options(function (Select $component): array {
                                        $record = $component->getRecord();

                                        return self::periodOptions(
                                            $record instanceof StockOpnameSession ? $record : null,
                                        );
                                    })
                                    ->searchable()
                                    ->required(),
                                DatePicker::make('opname_date')
                                    ->label('Opname Date')
                                    ->default(now())
                                    ->required(),
                                Select::make('warehouse_id')
                                    ->label('Location')
                                    ->options(fn (): array => Warehouse::query()
                                        ->saved()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if (blank($state)) {
                                            $set('items', []);

                                            return;
                                        }

                                        $warehouse = Warehouse::find($state);
                                        $variants = ProductVariant::query()
                                            ->eligibleForStocktake($state)
                                            ->with(['product', 'unit'])
                                            ->orderBy('name')
                                            ->get();
                                        $variantIds = $variants->pluck('id');
                                        $stocks = WarehouseStock::query()
                                            ->where('warehouse_id', $state)
                                            ->whereIn('product_variant_id', $variantIds)
                                            ->pluck('stock_available', 'product_variant_id');
                                        $distributedVariantIds = WarehouseStock::query()
                                            ->whereIn('product_variant_id', $variantIds)
                                            ->pluck('product_variant_id')
                                            ->all();

                                        $set('items', $variants->map(function (ProductVariant $variant) use (
                                            $warehouse,
                                            $stocks,
                                            $distributedVariantIds,
                                        ): array {
                                            $systemQty = $stocks->has($variant->id)
                                                ? (string) $stocks->get($variant->id)
                                                : (in_array($variant->id, $distributedVariantIds, true) || $warehouse?->code !== 'WH-FARMING'
                                                    ? 0
                                                    : (string) $variant->stock_available);

                                            return [
                                                'product_variant_id' => $variant->id,
                                                'item_label' => sprintf(
                                                    '%s - %s',
                                                    $variant->product?->name ?? 'Unknown Product',
                                                    $variant->name,
                                                ),
                                                'system_qty' => Quantity::format($systemQty),
                                                'physical_qty' => 0,
                                                'variance_qty' => Quantity::format(Quantity::subtract(0, $systemQty)),
                                                'unit' => $variant->unit?->code ?? $variant->unit?->name ?? '-',
                                            ];
                                        })->all());
                                    }),
                                Select::make('checker_id')
                                    ->label('Checker')
                                    ->options(fn (Get $get): array => self::userOptions($get('witness_id')))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required(),
                                Select::make('witness_id')
                                    ->label('Witness')
                                    ->options(fn (Get $get): array => self::userOptions($get('checker_id')))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->different('checker_id')
                                    ->required(),
                            ])
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Items')
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->relationship(
                                'items',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->with('productVariant.product'),
                            )
                            ->schema([
                                Hidden::make('product_variant_id'),
                                TextInput::make('item_label')
                                    ->label('Item')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function (?string $state, ?StockOpnameItem $record): ?string {
                                        if (filled($state)) {
                                            return $state;
                                        }

                                        $variant = $record?->productVariant;

                                        return $variant
                                            ? sprintf(
                                                '%s - %s',
                                                $variant->product?->name ?? 'Unknown Product',
                                                $variant->name,
                                            )
                                            : null;
                                    }),
                                QuantityInput::make('system_qty')
                                    ->label('System Qty')

                                    ->default(0)
                                    ->disabled()
                                    ->dehydrated(),
                                QuantityInput::make('physical_qty')
                                    ->label('Physical Qty')

                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        $set(
                                            'variance_qty',
                                            is_numeric(Quantity::normalize($state)) ? Quantity::format(Quantity::subtract(Quantity::normalize($state), $get('system_qty') ?? 0)) : null,
                                        );
                                    }),
                                QuantityInput::make('variance_qty')
                                    ->label('Variance')

                                    ->disabled()
                                    ->dehydrated(),
                                TextInput::make('unit')
                                    ->label('Unit')
                                    ->disabled()
                                    ->dehydrated(),
                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(2),
                            ])
                            ->table([
                                TableColumn::make('Item'),
                                TableColumn::make('System Qty'),
                                TableColumn::make('Physical Qty'),
                                TableColumn::make('Variance'),
                                TableColumn::make('Unit'),
                                TableColumn::make('Notes'),
                            ])
                            ->columns(5)
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, string> */
    private static function periodOptions(?StockOpnameSession $record = null): array
    {
        $options = [];
        $start = now()->startOfMonth();
        $minimum = now()->setDate(2026, 10, 1)->startOfMonth();

        if ($start->lessThan($minimum)) {
            $start = $minimum;
        }

        $activeWarehouseIds = Warehouse::query()
            ->saved()
            ->where('is_active', true)
            ->pluck('id');
        $completedPeriods = $activeWarehouseIds->isEmpty()
            ? []
            : StockOpnameSession::query()
                ->whereIn('warehouse_id', $activeWarehouseIds)
                ->where('approval_status', StockOpnameApprovalStatus::Approved)
                ->whereIn('status', [StockOpnameStatus::Approved, StockOpnameStatus::Finalized])
                ->select('period')
                ->groupBy('period')
                ->havingRaw('COUNT(DISTINCT warehouse_id) = ?', [$activeWarehouseIds->count()])
                ->pluck('period')
                ->all();

        for ($index = 0; $index < 25; $index++) {
            $period = $start->copy()->addMonths($index);
            $value = $period->format('Y-m');

            if (! in_array($value, $completedPeriods, true) || $value === $record?->period) {
                $options[$value] = $period->format('F Y');
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function userOptions(?string $excludedId = null): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->when($excludedId, fn ($query) => $query->whereKeyNot($excludedId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
