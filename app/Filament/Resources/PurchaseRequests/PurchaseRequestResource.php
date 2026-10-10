<?php

namespace App\Filament\Resources\PurchaseRequests;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ManagePurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestForm;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestInfolist;
use App\Filament\Resources\PurchaseRequests\Tables\PurchaseRequestsTable;
use App\Models\PurchaseRequest;
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

class PurchaseRequestResource extends Resource implements HasTableExport
{
    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'pr_number';

    public static function form(Schema $schema): Schema
    {
        return PurchaseRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseRequestsTable::configure($table);
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || (auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof PurchaseRequest || $record->trashed() || ! parent::canEdit($record)) {
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
        return $record instanceof PurchaseRequest
            && ! $record->trashed()
            && $record->record_status === RecordStatus::Draft
            && parent::canDelete($record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof PurchaseRequest && $record->trashed() && parent::canRestore($record);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Purchase Requests',
            columns: [
                'approval_status' => 'Approval Progress',
                'pr_number' => 'PR Number',
                'pr_date' => 'PR Date',
                'warehouse.name' => 'Location',
                'requestedBy.name' => 'Requested By',
                'required_date' => 'Required Date',
                'record_status' => 'Status',
                'notes' => 'Notes',
                'created_at' => 'Created At',
                'createdBy.name' => 'Created By',
                'updated_at' => 'Updated At',
                'updatedBy.name' => 'Updated By',
                'deleted_at' => 'Deleted At',
                'deletedBy.name' => 'Deleted By',
            ],
            relationships: ['warehouse', 'requestedBy', 'createdBy', 'updatedBy', 'deletedBy'],
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseRequests::route('/'),
            'create' => CreatePurchaseRequest::route('/create'),
            'view' => ViewPurchaseRequest::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
