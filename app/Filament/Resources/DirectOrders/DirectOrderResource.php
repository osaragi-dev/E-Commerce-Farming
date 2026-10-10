<?php

namespace App\Filament\Resources\DirectOrders;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\DirectOrders\Pages\CreateDirectOrder;
use App\Filament\Resources\DirectOrders\Pages\EditDirectOrder;
use App\Filament\Resources\DirectOrders\Pages\ManageDirectOrders;
use App\Filament\Resources\DirectOrders\Pages\ViewDirectOrder;
use App\Filament\Resources\DirectOrders\Schemas\DirectOrderForm;
use App\Filament\Resources\DirectOrders\Schemas\DirectOrderInfolist;
use App\Filament\Resources\DirectOrders\Tables\DirectOrdersTable;
use App\Models\DirectOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DirectOrderResource extends Resource implements HasTableExport
{
    protected static ?string $model = DirectOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'do_number';

    public static function form(Schema $schema): Schema
    {
        return DirectOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DirectOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DirectOrdersTable::configure($table);
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof DirectOrder || $record->trashed() || ! parent::canEdit($record)) {
            return false;
        }

        if ($record->record_status !== RecordStatus::Draft
            && $record->approval_status !== PurchaseRequestApprovalStatus::Revised) {
            return false;
        }

        return $record->created_by === auth('web')->id()
            || (auth('web')->user()?->hasAnyRole(['admin', 'super_admin']) ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof DirectOrder
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canDelete($record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof DirectOrder && $record->trashed() && parent::canRestore($record);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Direct Orders',
            columns: [
                'approval_status' => 'Approval',
                'progress' => 'Progress',
                'do_number' => 'DO Number',
                'do_date' => 'DO Date',
                'warehouse.name' => 'Location',
                'vendor.name' => 'Vendor',
                'total_amount' => 'Total Amount',
                'record_status' => 'Status',
                'approval_logs_count' => 'Log History',
                'notes' => 'Notes',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['warehouse', 'vendor', 'createdBy', 'updatedBy', 'deletedBy'],
            monetary: ['total_amount'],
            dateOnly: ['do_date'],
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDirectOrders::route('/'),
            'create' => CreateDirectOrder::route('/create'),
            'edit' => EditDirectOrder::route('/{record}/edit'),
            'view' => ViewDirectOrder::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
