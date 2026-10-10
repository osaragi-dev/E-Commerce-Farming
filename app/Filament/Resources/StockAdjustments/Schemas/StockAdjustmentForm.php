<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Filament\Forms\Components\QuantityInput;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockOpnameSession;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('adjustment_number')
                    ->label('Adjustment Number')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Generated after creation')
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Select::make('source')
                    ->label('Source')
                    ->options(StockAdjustmentSource::class)
                    ->default(StockAdjustmentSource::DirectAdjustment)
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation !== 'create')
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Select::make('stock_opname_session_id')
                    ->label('Stock Opname')
                    ->options(fn (): array => self::eligibleOpnameOptions())
                    ->searchable()
                    ->live()
                    ->required(fn (Get $get): bool => self::sourceValue($get('source')) === StockAdjustmentSource::StockOpname->value)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create'
                        && self::sourceValue($get('source')) === StockAdjustmentSource::StockOpname->value)
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $session = filled($state)
                            ? StockOpnameSession::query()->with(['warehouse', 'items.productVariant.product', 'items.productVariant.unit'])
                                ->find($state)
                            : null;

                        $set('items', $session ? self::opnameItemData($session) : []);
                    }),
                DatePicker::make('created_at')
                    ->label('Adjustment Date')
                    ->default(now())
                    ->native(false)
                    ->required()
                    ->helperText('Can be backdated.'),
                Repeater::make('items')
                    ->label('Items')
                    ->schema([
                        Hidden::make('stock_opname_item_id')->required(),
                        TextInput::make('item_label')->label('Item')->disabled()->dehydrated(false),
                        QuantityInput::make('system_qty')->label('System Qty')->disabled()->dehydrated(),
                        QuantityInput::make('adjusted_qty')
                            ->label('Adjusted Qty')
                            ->minValue(0)
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $adjustedQty = Quantity::normalize($state);
                                $systemQty = Quantity::normalize($get('system_qty'));

                                if (! is_numeric($adjustedQty) || ! is_numeric($systemQty)) {
                                    $set('variance_qty', null);

                                    return;
                                }

                                $variance = Quantity::subtract($adjustedQty, $systemQty);
                                $set('variance_qty', Quantity::format($variance));
                                $set('type', $variance >= 0
                                    ? StockAdjustmentType::Tambah->value
                                    : StockAdjustmentType::Kurang->value);
                            }),
                        QuantityInput::make('variance_qty')->label('Variance Qty')->disabled()->dehydrated(),
                        TextInput::make('unit')->label('Unit')->disabled()->dehydrated(),
                        Select::make('type')
                            ->label('Adjustment Type')
                            ->options(StockAdjustmentType::class)
                            ->placeholder('No adjustment')
                            ->disabled()
                            ->dehydrated(),
                        Textarea::make('notes')->label('Notes')->rows(2),
                    ])
                    ->table([
                        TableColumn::make('Item')->width('24%'),
                        TableColumn::make('System Qty')->width('10%')->alignEnd(),
                        TableColumn::make('Adjusted Qty')->width('11%')->alignEnd(),
                        TableColumn::make('Variance Qty')->width('11%')->alignEnd(),
                        TableColumn::make('Unit')->width('8%')->alignCenter(),
                        TableColumn::make('Adjustment Type')->width('15%')->alignCenter(),
                        TableColumn::make('Notes')->width('21%'),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->defaultItems(0)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create'
                        && self::sourceValue($get('source')) === StockAdjustmentSource::StockOpname->value),
                Select::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(fn () => Warehouse::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => Warehouse::query()->where('code', 'WH-FARMING')->value('id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value)
                    ->afterStateUpdated(function (Set $set): void {
                        $set('product_variant_id', null);
                    }),
                Select::make('product_variant_id')
                    ->label('Product Variant')
                    ->options(function (callable $get): array {
                        $warehouseId = $get('warehouse_id')
                            ?: Warehouse::query()->where('code', 'WH-FARMING')->value('id');

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
                                $query->whereHas('warehouseStocks', function ($query) use ($warehouseId): void {
                                    $query
                                        ->where('warehouse_id', $warehouseId)
                                        ->where(function ($query): void {
                                            $query
                                                ->where('stock_available', '>', 0)
                                                ->orWhere('stock_reserved', '>', 0)
                                                ->orWhere('stock_in_transit', '>', 0)
                                                ->orWhere('stock_out', '>', 0);
                                        });
                                });

                                if ($isFarmingWarehouse) {
                                    $query->orWhere(function ($legacyQuery): void {
                                        $legacyQuery
                                            ->where('stock_available', '>', 0)
                                            ->whereDoesntHave('warehouseStocks');
                                    });
                                }
                            })
                            ->with([
                                'product',
                                'warehouseStocks' => fn ($query) => $query->where('warehouse_id', $warehouseId),
                            ])
                            ->get()
                            ->mapWithKeys(function (ProductVariant $variant) use ($isFarmingWarehouse): array {
                                $available = $variant->warehouseStocks->first()?->stock_available
                                    ?? ($isFarmingWarehouse ? $variant->stock_available : 0);
                                $available = Quantity::format($available);

                                return [
                                    $variant->id => "{$variant->product?->name} - {$variant->name} (available: {$available})",
                                ];
                            })->all();
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                // Placeholder read-only — cuma buat kasih tau admin satuan
                // dari variant yang dipilih (sesuai master produk), tidak
                // bisa diedit dan tidak disimpan ke database.
                Placeholder::make('unit_display')
                    ->label('Unit')
                    ->content(function (callable $get): string {
                        $variantId = $get('product_variant_id');

                        if (blank($variantId)) {
                            return '-';
                        }

                        $variant = ProductVariant::with('unit')->find($variantId);

                        return $variant?->unit?->name ?? '-';
                    })
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                Radio::make('type')
                    ->label('Type')
                    ->options(StockAdjustmentType::class)
                    ->inline()
                    ->required()
                    ->live()
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                QuantityInput::make('quantity')

                    ->required()
                    ->minValue(0.001)
                    ->maxValue(function (callable $get): string|int|null {
                        $type = $get('type');
                        $type = $type instanceof StockAdjustmentType ? $type->value : $type;

                        return $type === StockAdjustmentType::Kurang->value
                            ? WarehouseStock::query()
                                ->where('warehouse_id', $get('warehouse_id'))
                                ->where('product_variant_id', $get('product_variant_id'))
                                ->value('stock_available')
                                ?? (Warehouse::find($get('warehouse_id'))?->code === 'WH-FARMING'
                                    ? ProductVariant::find($get('product_variant_id'))?->stock_available
                                    : 0)
                            : null;
                    })
                    // TCS revision (22/8): sebelumnya cuma update pas
                    // klik keluar field (onBlur), bikin preview Final
                    // Stock kelihatan "macet" di 0 kalau belum sempat
                    // klik ke tempat lain. Diganti live() biasa biar
                    // update tiap ketikan.
                    ->live()
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                // TCS revision (20 Aug): preview stok sebelum & sesudah
                // adjustment ini, biar admin bisa cek dulu sebelum Save.
                // Cuma tampilan (tidak disimpan ke database).
                Placeholder::make('current_stock')
                    ->label('Current Stock')
                    ->content(function (callable $get, ?StockAdjustment $record, string $operation): string {
                        if ($operation === 'view') {
                            $mutation = $record?->stockMutations()->first();

                            return $mutation ? Quantity::format($mutation->stock_before) : '-';
                        }

                        $variantId = $get('product_variant_id');

                        if (blank($variantId)) {
                            return '-';
                        }

                        $variant = ProductVariant::find($variantId);
                        $stock = WarehouseStock::query()
                            ->where('warehouse_id', $get('warehouse_id'))
                            ->where('product_variant_id', $variantId)
                            ->value('stock_available');

                        return $variant ? Quantity::format($stock ?? $variant->stock_available) : '-';
                    })
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                Placeholder::make('final_stock')
                    ->label('Final Stock')
                    ->content(function (callable $get, ?StockAdjustment $record, string $operation): string {
                        if ($operation === 'view') {
                            $mutation = $record?->stockMutations()->first();

                            return $mutation ? Quantity::format($mutation->stock_after) : '-';
                        }

                        $variantId = $get('product_variant_id');
                        $type = $get('type');
                        $quantity = Quantity::normalize($get('quantity'));
                        if (! is_numeric($quantity)) {
                            return '-';
                        }

                        if (blank($variantId) || blank($type) || blank($quantity)) {
                            return '-';
                        }

                        $variant = ProductVariant::find($variantId);

                        if (! $variant) {
                            return '-';
                        }

                        // Ketemu akar masalahnya: di halaman View/Edit,
                        // $get('type') balikin OBJEK Enum PHP (karena
                        // sudah di-cast lewat Model), bukan teks 'tambah'
                        // biasa kayak waktu Create. Disamakan dulu jadi
                        // teks sebelum dibandingkan.
                        $typeValue = $type instanceof StockAdjustmentType ? $type->value : $type;

                        $delta = $typeValue === StockAdjustmentType::Tambah->value
                            ? $quantity
                            : Quantity::subtract(0, $quantity);

                        $stock = WarehouseStock::query()
                            ->where('warehouse_id', $get('warehouse_id'))
                            ->where('product_variant_id', $variantId)
                            ->value('stock_available');

                        $stock ??= Warehouse::find($get('warehouse_id'))?->code === 'WH-FARMING'
                            ? $variant->stock_available
                            : 0;

                        return Quantity::format(Quantity::add($stock, $delta));
                    })
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
                Textarea::make('note')
                    ->label('Additional Note')
                    ->rows(3)
                    ->columnSpanFull()
                    ->visible(fn (Get $get, string $operation): bool => $operation !== 'create'
                        || self::sourceValue($get('source')) !== StockAdjustmentSource::StockOpname->value),
            ])
            ->columns(2);
    }

    /** @return array<string, string> */
    public static function eligibleOpnameOptions(): array
    {
        return StockOpnameSession::query()
            ->where('status', 'approved')
            ->where('approval_status', 'approved')
            ->whereHas('items', fn ($query) => $query->where('variance_qty', '!=', 0)->whereNull('stock_adjustment_id'))
            ->with('warehouse')
            ->orderByDesc('opname_date')
            ->get()
            ->mapWithKeys(fn (StockOpnameSession $session): array => [
                $session->id => $session->opname_number.' - '.($session->warehouse?->name ?? 'Unknown Location'),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function opnameItemData(StockOpnameSession $session): array
    {
        return $session->items
            ->map(function ($item): array {
                $variant = $item->productVariant;

                return [
                    'stock_opname_item_id' => $item->id,
                    'item_label' => trim(($variant?->product?->name ?? '').' '.($variant?->name ?? '')),
                    'system_qty' => $item->system_qty,
                    'adjusted_qty' => $item->physical_qty,
                    'variance_qty' => $item->variance_qty,
                    'unit' => $item->unit ?: $variant?->unit?->name,
                    'type' => $item->variance_qty > 0
                        ? StockAdjustmentType::Tambah->value
                        : ($item->variance_qty < 0 ? StockAdjustmentType::Kurang->value : null),
                    'notes' => $item->notes,
                ];
            })
            ->values()
            ->all();
    }

    private static function sourceValue(mixed $source): ?string
    {
        return $source instanceof StockAdjustmentSource ? $source->value : $source;
    }
}
