<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ManageGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Schemas\GoodsReceiptForm;
use App\Filament\Resources\GoodsReceipts\Schemas\GoodsReceiptInfolist;
use App\Filament\Resources\GoodsReceipts\Tables\GoodsReceiptsTable;
use App\Models\GoodsReceipt;
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

class GoodsReceiptResource extends Resource implements HasTableExport
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'receipt_number';

    public static function form(Schema $schema): Schema
    {
        return GoodsReceiptForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GoodsReceiptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceiptsTable::configure($table);
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || (auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof GoodsReceipt
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canDelete($record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof GoodsReceipt
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canEdit($record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof GoodsReceipt && $record->trashed() && parent::canRestore($record);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Goods Receipts',
            columns: [
                'approval_status' => 'Approval',
                'receipt_number' => 'GR Number',
                'received_at' => 'Received Date',
                'purchaseOrder.po_number' => 'PO Number',
                'purchaseOrder.warehouse.name' => 'Location',
                'purchaseOrder.vendor.name' => 'Vendor',
                'delivery_note_number' => 'Delivery Note',
                'checkedBy.name' => 'Checked By',
                'total_accepted' => 'Total Accepted',
                'total_rejected' => 'Total Rejected',
                'record_status' => 'Status',
                'notes' => 'Notes',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],
            relationships: ['purchaseOrder.warehouse', 'purchaseOrder.vendor', 'checkedBy', 'createdBy', 'updatedBy'],
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'edit' => EditGoodsReceipt::route('/{record}/edit'),
            'view' => ViewGoodsReceipt::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
