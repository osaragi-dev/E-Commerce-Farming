<?php

namespace App\Filament\Resources\Sectors;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Sectors\Pages\ManageSectors;
use App\Filament\Resources\Sectors\Schemas\SectorForm;
use App\Filament\Resources\Sectors\Schemas\SectorInfolist;
use App\Filament\Resources\Sectors\Tables\SectorsTable;
use App\Models\Sector;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SectorResource extends Resource implements HasTableExport
{
    protected static ?string $model = Sector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SectorForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SectorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SectorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSectors::route('/'),
        ];
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Sectors',
            columns: [
                'name' => 'Name',
                'slug' => 'Slug',
                'is_active' => 'Active',
                'sort_order' => 'Sort Order',
                'record_status' => 'Record Status',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['createdBy', 'updatedBy', 'deletedBy'],
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
