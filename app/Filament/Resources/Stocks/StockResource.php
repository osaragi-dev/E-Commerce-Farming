<?php

namespace App\Filament\Resources\Stocks;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Stocks\Pages\ListItemStocks;
use App\Filament\Resources\Stocks\Pages\ListLowStocks;
use App\Filament\Resources\Stocks\Pages\ListStocks;
use App\Filament\Resources\Stocks\Pages\StockCategories;
use App\Filament\Resources\Stocks\Pages\StockWarehouses;
use App\Filament\Resources\Stocks\Schemas\StockForm;
use App\Filament\Resources\Stocks\Tables\StocksTable;
use App\Models\WarehouseStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockResource extends Resource implements HasTableExport
{
    public const array WAREHOUSE_CODES = ['WH-FARMING', 'WH-SCCR'];

    protected static ?string $model = WarehouseStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Stocks';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $modelLabel = 'Stock';

    protected static ?string $pluralModelLabel = 'Stocks';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 0;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return StockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StocksTable::configure($table);
    }

    /**
     * TCS revision (29/8): kalau produk induknya non-aktif, variant-nya
     * (meski masih aktif) tetap tidak boleh muncul di daftar Stocks.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['warehouse', 'productVariant.product', 'productVariant.unit'])
            ->whereHas(
                'productVariant',
                fn (Builder $variantQuery): Builder => $variantQuery->inventoryVisible(),
            );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('ViewAny:Stock') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('Update:Stock') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => StockCategories::route('/'),
            'items' => ListItemStocks::route('/items'),
            'products' => ListStocks::route('/products'),
            'low-stock' => ListLowStocks::route('/low-stock'),
            'status' => ListStocks::route('/status/{status}'),
            'warehouses' => StockWarehouses::route('/warehouses'),
            'warehouse' => ListStocks::route('/warehouse/{warehouse}'),
        ];
    }

    public static function warehouseExportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Stock Warehouses',
            columns: [
                'name' => 'Warehouse',
                'code' => 'Warehouse Code',
                'total_items_count' => 'Total Items',
                'critical_items_count' => 'Critical Items',
            ],
        );
    }

    public static function itemExportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Item Stocks',
            columns: [
                'warehouse.name' => 'Warehouse',
                'item.name' => 'Item',
                'item.unit.code' => 'Unit',
                'stock_available' => 'Available',
                'stock_reserved' => 'Reserved',
                'stock_in_transit' => 'In Transit',
                'stock_out' => 'Out',
                'item.min_stock' => 'Min. Stock',
                'stock_status' => 'Status',
            ],
            relationships: ['warehouse', 'item.unit'],
        );
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Stocks',
            columns: [
                'warehouse.name' => 'Warehouse',
                'warehouse.code' => 'Warehouse Code',
                'productVariant.product.name' => 'Product',
                'productVariant.name' => 'Variant',
                'productVariant.unit.name' => 'Unit',
                'productVariant.initial_stock' => 'Initial Stock',
                'stock_available' => 'Available',
                'stock_reserved' => 'Reserved',
                'stock_in_transit' => 'In Transit',
                'stock_out' => 'Out',
                'productVariant.low_stock_threshold' => 'Min. Stock',
                'stockStatus' => 'Status',
            ],
            relationships: ['warehouse', 'productVariant.product', 'productVariant.unit'],
        );
    }
}
