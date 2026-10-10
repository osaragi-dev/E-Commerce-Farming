<?php

namespace App\Filament\Resources\StockAdjustments;

use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\Schemas\StockAdjustmentForm;
use App\Filament\Resources\StockAdjustments\Schemas\StockAdjustmentInfolist;
use App\Filament\Resources\StockAdjustments\Tables\StockAdjustmentsTable;
use App\Models\StockAdjustment;
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

class StockAdjustmentResource extends Resource implements HasTableExport
{
    protected static ?string $model = StockAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $recordTitleAttribute = 'label';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return StockAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockAdjustmentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockAdjustmentInfolist::configure($schema);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof StockAdjustment
            && parent::canEdit($record)
            && $record->isEditableBy(auth()->id());
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || ($record instanceof StockAdjustment
                && auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof StockAdjustment
            && ! $record->trashed()
            && parent::canDelete($record)
            && $record->record_status === RecordStatus::Draft;
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof StockAdjustment
            && parent::canRestore($record)
            && $record->trashed()
            && $record->previous_record_status === RecordStatus::Draft;
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateStockAdjustment::route('/create'),
            'index' => ListStockAdjustments::route('/'),
            'view' => ViewStockAdjustment::route('/{record}'),
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
            title: 'Stock Adjustments',
            columns: [
                'adjustment_number' => 'Adjustment Number',
                'source' => 'Source',
                'warehouse.name' => 'Warehouse',
                'product_summary' => 'Items',
                'type_summary' => 'Type',
                'quantity_summary' => 'Quantity',
                'record_status' => 'Status',
                'approval_status' => 'Approval Status',
                'approval_level' => 'Approval Level',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],
            relationships: [
                'warehouse',
                'productVariant.product',
                'stockOpnameItems.productVariant.product',
                'createdBy',
                'updatedBy',
            ],
        );
    }
}
