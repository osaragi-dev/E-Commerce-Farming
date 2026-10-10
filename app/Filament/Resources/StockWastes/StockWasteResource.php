<?php

namespace App\Filament\Resources\StockWastes;

use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\StockWastes\Pages\ListStockWastes;
use App\Filament\Resources\StockWastes\Pages\ViewStockWaste;
use App\Filament\Resources\StockWastes\Schemas\StockWasteForm;
use App\Filament\Resources\StockWastes\Schemas\StockWasteInfolist;
use App\Filament\Resources\StockWastes\Tables\StockWastesTable;
use App\Models\StockWaste;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StockWasteResource extends Resource implements HasTableExport
{
    protected static ?string $model = StockWaste::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static ?string $recordTitleAttribute = 'waste_number';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return 'Waste Records';
    }

    public static function getModelLabel(): string
    {
        return 'Waste Record';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Waste Records';
    }

    public static function form(Schema $schema): Schema
    {
        return StockWasteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockWastesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'createdBy',
            'deletedBy',
            'productVariant.product',
            'productVariant.unit',
            'updatedBy',
            'warehouse',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockWasteInfolist::configure($schema);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof StockWaste
            && parent::canDelete($record)
            && $record->record_status === RecordStatus::Draft
            && $record->posted_at === null;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof StockWaste
            && parent::canEdit($record)
            && $record->record_status === RecordStatus::Draft
            && $record->posted_at === null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockWastes::route('/'),
            'view' => ViewStockWaste::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Waste Records',
            columns: [
                'waste_number' => 'Waste Number',
                'source' => 'Source',
                'warehouse.name' => 'Location',
                'waste_date' => 'Date',
                'productVariant.product.name' => 'Product',
                'productVariant.name' => 'Item',
                'quantity' => 'Quantity',
                'productVariant.unit.name' => 'Unit',
                'waste_type' => 'Waste Type',
                'waste_status' => 'Status',
                'reason' => 'Reason',
                'picUser.name' => 'PIC User',
                'notes' => 'Notes',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],
            relationships: ['warehouse', 'productVariant.product', 'productVariant.unit', 'picUser', 'createdBy', 'updatedBy'],
        );
    }
}
