<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\StockMovements\Pages\EditStockMovement;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Resources\StockMovements\Schemas\StockMovementInfolist;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
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

class StockMovementResource extends Resource implements HasTableExport
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'movement_number';

    public static function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockMovementInfolist::configure($schema);
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof StockMovement || $record->trashed() || ! parent::canEdit($record)) {
            return false;
        }

        $isEditableState = ($record->status === StockMovementStatus::Draft
            && $record->approval_status === StockMovementApprovalStatus::Pending)
            || $record->approval_status === StockMovementApprovalStatus::Revised;

        if (! $isEditableState) {
            return false;
        }

        if ($record->isEditableBy(auth()->id())) {
            return true;
        }

        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false
            && ($record->status === StockMovementStatus::Draft
                || $record->approval_status?->value === 'revised');
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || ($record instanceof StockMovement
                && auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof StockMovement
            && ! $record->trashed()
            && parent::canDelete($record)
            && $record->status === StockMovementStatus::Draft;
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof StockMovement
            && parent::canRestore($record)
            && $record->trashed();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
            'edit' => EditStockMovement::route('/{record}/edit'),
            'view' => ViewStockMovement::route('/{record}'),
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
            title: 'Stock Movements',
            columns: [
                'approval_status' => 'Approval',
                'movement_number' => 'Movement Number',
                'movement_date' => 'Movement Date',
                'fromWarehouse.name' => 'From',
                'toWarehouse.name' => 'To',
                'requestedBy.name' => 'Requested By',
                'shipped_at' => 'Shipped At',
                'received_at' => 'Received At',
                'status' => 'Status',
                'notes' => 'Notes',
                'created_at' => 'Created At',
                'createdBy.name' => 'Created By',
                'updated_at' => 'Updated At',
                'updatedBy.name' => 'Updated By',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['fromWarehouse', 'toWarehouse', 'requestedBy', 'createdBy', 'updatedBy', 'deletedBy'],
        );
    }
}
