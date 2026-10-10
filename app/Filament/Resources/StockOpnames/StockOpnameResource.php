<?php

namespace App\Filament\Resources\StockOpnames;

use App\Enums\StockOpnameStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\Pages\ViewStockOpname;
use App\Filament\Resources\StockOpnames\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StockOpnames\Schemas\StockOpnameForm;
use App\Filament\Resources\StockOpnames\Schemas\StockOpnameInfolist;
use App\Filament\Resources\StockOpnames\Tables\StockOpnamesTable;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Services\ApprovalRequestInbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StockOpnameResource extends Resource implements HasTableExport
{
    protected static ?string $model = StockOpnameSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock Opnames';

    protected static ?string $modelLabel = 'Stock Opname';

    protected static ?string $pluralModelLabel = 'Stock Opnames';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'opname_number';

    public static function form(Schema $schema): Schema
    {
        return StockOpnameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockOpnamesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockOpnameInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof StockOpnameSession
            && ! $record->trashed()
            && parent::canEdit($record)
            && $record->isEditableBy(auth()->id());
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || ($record instanceof StockOpnameSession
                && auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof StockOpnameSession
            && ! $record->trashed()
            && parent::canDelete($record)
            && $record->status === StockOpnameStatus::Draft;
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof StockOpnameSession
            && parent::canRestore($record)
            && $record->trashed();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
            'create' => CreateStockOpname::route('/create'),
            'edit' => EditStockOpname::route('/{record}/edit'),
            'view' => ViewStockOpname::route('/{record}'),
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
            title: 'Stock Opnames',
            columns: [
                'approval_status' => 'Approval Status',
                'opname_number' => 'Opname Number',
                'period' => 'Period',
                'opname_date' => 'Opname Date',
                'warehouse.name' => 'Warehouse',
                'checker.name' => 'Checker',
                'witness.name' => 'Witness',
                'items_count' => 'Items',
                'process_status' => 'Process Status',
                'status' => 'Status',
                'notes' => 'Notes',
                'created_at' => 'Created At',
                'createdBy.name' => 'Created By',
                'updated_at' => 'Updated At',
                'updatedBy.name' => 'Updated By',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['warehouse', 'checker', 'witness', 'createdBy', 'updatedBy', 'deletedBy'],
        );
    }
}
