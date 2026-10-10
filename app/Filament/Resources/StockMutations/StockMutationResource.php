<?php

namespace App\Filament\Resources\StockMutations;

use App\Enums\StockMutationSource;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Repacks\RepackResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMutations\Pages\ListStockMutations;
use App\Filament\Resources\StockMutations\Pages\ViewStockMutation;
use App\Filament\Resources\StockMutations\Tables\StockMutationsTable;
use App\Filament\Resources\StockWastes\StockWasteResource;
use App\Models\OrderItem;
use App\Models\Repack;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockMutation;
use App\Models\StockWaste;
use App\Support\Quantity;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bab 22: Read Only — audit trail seluruh pergerakan stok dari semua
 * sumber (Order, Penyesuaian Manual, Stok Awal).
 */
class StockMutationResource extends Resource implements HasTableExport
{
    protected static ?string $model = StockMutation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 99;

    public static function table(Table $table): Table
    {
        return StockMutationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('source')->badge(),
            TextEntry::make('repack.repack_number')->label('Convert Number')->placeholder('-')
                ->url(fn (StockMutation $record): string => static::activityUrl($record)),
            TextEntry::make('warehouse.name')->label('Warehouse')->placeholder('—'),
            TextEntry::make('productVariant.product.name')->label('Product'),
            TextEntry::make('productVariant.name')->label('Variant'),
            TextEntry::make('stock_before')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Before'),
            TextEntry::make('quantity_change')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Change'),
            TextEntry::make('stock_after')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('After'),
            TextEntry::make('recordedBy.name')->label('Recorded By')->placeholder('System'),
            TextEntry::make('created_at')->label('Recorded At')->dateTime(),
        ]);
    }

    public static function activityUrl(StockMutation $mutation): string
    {
        $fallback = static::getUrl('view', ['record' => $mutation]);

        if (! $mutation->reference_id) {
            if ($mutation->source === StockMutationSource::InitialStock || $mutation->source === StockMutationSource::ManualAdjustment) {
                $product = $mutation->productVariant?->product;

                if ($product && ProductResource::canView($product)) {
                    return ProductResource::getUrl('view', ['record' => $product]);
                }
            }

            return $fallback;
        }

        if ($mutation->reference_type === OrderItem::class) {
            $order = $mutation->orderItem?->order;

            return $order && OrderResource::canView($order)
                ? OrderResource::getUrl('view', ['record' => $order])
                : $fallback;
        }

        if ($mutation->reference_type === StockAdjustment::class) {
            $adjustment = $mutation->stockAdjustment;

            return $adjustment && StockAdjustmentResource::canView($adjustment)
                ? StockAdjustmentResource::getUrl('view', ['record' => $adjustment])
                : $fallback;
        }

        if ($mutation->reference_type === StockMovement::class) {
            $movement = $mutation->stockMovement;

            return $movement && StockMovementResource::canView($movement)
                ? StockMovementResource::getUrl('view', ['record' => $movement])
                : $fallback;
        }

        if ($mutation->reference_type === Repack::class && $mutation->repack && RepackResource::canView($mutation->repack)) {
            return RepackResource::getUrl('index', [
                'tableAction' => 'view',
                'tableActionRecord' => $mutation->repack->id,
            ]);
        }

        if ($mutation->reference_type === StockWaste::class) {
            $waste = $mutation->stockWaste;

            return $waste && StockWasteResource::canView($waste)
                ? StockWasteResource::getUrl('view', ['record' => $waste])
                : $fallback;
        }

        return $fallback;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['productVariant.product', 'orderItem.order', 'stockAdjustment', 'stockMovement', 'repack', 'stockWaste'])
            ->where('source', '!=', StockMutationSource::OrderFulfilled);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMutations::route('/'),
            'view' => ViewStockMutation::route('/{record}'),
        ];
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Stock Mutations',
            columns: [
                'created_at' => 'Date',
                'warehouse.name' => 'Warehouse',
                'productVariant.product.name' => 'Product',
                'productVariant.name' => 'Variant',
                'source' => 'Source',
                'repack.repack_number' => 'Convert Number',
                'stock_before' => 'Before',
                'quantity_change' => 'Change',
                'stock_after' => 'After',
                'recordedBy.name' => 'Recorded By',
            ],
            relationships: ['warehouse', 'productVariant.product', 'recordedBy', 'repack'],
        );
    }
}
