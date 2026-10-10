<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Resources\Vendors\Schemas\VendorInfolist;
use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalRequestInbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VendorResource extends Resource implements HasTableExport
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 6;

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || (auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VendorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Vendors',
            columns: [
                'vendor_code' => 'Vendor Code',
                'name' => 'Name',
                'contact_person' => 'Contact Person',
                'phone' => 'Phone',
                'email' => 'Email',
                'address' => 'Address',
                'tax_id' => 'Tax ID',
                'bank_name' => 'Bank Name',
                'bank_account' => 'Bank Account',
                'bank_account_name' => 'Bank Account Name',
                'lead_time_days' => 'Lead Time (Days)',
                'payment_terms' => 'Payment Terms',
                'is_active' => 'Active',
                'record_status' => 'Record Status',
                'notes' => 'Notes',
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

    public static function getPages(): array
    {
        return [
            'index' => ManageVendors::route('/'),
            'view' => ViewVendor::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
