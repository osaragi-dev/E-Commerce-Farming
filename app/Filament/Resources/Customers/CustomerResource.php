<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * TCS revision (24-25 Aug 2026): dulu "Customer" cuma diturunkan dari
 * User tanpa Role (Read Only, cuma bisa lewat registrasi Website).
 * Sekarang ada model Customer terpisah (dari modul Order Adam), dengan
 * Lifecycle standar (Draft/Saved, Clone, Export) sama kayak
 * Sectors/Categories/Products — dikelola langsung lewat App\Models\Customer.
 */
class CustomerResource extends Resource implements HasTableExport
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 7;

    // Customer sekarang model sendiri (bukan lagi berbagi class User
    // dengan resource Users Bab 21), jadi tidak ada lagi risiko tabrakan
    // nama permission — aman pakai auto-discovery Shield yang standar.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('ViewAny:Customer') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('View:Customer') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('Create:Customer') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('Update:Customer') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('Delete:Customer') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('Restore:Customer') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Customers',
            columns: [
                'name' => 'Name',
                'customer_type' => 'Customer Type',
                'email' => 'Email',
                'phone' => 'Phone',
                'address' => 'Address',
                'is_active' => 'Active',
                'record_status' => 'Record Status',
                'email_verified_at' => 'Email Verified At',
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
}
