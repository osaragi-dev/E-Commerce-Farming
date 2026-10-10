<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Warehouses\Pages\ManageWarehouses;
use App\Filament\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Resources\Warehouses\Schemas\WarehouseInfolist;
use App\Filament\Resources\Warehouses\Tables\WarehousesTable;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WarehouseResource extends Resource implements HasTableExport
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WarehouseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Warehouses',
            columns: [
                'code' => 'Code',
                'name' => 'Name',
                'description' => 'Description',
                'is_active' => 'Active',
                'is_pos' => 'Is POS',
                'record_status' => 'Record Status',
                'created_at' => 'Created At',
                'createdBy.name' => 'Created By',
                'updated_at' => 'Updated At',
                'updatedBy.name' => 'Updated By',
                'deleted_at' => 'Deleted At',
                'deletedBy.name' => 'Deleted By',
            ],
            relationships: ['createdBy', 'updatedBy', 'deletedBy'],
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManageWarehouses::route('/')];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
