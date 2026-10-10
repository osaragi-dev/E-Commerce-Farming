<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource implements HasTableExport
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Settings > Users adalah menu khusus Internal Account (Admin/Staff)
     * yang login ke Admin Panel — BUKAN tempat mengelola akun Customer.
     * Customer dibuat lewat Sign Up di website (App\Livewire\Auth\Register)
     * dan disimpan di tabel `customers`.
     *
     * Filter legacy role tetap dipertahankan sebagai pertahanan tambahan
     * selama deployment bertahap; migration customer menghapus role dan row
     * User customer lama setelah akun dipindahkan ke tabel `customers`.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', 'customer'));
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Users',
            columns: [
                'name' => 'Name',
                'email' => 'Email',
                'roles.name' => 'Role',
                'is_active' => 'Active',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
                'deletedBy.name' => 'Deleted By',
                'deleted_at' => 'Deleted At',
            ],
            relationships: ['roles', 'createdBy', 'updatedBy', 'deletedBy'],
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
