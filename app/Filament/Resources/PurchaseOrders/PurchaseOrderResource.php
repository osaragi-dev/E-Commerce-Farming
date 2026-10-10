<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
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

class PurchaseOrderResource extends Resource implements HasTableExport
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function canView(Model $record): bool
    {
        if (parent::canView($record)) {
            return true;
        }

        $user = auth('web')->user();

        if (! $user instanceof User || ! $record instanceof PurchaseOrder) {
            return false;
        }

        $record->loadMissing('closeRequests');

        return app(ApprovalRequestInbox::class)->canReviewDetail($user, $record)
            || ($record->latest_close_request instanceof PurchaseOrderCloseRequest
                && app(ApprovalRequestInbox::class)->canReviewDetail($user, $record->latest_close_request));
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof PurchaseOrder
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof PurchaseOrder
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canDelete($record);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Purchase Orders',
            columns: [
                'approval_status' => 'Approval',
                'po_number' => 'PO Number',
                'po_date' => 'PO Date',
                'purchaseRequest.pr_number' => 'PR Number',
                'warehouse.name' => 'Location',
                'vendor.name' => 'Vendor',
                'total_amount' => 'Total Amount',
                'received_status' => 'Received',
                'closing_status' => 'Closing',
                'payment_status' => 'Payment',
                'payment_total' => 'Payment Total',
                'latest_payment_date' => 'Payment Date',
                'record_status' => 'Status',
                'expected_delivery_date' => 'Expected Delivery',
                'notes' => 'Notes',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],
            relationships: ['purchaseRequest', 'warehouse', 'vendor', 'createdBy', 'updatedBy'],
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
