<?php

namespace App\Filament\Resources\Repacks;

use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Repacks\Pages\ManageRepacks;
use App\Filament\Resources\Repacks\Schemas\RepackForm;
use App\Filament\Resources\Repacks\Schemas\RepackInfolist;
use App\Filament\Resources\Repacks\Tables\RepacksTable;
use App\Models\Repack;
use App\Models\SystemFreezeState;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RepackResource extends Resource implements HasTableExport
{
    protected static ?string $model = Repack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'repack_number';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'convert';

    protected static ?string $navigationLabel = 'Convert';

    protected static ?string $modelLabel = 'Convert';

    protected static ?string $pluralModelLabel = 'Convert';

    public static function form(Schema $schema): Schema
    {
        return RepackForm::configure($schema);
    }

    public static function canCreate(): bool
    {
        return ! SystemFreezeState::current()->is_frozen && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->trashed() && $record->record_status === RecordStatus::Draft
            && $record->applied_at === null && ! SystemFreezeState::current()->is_frozen
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->trashed() && $record->record_status === RecordStatus::Draft
            && $record->applied_at === null && ! SystemFreezeState::current()->is_frozen
            && parent::canDelete($record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record->trashed() && $record->previous_record_status === RecordStatus::Draft
            && $record->applied_at === null && ! SystemFreezeState::current()->is_frozen
            && parent::canRestore($record);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RepackInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepacksTable::configure($table);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Convert',
            columns: [
                'repack_number' => 'Convert Number',
                'warehouse.name' => 'Location',
                'fromProductVariant.inventory_label' => 'From Product',
                'from_quantity' => 'From Qty',
                'from_unit' => 'From Unit',
                'toProductVariant.inventory_label' => 'To Product',
                'to_quantity' => 'To Qty',
                'to_unit' => 'To Unit',
                'display_status' => 'Status',
                'notes' => 'Notes',
                'created_at' => 'Created At',
                'createdBy.name' => 'Created By',
                'updated_at' => 'Updated At',
                'updatedBy.name' => 'Updated By',
                'deleted_at' => 'Deleted At',
                'deletedBy.name' => 'Deleted By',
                'cancelled_at' => 'Cancelled At',
                'cancelledBy.name' => 'Cancelled By',
            ],
            relationships: ['warehouse', 'fromProductVariant.product', 'toProductVariant.product', 'createdBy', 'updatedBy', 'deletedBy', 'cancelledBy'],
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManageRepacks::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['warehouse', 'fromProductVariant.product', 'toProductVariant.product', 'createdBy', 'updatedBy', 'deletedBy', 'cancelledBy', 'stockMutations.productVariant.product']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
