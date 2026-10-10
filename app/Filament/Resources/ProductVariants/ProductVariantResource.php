<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\ProductVariants\Schemas\ProductVariantForm;
use App\Filament\Resources\ProductVariants\Schemas\ProductVariantInfolist;
use App\Filament\Resources\ProductVariants\Tables\ProductVariantsTable;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductVariantResource extends Resource implements HasTableExport
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Variants';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    /**
     * TCS revision (15 Aug 2026): "varian bukan menu baru tapi gabung
     * dengan produk" — Variant dikelola lewat Repeater di form Product,
     * bukan menu tersendiri. Resource class dipertahankan (dipakai oleh
     * Repeater's relationship() + tetap bisa diakses lewat route kalau
     * dibutuhkan), tapi tidak tampil di sidebar.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductVariantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductVariantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductVariantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductVariants::route('/'),
        ];
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Product Variants',
            columns: [
                'product.name' => 'Product',
                'name' => 'Variant',
                'unit.name' => 'Unit',
                'price' => 'Price',
                'price_quantity' => 'Price Quantity',
                'stock_available' => 'Available',
                'stock_reserved' => 'Reserved',
                'stock_in_transit' => 'In Transit',
                'stock_out' => 'Out',
                'low_stock_threshold' => 'Min. Stock',
                'is_active' => 'Active',
                'record_status' => 'Record Status',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['unit', 'createdBy', 'updatedBy', 'deletedBy'],
            monetary: ['price'],
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
