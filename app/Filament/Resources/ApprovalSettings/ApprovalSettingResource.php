<?php

namespace App\Filament\Resources\ApprovalSettings;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\ApprovalSettings\Pages\ManageApprovalSettings;
use App\Filament\Resources\ApprovalSettings\Schemas\ApprovalSettingForm;
use App\Filament\Resources\ApprovalSettings\Tables\ApprovalSettingsTable;
use App\Models\ApprovalSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ApprovalSettingResource extends Resource implements HasTableExport
{
    protected static ?string $model = ApprovalSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Setting Approval';

    protected static ?string $modelLabel = 'Approval Setting';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ApprovalSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageApprovalSettings::route('/')];
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Approval Settings',
            columns: [
                'module_code' => 'Module',
                'levels_count' => 'Approval Levels',
                'is_active' => 'Active',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ],
        );
    }
}
